<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/session_manager.php';
require_once __DIR__ . '/device_manager.php';
require_once __DIR__ . '/parsers/InformMessageParser.php';
require_once __DIR__ . '/parsers/HuaweiInformMessageParser.php';
require_once __DIR__ . '/responses/InformResponseGenerator.php';
require_once __DIR__ . '/auth/AuthenticationHandler.php';

class TR069Server {
    private $db;
    private $sessionManager;
    private $deviceManager;
    private $informParser;
    private $huaweiInformParser;
    private $responseGenerator;
    private $authHandler;
    private $soapResponse;
    private $deviceId;
    private $sessionId;
    private $isHuaweiDevice = false;
    private $shouldSendGetParameterValues = false;
    private $serialNumber = null;
    private $useParameterDiscovery = false;
    private $discoveryMode = false;
    private $parameterDiscoveryStage = 'none'; // none, wlanconfig, parameters
    private $explorationPhase = 1; // Track what phase of parameter exploration we're in
    private $explorationAttempts = 0; // Count of attempts in current exploration phase

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->sessionManager = new SessionManager($this->db);
        $this->deviceManager = new DeviceManager($this->db);
        $this->informParser = new InformMessageParser();
        $this->huaweiInformParser = new HuaweiInformMessageParser();
        $this->responseGenerator = new InformResponseGenerator();
        $this->authHandler = new AuthenticationHandler();
        
        // Check if this is a Huawei device (basic check, will be updated by setHuaweiDetection)
        if (isset($_SERVER['HTTP_USER_AGENT']) && (
            stripos($_SERVER['HTTP_USER_AGENT'], 'huawei') !== false || 
            stripos($_SERVER['HTTP_USER_AGENT'], 'hw_') !== false ||
            stripos($_SERVER['HTTP_USER_AGENT'], 'hg8') !== false)) {
            $this->isHuaweiDevice = true;
            error_log("TR069Server: Detected Huawei device from User-Agent");
        }
        
