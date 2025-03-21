
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
    private $modelHint = '';
    private $shouldSendGetParameterValues = false;
    private $serialNumber = null;
    private $useParameterDiscovery = false;
    private $discoveryMode = false;
    private $parameterDiscoveryStage = 'none'; // none, wlanconfig, parameters
    private $explorationPhase = 1; // Track what phase of parameter exploration we're in
    private $explorationAttempts = 0; // Count of attempts in current exploration phase
    private $hg8145vDiscoveryStep = 1; // Specifically for HG8145V model

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
            
            // Try to determine model from user agent
            if (stripos($_SERVER['HTTP_USER_AGENT'], 'hg8145') !== false) {
                $this->modelHint = 'HG8145V';
                error_log("TR069Server: Detected HG8145V model from User-Agent");
            }
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
    
    // Allow setting model hint from outside
    public function setModelHint($modelHint) {
        $this->modelHint = $modelHint;
        error_log("TR069Server: Model hint set to: " . $modelHint);
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
            
            // For HG8145V models, use the special discovery sequence
            if ($this->modelHint == 'HG8145V') {
                error_log("TR069Server: Using HG8145V-specific discovery sequence, step " . $this->hg8145vDiscoveryStep);
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Using HG8145V-specific discovery, step " . $this->hg8145vDiscoveryStep . "\n", FILE_APPEND);
                
                $this->soapResponse = $this->responseGenerator->createHG8145VDiscoverySequence(
                    $this->sessionId, 
                    $this->hg8145vDiscoveryStep
                );
                
                // Increment discovery step for next time
                $this->hg8145vDiscoveryStep++;
                if ($this->hg8145vDiscoveryStep > 7) {
                    $this->hg8145vDiscoveryStep = 1; // Reset if we've gone through all steps
                }
            } else {
                // Always send the GetParameterValues for other Huawei devices on empty POST
                $this->soapResponse = $this->responseGenerator->createHuaweiGetParameterValuesRequest($this->sessionId);
            }
            
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
                        if (!empty($this->sessionId)) {
                            $this->sessionManager->recordFault($this->sessionId, $cwmpFaultCode, $cwmpFaultString);
                        }
                        
                        // HG8145V specific handling
                        if ($cwmpFaultCode == '9005' && $this->modelHint == 'HG8145V') {
                            error_log("TR069Server: Invalid parameter fault (9005) for HG8145V - Moving to next step in discovery");
                            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Invalid parameter (9005) for HG8145V - Using discovery step " . $this->hg8145vDiscoveryStep . "\n", FILE_APPEND);
                            
                            // Generate the next discovery request
                            $this->soapResponse = $this->responseGenerator->createHG8145VDiscoverySequence(
                                $this->sessionId, 
                                $this->hg8145vDiscoveryStep
                            );
                            
                            // Increment for next time
                            $this->hg8145vDiscoveryStep++;
                            if ($this->hg8145vDiscoveryStep > 7) {
                                $this->hg8145vDiscoveryStep = 1; // Reset after going through all steps
                            }
                            
                            return;
                        }
                        // If this is an invalid parameter fault (9005), we should initiate deeper parameter exploration
                        else if ($cwmpFaultCode == '9005' && $this->isHuaweiDevice && $this->useParameterDiscovery) {
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
            } else if ($this->modelHint == 'HG8145V') {
                error_log("TR069Server: HG8145V discovery step: " . $this->hg8145vDiscoveryStep);
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " HG8145V discovery step: " . $this->hg8145vDiscoveryStep . "\n", FILE_APPEND);
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
            
            // Special handling for HG8145V model
            if ($this->modelHint == 'HG8145V') {
                if (empty($parameterList)) {
                    error_log("TR069Server: No parameters found in GetParameterNamesResponse for HG8145V");
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " No parameters found for HG8145V in step " . $this->hg8145vDiscoveryStep . "\n", FILE_APPEND);
                    
                    // Move to next discovery step
                    $this->hg8145vDiscoveryStep++;
                    if ($this->hg8145vDiscoveryStep > 7) {
                        $this->hg8145vDiscoveryStep = 1; // Reset if we've gone through all steps
                    }
                    
                    $this->soapResponse = $this->responseGenerator->createHG8145VDiscoverySequence(
                        $this->sessionId, 
                        $this->hg8145vDiscoveryStep
                    );
                    return;
                }
                
                // Check if we found WLANConfiguration objects or other interesting parameters
                if (!empty($detectedWlanObjects)) {
                    error_log("TR069Server: Found WLANConfiguration objects for HG8145V: " . implode(", ", $detectedWlanObjects));
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Found WLANConfiguration objects: " . implode(", ", $detectedWlanObjects) . "\n", FILE_APPEND);
                    
                    // For HG8145V, after finding WLAN objects, move to exploring the first one
                    if (count($detectedWlanObjects) > 0) {
                        $wlanPath = $detectedWlanObjects[0] . ".";
                        error_log("TR069Server: HG8145V - Exploring WLAN path: " . $wlanPath);
                        file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " HG8145V - Exploring WLAN path: " . $wlanPath . "\n", FILE_APPEND);
                        
                        $this->soapResponse = $this->responseGenerator->createGetParameterNamesRequest(
                            $this->sessionId, 
                            $wlanPath, 
                            1
                        );
                        return;
                    }
                }
                
                // Process any security parameters we detected
                if (!empty($detectedSecurityParams)) {
                    error_log("TR069Server: Found security parameters for HG8145V: " . implode(", ", $detectedSecurityParams));
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Found security parameters: " . implode(", ", $detectedSecurityParams) . "\n", FILE_APPEND);
                    
                    // Send a GetParameterValues request for the security parameters
                    $this->soapResponse = $this->responseGenerator->createCustomGetParameterValuesRequest(
                        $this->sessionId,
                        $detectedSecurityParams
                    );
                    return;
                }
                
                // If we found X_HW parameters, try to get those values
                if (!empty($detectedXHWParams)) {
                    error_log("TR069Server: Found X_HW parameters for HG8145V: " . implode(", ", $detectedXHWParams));
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Found X_HW parameters: " . implode(", ", $detectedXHWParams) . "\n", FILE_APPEND);
                    
                    // Send a GetParameterValues request for the X_HW parameters
                    $this->soapResponse = $this->responseGenerator->createCustomGetParameterValuesRequest(
                        $this->sessionId,
                        $detectedXHWParams
                    );
                    return;
                }
                
                // If no interesting parameters found, move to next discovery step
                $this->hg8145vDiscoveryStep++;
                if ($this->hg8145vDiscoveryStep > 7) {
                    $this->hg8145vDiscoveryStep = 1; // Reset if we've gone through all steps
                }
                
                $this->soapResponse = $this->responseGenerator->createHG8145VDiscoverySequence(
                    $this->sessionId, 
                    $this->hg8145vDiscoveryStep
                );
                return;
            }
            
            // Handle regular discovery mode for other devices
            if ($this->discoveryMode || $this->useParameterDiscovery) {
                // If we detected WLAN interfaces, try to get more info about them
                if (!empty($detectedWifiInterfaces)) {
                    error_log("TR069Server: Detected WiFi interfaces: " . implode(", ", $detectedWifiInterfaces));
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Detected WiFi interfaces: " . implode(", ", $detectedWifiInterfaces) . "\n", FILE_APPEND);
                    
                    // Prioritize looking for security parameters
                    $securityParamList = [];
                    foreach ($detectedWifiInterfaces as $ifaceNum) {
                        $securityParamList[] = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$ifaceNum}.SSID";
                        $securityParamList[] = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$ifaceNum}.KeyPassphrase";
                        $securityParamList[] = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$ifaceNum}.PreSharedKey";
                        $securityParamList[] = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$ifaceNum}.X_HW_WPAKey";
                    }
                    
                    $this->soapResponse = $this->responseGenerator->createCustomGetParameterValuesRequest(
                        $this->sessionId,
                        $securityParamList
                    );
                    return;
                }
                
                // If we found security parameters directly, query them
                if (!empty($detectedSecurityParams)) {
                    error_log("TR069Server: Detected security parameters: " . implode(", ", $detectedSecurityParams));
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Detected security parameters: " . implode(", ", $detectedSecurityParams) . "\n", FILE_APPEND);
                    
                    $this->soapResponse = $this->responseGenerator->createCustomGetParameterValuesRequest(
                        $this->sessionId,
                        $detectedSecurityParams
                    );
                    return;
                }
                
                // Continue with parameter exploration
                if ($this->explorationPhase < 6) {
                    $this->explorationPhase++;
                    $this->explorationAttempts = 0;
                    
                    $this->soapResponse = $this->responseGenerator->createExploreParametersRequest(
                        $this->sessionId,
                        $this->explorationPhase
                    );
                    return;
                }
                
                // If all phases complete, try comprehensive WiFi security
                $this->soapResponse = $this->responseGenerator->createWifiSecurityParametersRequest($this->sessionId);
                return;
            }
            
            // Default response for parameter names - look deeper
            if (!empty($parameterList)) {
                // Try to get values for any discovered parameters that might be interesting
                $valuesToGet = [];
                
                foreach ($parameterList as $param) {
                    // Look for SSID/WiFi/security related parameters
                    if (stripos($param, 'SSID') !== false || 
                        stripos($param, 'WPA') !== false || 
                        stripos($param, 'KeyPassphrase') !== false || 
                        stripos($param, 'PreSharedKey') !== false || 
                        stripos($param, 'WiFi') !== false ||
                        stripos($param, 'WLAN') !== false) {
                        $valuesToGet[] = $param;
                    }
                }
                
                if (!empty($valuesToGet)) {
                    $this->soapResponse = $this->responseGenerator->createCustomGetParameterValuesRequest(
                        $this->sessionId,
                        $valuesToGet
                    );
                    return;
                }
            }
            
            // No relevant parameters found, return empty response
            $this->soapResponse = null;
        } catch (Exception $e) {
            error_log("TR069Server: Error in handleGetParameterNamesResponse: " . $e->getMessage());
            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Error in handleGetParameterNamesResponse: " . $e->getMessage() . "\n", FILE_APPEND);
            $this->soapResponse = null;
        }
    }
    
    // Implement remaining method stubs
    private function handleInform($request, $rawXml = '') {
        // Implementation of handleInform...
    }
    
    private function handleGetParameterValuesResponse($request, $rawXml = '') {
        // Implementation of handleGetParameterValuesResponse...
    }
    
    private function handleEmptyRequest() {
        // Implementation of handleEmptyRequest...
    }
    
    private function sendResponse() {
        // Implementation of sendResponse...
    }
}