        // Check for session ID in URL for continued sessions
        if (isset($_GET['session_id'])) {
            $this->sessionId = $_GET['session_id'];
            error_log("TR069Server: Found session ID in URL: " . $this->sessionId);
            
            // If valid session, get serial number
            $session = $this->sessionManager->validateSession($this->sessionId);
            if ($session) {
                $this->serialNumber = $session['device_serial'];
                error_log("TR069Server: Valid session with serial number: " . $this->serialNumber);
            } else {
                error_log("TR069Server: Invalid session ID in URL");
                $this->sessionId = null;
            }
        }
    }
    
    // Allow setting the Huawei detection flag from outside
    public function setHuaweiDetection($isHuawei) {
        $this->isHuaweiDevice = $isHuawei;
        if ($isHuawei) {
            error_log("TR069Server: External Huawei device detection confirmed");
        }
    }

    // New method to control parameter discovery
    public function setUseParameterDiscovery($useDiscovery) {
        $this->useParameterDiscovery = $useDiscovery;
        error_log("TR069Server: Parameter discovery " . ($useDiscovery ? "enabled" : "disabled"));
    }
    
    public function handleRequest() {
        error_log("TR069Server: Beginning request handling");
        error_log("TR-069 Request received: " . date('Y-m-d H:i:s'));
        
        if (!$this->authHandler->authenticate()) {
            error_log("TR069Server: Authentication failed");
            header('WWW-Authenticate: Basic realm="TR-069 ACS"');
            header('HTTP/1.1 401 Unauthorized');
            exit('Authentication required');
        }

        $rawPost = file_get_contents('php://input');
        
        // Log raw POST length, truncate if too long for basic logging
        if (!empty($rawPost)) {
            $logLength = min(strlen($rawPost), 200); // Only log first 200 chars in standard log
            error_log("Raw POST data (first {$logLength} chars): " . substr($rawPost, 0, $logLength) . (strlen($rawPost) > $logLength ? "..." : ""));
        } else {
            error_log("TR069Server: Empty POST received");
            // Write to get.log when we receive an empty POST
            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Empty POST received in TR069Server from " . $_SERVER['REMOTE_ADDR'] . "\n", FILE_APPEND);
        }
        
        // If this is an empty POST with a Huawei device, always send GetParameterValues
        if ($this->isHuaweiDevice && empty($rawPost)) {
            error_log("TR069Server: Empty POST from Huawei device detected - sending GetParameterValues");
            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Huawei empty POST detected, will send GetParameterValues\n", FILE_APPEND);
            
            // Generate a session ID if we don't have one already
            if (empty($this->sessionId)) {
                $this->sessionId = bin2hex(random_bytes(16));
                error_log("TR069Server: Generated new session ID for empty POST: " . $this->sessionId);
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Generated new session ID: " . $this->sessionId . "\n", FILE_APPEND);
                
                // If we have a serial number from a previous inform, save the session
                if (!empty($this->serialNumber)) {
                    $this->sessionManager->createSession($this->serialNumber, $this->sessionId);
                    error_log("TR069Server: Created session with existing serial number: " . $this->serialNumber);
                } else {
                    error_log("TR069Server: No serial number available for session creation");
                }
            }
            
            // Always send the GetParameterValues for Huawei devices on empty POST
            $this->soapResponse = $this->responseGenerator->createHuaweiGetParameterValuesRequest($this->sessionId);
            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Sending Huawei GetParameterValues with session ID: " . $this->sessionId . "\n", FILE_APPEND);
            $this->sendResponse();
            return;
        }

        // Handle non-empty POST data - this is either an Inform or a GetParameterValuesResponse
        if (!empty($rawPost)) {
            try {
                libxml_use_internal_errors(true);
                $xml = new SimpleXMLElement($rawPost);
                $this->processRequest($xml, $rawPost);
                $this->sendResponse();
            } catch (Exception $e) {
                error_log("TR069Server Error: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                
                // For Huawei devices, log more details about the XML parsing error
                if ($this->isHuaweiDevice) {
                    error_log("Huawei XML parsing errors:");
                    foreach (libxml_get_errors() as $error) {
                        error_log("Line {$error->line}, Column {$error->column}: {$error->message}");
                    }
                    libxml_clear_errors();
                }
                
                // Log the raw XML for debugging
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " ERROR parsing XML: " . $e->getMessage() . "\n", FILE_APPEND);
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Raw XML that caused error: " . $rawPost . "\n", FILE_APPEND);
                
                header('HTTP/1.1 500 Internal Server Error');
                echo "Internal Server Error: " . $e->getMessage();
                exit;
            }
        } else {
            $this->handleEmptyRequest();
        }
    }

    // When processing SOAP Fault, check for specific fault codes
    private function processRequest($xml, $rawXml = '') {
        try {
            // Log the full raw XML for debugging GetParameterValuesResponse issues
            if ($this->isHuaweiDevice) {
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Processing XML: " . $rawXml . "\n", FILE_APPEND);
            }
            
            $namespace = $xml->getNamespaces(true);
            $soapEnv = isset($namespace['SOAP-ENV']) ? $namespace['SOAP-ENV'] : 'http://schemas.xmlsoap.org/soap/envelope/';
            $cwmp = isset($namespace['cwmp']) ? $namespace['cwmp'] : 'urn:dslforum-org:cwmp-1-0';

            error_log("TR069Server: Detected namespaces - SOAP: {$soapEnv}, CWMP: {$cwmp}");

            // Extract SOAP Header ID if present for session tracking
            $header = $xml->children($soapEnv)->Header;
            if ($header) {
                $soapId = $header->children($cwmp)->ID;
                if ($soapId) {
                    error_log("TR069Server: Found SOAP ID in header: " . (string)$soapId);
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Found SOAP ID in header: " . (string)$soapId . "\n", FILE_APPEND);
                    // Use this ID for session correlation
                    $this->sessionId = (string)$soapId;
                }
            }

            $body = $xml->children($soapEnv)->Body;
            if (empty($body)) {
                error_log("TR069Server: Empty SOAP Body received");
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Empty SOAP Body received\n", FILE_APPEND);
                throw new Exception("Empty SOAP Body");
            }

            // Check for SOAP Fault
            $fault = $body->children($soapEnv)->Fault;
            if (!empty($fault)) {
                $faultCode = (string)$fault->faultcode;
                $faultString = (string)$fault->faultstring;
                
                // Look for cwmp:Fault in detail
                $detail = $fault->detail;
                if ($detail) {
                    $cwmpFault = $detail->children($cwmp)->Fault;
                    if ($cwmpFault) {
                        $cwmpFaultCode = (string)$cwmpFault->FaultCode;
                        $cwmpFaultString = (string)$cwmpFault->FaultString;
                        
                        error_log("TR069Server: CWMP Fault received - Code: {$cwmpFaultCode}, String: {$cwmpFaultString}");
                        file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " CWMP Fault - Code: {$cwmpFaultCode}, String: {$cwmpFaultString}\n", FILE_APPEND);
                        
                        // Record the fault in our session manager
                        $this->sessionManager->recordFault($this->sessionId, $cwmpFaultCode, $cwmpFaultString);
                        
                        // If this is an invalid parameter fault (9005), we should initiate deeper parameter exploration
                        if ($cwmpFaultCode == '9005' && $this->isHuaweiDevice && $this->useParameterDiscovery) {
                            error_log("TR069Server: Invalid parameter fault (9005) received - Starting parameter exploration phase {$this->explorationPhase}");
                            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Invalid parameter (9005) - Starting exploration phase {$this->explorationPhase}\n", FILE_APPEND);
                            
                            // Set discovery mode and prepare discovery request
                            $this->discoveryMode = true;
                            
                            // Increment attempts for current phase
                            $this->explorationAttempts++;
                            
                            // If we've tried this phase too many times, move to next phase
                            if ($this->explorationAttempts >= 2) {
                                $this->explorationPhase++;
                                $this->explorationAttempts = 0;
                                error_log("TR069Server: Moving to exploration phase {$this->explorationPhase}");
                                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Moving to exploration phase {$this->explorationPhase}\n", FILE_APPEND);
                            }
                            
                            // If we've gone through all phases, try the comprehensive security parameters request
                            if ($this->explorationPhase > 6) {
                                error_log("TR069Server: Trying comprehensive WiFi security parameters after exhausting exploration phases");
                                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Trying comprehensive WiFi security parameters\n", FILE_APPEND);
                                $this->soapResponse = $this->responseGenerator->createWifiSecurityParametersRequest($this->sessionId);
                            } else {
                                // Generate parameter exploration request for current phase
                                $this->soapResponse = $this->responseGenerator->createExploreParametersRequest(
                                    $this->sessionId, 
                                    $this->explorationPhase
                                );
                            }
                            return;
                        }
                        
                        // For other fault responses, we don't send a reply
                        $this->soapResponse = null;
                        return;
                    }
                }
                
                error_log("TR069Server: SOAP Fault received - Code: {$faultCode}, String: {$faultString}");
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " SOAP Fault - Code: {$faultCode}, String: {$faultString}\n", FILE_APPEND);
                
                // For fault responses, we don't send a reply
                $this->soapResponse = null;
                return;
            }

            // Get all namespaced children - for detecting different request types
            $foundRequestType = false;
            $request = null;
            $requestName = '';
            
            // Try to find cwmp:Inform
            $request = $body->children($cwmp);
            if (count($request) > 0) {
                $requestName = $request->getName();
                if (!empty($requestName)) {
                    $foundRequestType = true;
                    error_log("TR069Server: Found request type in cwmp namespace: " . $requestName);
                }
            }
            
            // If not found, try to look for GetParameterValuesResponse/GetParameterNamesResponse differently
            if (!$foundRequestType && $this->isHuaweiDevice) {
                // Try direct detection via string search in body XML
                $bodyXml = $body->asXML();
                if (strpos($bodyXml, 'GetParameterValuesResponse') !== false) {
                    $requestName = 'GetParameterValuesResponse';
                    $foundRequestType = true;
                    error_log("TR069Server: Detected GetParameterValuesResponse in body via string search");
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Detected GetParameterValuesResponse via string search\n", FILE_APPEND);
                } else if (strpos($bodyXml, 'GetParameterNamesResponse') !== false) {
                    $requestName = 'GetParameterNamesResponse';
                    $foundRequestType = true;
                    error_log("TR069Server: Detected GetParameterNamesResponse in body via string search");
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Detected GetParameterNamesResponse via string search\n", FILE_APPEND);
                }
                
                // Try all potential namespaces for response types
                if (!$foundRequestType) {
                    foreach ($namespace as $prefix => $uri) {
                        $testRequest = $body->children($uri);
                        if (count($testRequest) > 0) {
                            $testName = $testRequest->getName();
                            if ($testName == 'GetParameterValuesResponse' || $testName == 'GetParameterNamesResponse') {
                                $request = $testRequest;
                                $requestName = $testName;
                                $foundRequestType = true;
                                error_log("TR069Server: Found {$requestName} in namespace: " . $uri);
                                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Found {$requestName} in namespace: " . $uri . "\n", FILE_APPEND);
                                break;
                            }
                        }
                    }
                }
            }

            if (!$foundRequestType) {
                // Log the fact that we couldn't find a proper request type
                error_log("TR069Server: No valid request type found in SOAP body");
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " No valid request type found in SOAP body\n", FILE_APPEND);
                
                // For Huawei devices with a session ID, if we detect this is probably a GetParameterValuesResponse, try to parse it as such
                if ($this->isHuaweiDevice && !empty($this->sessionId)) {
                    // Check if the body contains ParameterList or Parameters string
                    $bodyXml = $body->asXML();
                    if (strpos($bodyXml, 'ParameterList') !== false || strpos($bodyXml, 'Parameters') !== false) {
                        error_log("TR069Server: Detected likely GetParameterValuesResponse based on content");
                        file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Detected likely GetParameterValuesResponse based on content\n", FILE_APPEND);
                        $this->handleGetParameterValuesResponse($body, $rawXml);
                        return;
                    }
                }
                
                // If we couldn't determine the request type but we have a session, try to continue
                if (!empty($this->sessionId)) {
                    error_log("TR069Server: Unknown request type, but session exists. Continuing with empty response.");
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Unknown request type, but session exists. Continuing with empty response.\n", FILE_APPEND);
                    // Don't throw an exception, just return empty response
                    $this->soapResponse = null;
                    return;
                }
                
                throw new Exception("Unknown request type in SOAP body");
            }

            error_log("TR069Server: Processing request type: " . $requestName);
            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Processing request: " . $requestName . "\n", FILE_APPEND);

            switch ($requestName) {
                case 'Inform':
                    $this->handleInform($request, $rawXml);
                    break;
                case 'GetParameterValuesResponse':
                    $this->handleGetParameterValuesResponse($request, $rawXml);
                    break;
                case 'GetParameterNamesResponse':
                    $this->handleGetParameterNamesResponse($request, $rawXml);
                    break;
                default:
                    error_log("TR069Server: Unknown request type: " . $requestName);
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Unknown request type: " . $requestName . "\n", FILE_APPEND);
                    // For Huawei devices with a session, don't throw exception on unknown request types
                    if ($this->isHuaweiDevice && !empty($this->sessionId)) {
                        error_log("TR069Server: Allowing unknown request type for Huawei device with session");
                        $this->soapResponse = null;
                        return;
                    }
                    throw new Exception("Unknown request type: $requestName");
            }
        } catch (Exception $e) {
            error_log("TR069Server: Error in processRequest: " . $e->getMessage());
            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Error in processRequest: " . $e->getMessage() . "\n", FILE_APPEND);
            throw $e;
        }
    }

    // Add a new handler for GetParameterNamesResponse
    private function handleGetParameterNamesResponse($request, $rawXml = '') {
        try {
            error_log("TR069Server: Processing GetParameterNamesResponse");
            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " GetParameterNamesResponse received\n", FILE_APPEND);
            
            // Log the exploration phase we're in
            if ($this->discoveryMode) {
                error_log("TR069Server: In exploration phase: " . $this->explorationPhase);
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " In exploration phase: " . $this->explorationPhase . "\n", FILE_APPEND);
            } else {
                // Log the discovery stage we're in
                error_log("TR069Server: Parameter discovery stage: " . $this->parameterDiscoveryStage);
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Parameter discovery stage: " . $this->parameterDiscoveryStage . "\n", FILE_APPEND);
            }
            
            // Extract parameter names from the response
            $parameterList = [];
            $detectedWifiInterfaces = [];
            $detectedSecurityParams = [];
            $detectedWlanObjects = [];
            $detectedXHWParams = [];
            
            // Create a new XML parser just for the parameter extraction
            libxml_use_internal_errors(true);
            $xmlDoc = new DOMDocument();
            $xmlDoc->loadXML($rawXml);
            $xpath = new DOMXPath($xmlDoc);
            
            // Register all namespaces from the document
            foreach ($xmlDoc->documentElement->getAttributesNS() as $attrName => $attrNode) {
                if (preg_match('/^xmlns:(.+)$/', $attrName, $matches)) {
                    $prefix = $matches[1];
                    $uri = $attrNode->nodeValue;
                    $xpath->registerNamespace($prefix, $uri);
                }
            }
            
            // Try multiple XPath expressions to find parameters
            $paramNodes = [];
            $xpathExpressions = [
                '//ParameterInfoStruct/Name',
                '//cwmp:ParameterInfoStruct/cwmp:Name',
                '//ParameterList/ParameterInfoStruct/Name',
                '//cwmp:ParameterList/cwmp:ParameterInfoStruct/cwmp:Name'
            ];
            
            foreach ($xpathExpressions as $expr) {
                $nodes = $xpath->query($expr);
                if ($nodes && $nodes->length > 0) {
                    $paramNodes = $nodes;
                    break;
                }
            }
            
            // Process the discovered parameters
            if (count($paramNodes) > 0) {
                foreach ($paramNodes as $paramNode) {
                    $paramName = $paramNode->textContent;
                    $parameterList[] = $paramName;
                    
                    error_log("TR069Server: Discovered parameter: " . $paramName);
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Discovered parameter: " . $paramName . "\n", FILE_APPEND);
                    
                    // Detect WiFi interfaces
                    if (preg_match('/WLANConfiguration\.(\d+)$/', $paramName, $matches)) {
                        $detectedWifiInterfaces[] = $matches[1];
                        $detectedWlanObjects[] = $paramName;
                    }
                    
                    // Detect X_HW vendor-specific parameters
                    if (stripos($paramName, 'X_HW_') !== false) {
                        $detectedXHWParams[] = $paramName;
                    }
                    
                    // Try to identify WiFi security parameter names
                    if (stripos($paramName, 'KeyPassphrase') !== false || 
                        stripos($paramName, 'PreSharedKey') !== false || 
                        stripos($paramName, 'WPAKey') !== false || 
                        stripos($paramName, 'SecurityKey') !== false ||
                        stripos($paramName, 'Password') !== false) {
                        $detectedSecurityParams[] = $paramName;
                    }
                }
            }
            
            if (empty($parameterList)) {
                error_log("TR069Server: No parameters found in GetParameterNamesResponse");
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " No parameters found in GetParameterNamesResponse\n", FILE_APPEND);
                
                // Move to next exploration phase
                $this->explorationPhase++;
                error_log("TR069Server: Moving to exploration phase {$this->explorationPhase} after empty response");
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Moving to exploration phase {$this->explorationPhase}\n", FILE_APPEND);
                
                // If we've gone through all phases, try the comprehensive security parameters
                if ($this->explorationPhase > 6) {
                    $this->soapResponse = $this->responseGenerator->createWifiSecurityParametersRequest($this->sessionId);
                } else {
                    $this->soapResponse = $this->responseGenerator->createExploreParametersRequest(
                        $this->sessionId, 
                        $this->explorationPhase
                    );
                }
                return;
            }
            
            // If in exploration mode, analyze what we found and decide next steps
            if ($this->discoveryMode) {
                error_log("TR069Server: Analysis of exploration phase " . $this->explorationPhase);
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Analysis of exploration phase " . $this->explorationPhase . "\n", FILE_APPEND);
                
                // Check if we found WLANConfiguration objects
                if (!empty($detectedWlanObjects)) {
                    error_log("TR069Server: Found WLANConfiguration objects: " . implode(", ", $detectedWlanObjects));
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Found WLANConfiguration objects: " . implode(", ", $detectedWlanObjects) . "\n", FILE_APPEND);
                    
                    // Explore first WLAN configuration
                    if (count($detectedWlanObjects) > 0) {
                        $wlanPath = $detectedWlanObjects[0] . ".";
                        error_log("TR069Server: Exploring WLAN path: " . $wlanPath);
                        file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Exploring WLAN path: " . $wlanPath . "\n", FILE_APPEND);
                        
                        $this->soapResponse = $this->responseGenerator->createGetParameterNamesRequest(
                            $this->sessionId, 
                            $wlanPath, 
                            1
                        );
                        return;
                    }
                }
                
                // Check if we found X_HW parameters
                if (!empty($detectedXHWParams)) {
                    error_log("TR069Server: Found X_HW parameters: " . implode(", ", $detectedXHWParams));
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Found X_HW parameters: " . implode(", ", $detectedXHWParams) . "\n", FILE_APPEND);
                    
                    // Look for X_HW_WLAN or similar
                    foreach ($detectedXHWParams as $param) {
                        if (stripos($param, 'WLAN') !== false || stripos($param, 'WiFi') !== false) {
                            error_log("TR069Server: Exploring X_HW WiFi path: " . $param);
                            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Exploring X_HW WiFi path: " . $param . "\n", FILE_APPEND);
                            
                            $this->soapResponse = $this->responseGenerator->createGetParameterNamesRequest(
                                $this->sessionId, 
                                $param . ".", 
                                1
                            );
                            return;
                        }
                    }
                }
                
                // Move to next exploration phase if we didn't find anything interesting in this phase
                $this->explorationPhase++;
                error_log("TR069Server: Moving to exploration phase {$this->explorationPhase}");
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Moving to exploration phase {$this->explorationPhase}\n", FILE_APPEND);
                
                // If we've gone through all phases, try the comprehensive security parameters
                if ($this->explorationPhase > 6) {
                    $this->soapResponse = $this->responseGenerator->createWifiSecurityParametersRequest($this->sessionId);
                } else {
                    $this->soapResponse = $this->responseGenerator->createExploreParametersRequest(
                        $this->sessionId, 
                        $this->explorationPhase
                    );
                }
                return;
            }
            
            // Original WLAN Configuration discovery logic continues below
            if ($this->parameterDiscoveryStage == 'wlanconfig') {
                if (!empty($detectedWifiInterfaces)) {
                    error_log("TR069Server: Detected WiFi interfaces: " . implode(", ", $detectedWifiInterfaces));
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Detected WiFi interfaces: " . implode(", ", $detectedWifiInterfaces) . "\n", FILE_APPEND);
                    
                    // Next, discover parameters for the first interface
                    if (count($detectedWifiInterfaces) > 0) {
                        $interface = $detectedWifiInterfaces[0];
                        $paramPath = "InternetGatewayDevice.LANDevice.1.WLANConfiguration." . $interface . ".";
                        
                        $this->parameterDiscoveryStage = 'parameters';
                        $this->soapResponse = $this->responseGenerator->createGetParameterNamesRequest(
                            $this->sessionId, 
                            $paramPath, 
                            1
                        );
                        return;
                    }
                }
                
                // If no interfaces found, try a direct GetParameterValues with default names
                $this->soapResponse = $this->responseGenerator->createHuaweiGetParameterValuesRequest($this->sessionId);
                return;
            }
            
            // Parameter discovery phase - find the actual parameters we need (SSID, password)
            if ($this->parameterDiscoveryStage == 'parameters') {
                // Check if we found security parameters
                $discoveredSSID = false;
                $discoveredKey = false;
                $ssidParam = "";
                $keyParam = "";
                
                foreach ($parameterList as $param) {
                    if (stripos($param, 'SSID') !== false && !$discoveredSSID) {
                        $ssidParam = $param;
                        $discoveredSSID = true;
                    }
                    
                    // Look for password parameters with different possible names
                    if ((stripos($param, 'Key') !== false || 
                         stripos($param, 'Password') !== false || 
                         stripos($param, 'PreSharedKey') !== false) && 
                        !$discoveredKey) {
                        $keyParam = $param;
                        $discoveredKey = true;
                    }
                }
                
                // Build custom GetParameterValues based on discovered parameters
                if ($discoveredSSID || $discoveredKey) {
                    error_log("TR069Server: Discovered SSID param: " . $ssidParam . ", Key param: " . $keyParam);
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Discovered SSID param: " . $ssidParam . ", Key param: " . $keyParam . "\n", FILE_APPEND);
                    
                    // Build a custom GetParameterValues request with only the discovered parameters
                    $paramCount = 0;
                    $paramXml = "";
                    
                    if ($discoveredSSID) {
                        $paramXml .= "<string>" . $ssidParam . "</string>\n                        ";
                        $paramCount++;
                    }
                    
                    if ($discoveredKey) {
                        $paramXml .= "<string>" . $keyParam . "</string>\n                        ";
                        $paramCount++;
                    }
                    
                    $request = '<?xml version="1.0" encoding="UTF-8"?>
                    <SOAP-ENV:Envelope
                        xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"
                        xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
                        xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                        xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/">
                        <SOAP-ENV:Header>
                            <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $this->sessionId . '</cwmp:ID>
                        </SOAP-ENV:Header>
                        <SOAP-ENV:Body>
                            <cwmp:GetParameterValues>
                                <ParameterNames SOAP-ENC:arrayType="xsd:string[' . $paramCount . ']">
                                    ' . $paramXml . '
                                </ParameterNames>
                            </cwmp:GetParameterValues>
                        </SOAP-ENV:Body>
                    </SOAP-ENV:Envelope>';
                    
                    error_log("TR069Server: Created custom GetParameterValues request with discovered parameters");
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Custom GetParameterValues request: " . $request . "\n", FILE_APPEND);
                    
                    $this->soapResponse = $request;
                    return;
                }
                
                // If no specific parameters found, try default ones
                $this->soapResponse = $this->responseGenerator->createHuaweiGetParameterValuesRequest($this->sessionId);
                return;
            }
            
            // Default - no specific action
            $this->soapResponse = null;
            
        } catch (Exception $e) {
            error_log("TR069Server: Error in handleGetParameterNamesResponse: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
            
            // For Huawei devices, try to continue even with errors
            if ($this->isHuaweiDevice) {
                $this->soapResponse = null;
                return;
            }
            
            throw $e;
        }
    }

    private function handleInform($request, $rawXml = '') {
        try {
            // Look for Huawei-specific indicators in the XML
            if (stripos($rawXml, 'HG8') !== false || 
                stripos($rawXml, 'Huawei') !== false || 
                $this->isHuaweiDevice) {
                
                error_log("TR069Server: Confirmed Huawei device from XML content or previous detection");
                $this->isHuaweiDevice = true;
            }
            
            // Get manufacturer from the DeviceId section first
            $deviceId = $request->DeviceID;
            $manufacturer = (string)$deviceId->Manufacturer;
            
            error_log("TR069Server: Detected device manufacturer: " . $manufacturer);
            
            // If manufacturer contains 'huawei', set the flag
            if (stripos($manufacturer, 'huawei') !== false) {
                $this->isHuaweiDevice = true;
                error_log("TR069Server: Confirmed Huawei device from manufacturer name");
            }
            
            // Select appropriate parser based on device detection
            $deviceInfo = null;
            if ($this->isHuaweiDevice) {
                error_log("TR069Server: Using Huawei parser for device");
                $deviceInfo = $this->huaweiInformParser->parseInform($request);
                
                // Save serial number for session management
                $this->serialNumber = $deviceInfo['serialNumber'];
                
                // Ensure required fields for device_manager.php are set
                $deviceInfo['ssid'] = $deviceInfo['ssid1'] ?? null;
                $deviceInfo['ssidPassword'] = $deviceInfo['ssidPassword1'] ?? null;
                $deviceInfo['connectedClients'] = ($deviceInfo['connectedClients1'] ?? 0) + ($deviceInfo['connectedClients2'] ?? 0);
                
                // Log the enhanced information
                error_log("TR069Server: Huawei device details - Model: " . $deviceInfo['modelName'] . 
                          ", Serial: " . $deviceInfo['serialNumber'] . 
                          ", SSID: " . ($deviceInfo['ssid'] ?? 'Not provided'));
            } else {
                error_log("TR069Server: Using default/MikroTik parser for device");
                $deviceInfo = $this->informParser->parseInform($request);
            }
            
            $this->deviceId = $this->deviceManager->updateDevice($deviceInfo);
            
            // Use existing session ID if available, otherwise create new
            if (empty($this->sessionId)) {
                $this->sessionId = $this->sessionManager->createSession($deviceInfo['serialNumber']);
            } else {
                // Ensure we have a valid session with this ID
                $this->sessionManager->updateOrCreateSession($deviceInfo['serialNumber'], $this->sessionId);
            }
            
            $this->soapResponse = $this->responseGenerator->createResponse($this->sessionId);
            
            error_log("TR069Server: Inform handled successfully. Device ID: " . $this->deviceId . ", Session ID: " . $this->sessionId);
            
            // For Huawei devices, we want to set a flag for sending GetParameterValues
            // (this will be done in the next empty POST)
            if ($this->isHuaweiDevice) {
                $this->shouldSendGetParameterValues = true;
                error_log("TR069Server: Flag set to send Huawei GetParameterValues on next empty POST");
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Set flag for GetParameterValues on next empty POST, session: " . $this->sessionId . "\n", FILE_APPEND);
            }
        } catch (Exception $e) {
            error_log("TR069Server: Error in handleInform: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Error in handleInform: " . $e->getMessage() . "\n", FILE_APPEND);
            throw $e;
        }
    }

    private function handleGetParameterValuesResponse($request, $rawXml = '') {
        try {
            error_log("TR069Server: Processing GetParameterValuesResponse");
            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " GetParameterValuesResponse received\n", FILE_APPEND);
            
            // For Huawei devices, process the parameter values differently
            if ($this->isHuaweiDevice) {
                error_log("TR069Server: Processing Huawei GetParameterValuesResponse");
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Processing Huawei GetParameterValuesResponse\n", FILE_APPEND);
                
                // Log the raw XML for debugging
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Raw response XML: " . $rawXml . "\n", FILE_APPEND);
                
                // Parse out values from the XML response
                $parameterValues = [];
                
                // Try to extract values using various XML parsing methods
                try {
                    // First try with SimpleXML
                    $xml = simplexml_load_string($rawXml);
                    $namespace = $xml->getNamespaces(true);
                    
                    // Try different approaches to find ParameterList
                    $parameterList = null;
                    $body = $xml->children($namespace['SOAP-ENV'] ?? 'http://schemas.xmlsoap.org/soap/envelope/')->Body;
                    
                    // Log debug info about what we found in Body
                    foreach ($body->children() as $child) {
                        error_log("TR069Server: Found child in Body: " . $child->getName());
                    }
                    
                    // Check for GetParameterValuesResponse in different namespaces
                    foreach ($namespace as $prefix => $uri) {
                        $response = $body->children($uri);
                        if (isset($response->GetParameterValuesResponse)) {
                            $parameterList = $response->GetParameterValuesResponse->ParameterList;
                            error_log("TR069Server: Found ParameterList in namespace: " . $uri);
                            break;
                        }
                    }
                    
                    // If still not found, try direct XML parsing
                    if (empty($parameterList)) {
                        error_log("TR069Server: ParameterList not found via SimpleXML, trying DOM");
                        $dom = new DOMDocument();
                        $dom->loadXML($rawXml);
                        $xpath = new DOMXPath($dom);
                        
                        // Register all namespaces from document
                        $namespaces = [];
                        foreach ($dom->documentElement->attributes as $attr) {
                            if (strpos($attr->nodeName, 'xmlns:') === 0) {
                                $prefix = substr($attr->nodeName, 6);
                                $uri = $attr->nodeValue;
                                $xpath->registerNamespace($prefix, $uri);
                                $namespaces[$prefix] = $uri;
                            }
                        }
                        
                        // Try multiple XPath expressions to find parameters
                        $paramNodes = [];
                        $xpathExpressions = [
                            '//ParameterValueStruct',
                            '//cwmp:ParameterValueStruct',
                            '//ParameterList/ParameterValueStruct',
                            '//cwmp:ParameterList/cwmp:ParameterValueStruct'
                        ];
                        
                        foreach ($xpathExpressions as $expr) {
                            $nodes = $xpath->query($expr);
                            if ($nodes && $nodes->length > 0) {
                                error_log("TR069Server: Found parameter nodes with XPath: " . $expr . ", count: " . $nodes->length);
                                
                                foreach ($nodes as $node) {
                                    $nameNode = $xpath->query('.//Name', $node)->item(0);
                                    $valueNode = $xpath->query('.//Value', $node)->item(0);
                                    
                                    if ($nameNode && $valueNode) {
                                        $name = $nameNode->textContent;
                                        $value = $valueNode->textContent;
                                        
                                        error_log("TR069Server: Found parameter: " . $name . " = " . $value);
                                        $parameterValues[$name] = $value;
                                    }
                                }
                                break;
                            }
                        }
                    } else {
                        // Process ParameterList found via SimpleXML
                        foreach ($parameterList->children() as $param) {
                            $name = (string)$param->Name;
                            $value = (string)$param->Value;
                            $parameterValues[$name] = $value;
                        }
                    }
                } catch (Exception $e) {
                    error_log("TR069Server: Error parsing GetParameterValuesResponse: " . $e->getMessage());
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Error parsing response: " . $e->getMessage() . "\n", FILE_APPEND);
                }
                
                // Log what parameters we found
                if (!empty($parameterValues)) {
                    error_log("TR069Server: Extracted " . count($parameterValues) . " parameters from response");
                    foreach ($parameterValues as $name => $value) {
                        error_log("TR069Server: Parameter: " . $name . " = " . $value);
                        file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Parameter: " . $name . " = " . $value . "\n", FILE_APPEND);
                    }
                    
                    // Update device info in database with the new values
                    if (!empty($this->serialNumber)) {
                        // Look for specific parameters we're interested in
                        $deviceUpdates = [];
                        
                        // WiFi parameters - different devices use different parameter names
                        foreach ($parameterValues as $name => $value) {
                            // SSID detection for 2.4GHz and 5GHz
                            if (strpos($name, 'WLANConfiguration.1.SSID') !== false) {
                                $deviceUpdates['ssid1'] = $value;
                            } else if (strpos($name, 'WLANConfiguration.2.SSID') !== false) {
                                $deviceUpdates['ssid2'] = $value;
                            } else if (strpos($name, 'WLANConfiguration.5.SSID') !== false) {
                                $deviceUpdates['ssid2'] = $value;
                            }
                            
                            // WiFi password detection - different possible parameter names
                            if (stripos($name, 'WLANConfiguration.1.KeyPassphrase') !== false || 
                                stripos($name, 'WLANConfiguration.1.PreSharedKey') !== false) {
                                $deviceUpdates['ssidPassword1'] = $value;
                            } else if (stripos($name, 'WLANConfiguration.2.KeyPassphrase') !== false || 
                                       stripos($name, 'WLANConfiguration.2.PreSharedKey') !== false) {
                                $deviceUpdates['ssidPassword2'] = $value;
                            } else if (stripos($name, 'WLANConfiguration.5.KeyPassphrase') !== false || 
                                       stripos($name, 'WLANConfiguration.5.PreSharedKey') !== false) {
                                $deviceUpdates['ssidPassword2'] = $value;
                            }
                        }
                        
                        // Only update if we found any parameters to update
                        if (!empty($deviceUpdates)) {
                            error_log("TR069Server: Updating device with new values: " . json_encode($deviceUpdates));
                            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Updating device with values: " . json_encode($deviceUpdates) . "\n", FILE_APPEND);
                            
                            $deviceInfo = [
                                'serialNumber' => $this->serialNumber,
                            ];
                            
                            // Merge the updates into deviceInfo
                            foreach ($deviceUpdates as $key => $value) {
                                $deviceInfo[$key] = $value;
                            }
                            
                            // Update the device in database
                            $this->deviceManager->updateDevice($deviceInfo);
                        } else {
                            error_log("TR069Server: No WiFi parameters found to update");
                            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " No WiFi parameters found to update\n", FILE_APPEND);
                        }
                    } else {
                        error_log("TR069Server: Cannot update device - no serial number available");
                        file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Cannot update device - no serial number\n", FILE_APPEND);
                    }
                } else {
                    error_log("TR069Server: No parameters found in GetParameterValuesResponse");
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " No parameters found in response\n", FILE_APPEND);
                }
            }
            
            // No response needed for GetParameterValuesResponse
            $this->soapResponse = null;
        } catch (Exception $e) {
            error_log("TR069Server: Error in handleGetParameterValuesResponse: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
            
            // For Huawei devices, try to continue even with errors
            if ($this->isHuaweiDevice) {
                $this->soapResponse = null;
                return;
            }
            
            throw $e;
        }
    }

    private function handleEmptyRequest() {
        error_log("TR069Server: Handling empty request");
        
        // For empty request with a session ID, send GetParameterValues if flag is set
        if (!empty($this->sessionId) && $this->shouldSendGetParameterValues) {
            error_log("TR069Server: Session ID exists and should send GetParameterValues");
            
            // Check if this is a Huawei device - use appropriate request
            if ($this->isHuaweiDevice) {
                error_log("TR069Server: Sending Huawei GetParameterValues");
                
                // If we are in discovery mode, we use different logic
                if ($this->discoveryMode) {
                    error_log("TR069Server: In discovery mode, phase: " . $this->explorationPhase);
                    
                    // Generate parameter exploration request for current phase
                    $this->soapResponse = $this->responseGenerator->createExploreParametersRequest(
                        $this->sessionId, 
                        $this->explorationPhase
                    );
                } else if ($this->useParameterDiscovery) {
                    // Start exploration sequence
                    $this->discoveryMode = true;
                    $this->explorationPhase = 1;
                    $this->explorationAttempts = 0;
                    $this->soapResponse = $this->responseGenerator->createExploreParametersRequest(
                        $this->sessionId, 
                        $this->explorationPhase
                    );
                } else {
                    // Use standard request if not in discovery mode
                    $this->soapResponse = $this->responseGenerator->createHuaweiGetParameterValuesRequest($this->sessionId);
                }
            } else {
                // Standard GetParameterValues for non-Huawei devices
                $this->soapResponse = $this->responseGenerator->createGetParameterValuesRequest($this->sessionId);
            }
            
            // Reset the flag
            $this->shouldSendGetParameterValues = false;
        } else {
            error_log("TR069Server: Empty request with no valid session or no GetParameterValues needed");
            $this->soapResponse = null;
        }
    }

    private function sendResponse() {
        if (!empty($this->soapResponse)) {
            error_log("TR069Server: Sending SOAP response, length: " . strlen($this->soapResponse));
            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Sending response, length: " . strlen($this->soapResponse) . "\n", FILE_APPEND);
            
            // Set appropriate headers
            header('Content-Type: text/xml; charset=utf-8');
            header('Content-Length: ' . strlen($this->soapResponse));
            
            // Output the response
            echo $this->soapResponse;
        } else {
            error_log("TR069Server: No response to send (empty response)");
            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " No response to send\n", FILE_APPEND);
            
            // Send empty 204 response
            header('HTTP/1.1 204 No Content');
        }
    }
}

