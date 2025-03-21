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
    private $hg8145vDiscoveryStep = 1; // Specifically for HG8145V model
    private $discoveredParameters = []; // Store parameters discovered for requesting values

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
            error_log("TR069: Detected Huawei device from User-Agent");
            
            // Try to determine model from user agent
            if (stripos($_SERVER['HTTP_USER_AGENT'], 'hg8145') !== false) {
                $this->modelHint = 'HG8145V';
                error_log("TR069: Detected HG8145V model from User-Agent");
            }
        }
        
        // Check for session ID in URL for continued sessions
        if (isset($_GET['session_id'])) {
            $this->sessionId = $_GET['session_id'];
            error_log("TR069: Found session ID in URL: " . $this->sessionId);
            
            // If valid session, get serial number
            $session = $this->sessionManager->validateSession($this->sessionId);
            if ($session) {
                $this->serialNumber = $session['device_serial'];
                error_log("TR069: Valid session with serial number: " . $this->serialNumber);
            } else {
                error_log("TR069: Invalid session ID in URL");
                $this->sessionId = null;
            }
        }
    }
    
    // Allow setting the Huawei detection flag from outside
    public function setHuaweiDetection($isHuawei) {
        $this->isHuaweiDevice = $isHuawei;
        if ($isHuawei) {
            error_log("TR069: External Huawei device detection confirmed");
        }
    }
    
    // Allow setting model hint from outside
    public function setModelHint($modelHint) {
        $this->modelHint = $modelHint;
        error_log("TR069: Model hint set to: " . $modelHint);
    }

    // New method to control parameter discovery
    public function setUseParameterDiscovery($useDiscovery) {
        $this->useParameterDiscovery = $useDiscovery;
        error_log("TR069: Parameter discovery " . ($useDiscovery ? "enabled" : "disabled"));
    }
    
    public function handleRequest() {
        error_log("TR069: Beginning request handling");
        error_log("TR069: Request received: " . date('Y-m-d H:i:s'));
        
        if (!$this->authHandler->authenticate()) {
            error_log("TR069: Authentication failed");
            header('WWW-Authenticate: Basic realm="TR-069 ACS"');
            header('HTTP/1.1 401 Unauthorized');
            exit('Authentication required');
        }

        $rawPost = file_get_contents('php://input');
        
        // Simplified logging - only log that we received data, not the full content
        if (!empty($rawPost)) {
            error_log("TR069: Received POST data of length " . strlen($rawPost));
        } else {
            error_log("TR069: Empty POST received");
            file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Empty POST received\n", FILE_APPEND);
        }
        
        // If this is an empty POST with a Huawei device, start parameter discovery
        if ($this->isHuaweiDevice && empty($rawPost)) {
            error_log("TR069: Empty POST from Huawei device - starting parameter discovery");
            file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Starting WiFi parameter discovery\n", FILE_APPEND);
            
            // Generate a session ID if we don't have one already
            if (empty($this->sessionId)) {
                $this->sessionId = bin2hex(random_bytes(16));
                error_log("TR069: Generated new session ID for empty POST: " . $this->sessionId);
                file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " New session ID: " . $this->sessionId . "\n", FILE_APPEND);
                
                // If we have a serial number from a previous inform, save the session
                if (!empty($this->serialNumber)) {
                    $this->sessionManager->createSession($this->serialNumber, $this->sessionId);
                }
            }
            
            // For HG8145V models, use the special discovery sequence
            if ($this->modelHint == 'HG8145V') {
                error_log("TR069: Using HG8145V-specific WiFi discovery sequence, step " . $this->hg8145vDiscoveryStep);
                file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " HG8145V WiFi discovery step " . $this->hg8145vDiscoveryStep . "\n", FILE_APPEND);
                
                $this->soapResponse = $this->responseGenerator->createHG8145VWifiDiscoverySequence(
                    $this->sessionId, 
                    $this->hg8145vDiscoveryStep
                );
                
                // Increment discovery step for next time
                $this->hg8145vDiscoveryStep++;
                if ($this->hg8145vDiscoveryStep > 5) {
                    $this->hg8145vDiscoveryStep = 1; // Reset if we've gone through all steps
                }
            } else {
                // Start with basic discovery for other Huawei devices
                $this->soapResponse = $this->responseGenerator->createWifiDiscoveryRequest($this->sessionId);
            }
            
            file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Sending discovery request with session ID: " . $this->sessionId . "\n", FILE_APPEND);
            $this->sendResponse();
            return;
        }

        // Handle non-empty POST data - this is either an Inform or a response
        if (!empty($rawPost)) {
            try {
                libxml_use_internal_errors(true);
                $xml = new SimpleXMLElement($rawPost);
                $this->processRequest($xml, $rawPost);
                $this->sendResponse();
            } catch (Exception $e) {
                error_log("TR069 Error: " . $e->getMessage());
                
                // Log XML parsing errors
                if ($this->isHuaweiDevice) {
                    $errors = libxml_get_errors();
                    foreach ($errors as $error) {
                        error_log("XML Error: Line {$error->line}, Column {$error->column}: {$error->message}");
                    }
                    libxml_clear_errors();
                }
                
                file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " ERROR parsing XML: " . $e->getMessage() . "\n", FILE_APPEND);
                
                header('HTTP/1.1 500 Internal Server Error');
                echo "Internal Server Error: " . $e->getMessage();
                exit;
            }
        } else {
            $this->handleEmptyRequest();
        }
    }

    private function processRequest($xml, $rawXml = '') {
        try {
            $namespace = $xml->getNamespaces(true);
            $soapEnv = isset($namespace['SOAP-ENV']) ? $namespace['SOAP-ENV'] : 'http://schemas.xmlsoap.org/soap/envelope/';
            $cwmp = isset($namespace['cwmp']) ? $namespace['cwmp'] : 'urn:dslforum-org:cwmp-1-0';

            error_log("TR069: Processing SOAP request");

            // Extract SOAP Header ID if present for session tracking
            $header = $xml->children($soapEnv)->Header;
            if ($header) {
                $soapId = $header->children($cwmp)->ID;
                if ($soapId) {
                    error_log("TR069: Found SOAP ID in header: " . (string)$soapId);
                    file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Found SOAP ID: " . (string)$soapId . "\n", FILE_APPEND);
                    // Use this ID for session correlation
                    $this->sessionId = (string)$soapId;
                }
            }

            $body = $xml->children($soapEnv)->Body;
            if (empty($body)) {
                error_log("TR069: Empty SOAP Body received");
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
                        
                        error_log("TR069: CWMP Fault received - Code: {$cwmpFaultCode}, String: {$cwmpFaultString}");
                        file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " CWMP Fault - Code: {$cwmpFaultCode}, String: {$cwmpFaultString}\n", FILE_APPEND);
                        
                        // HG8145V specific handling for Invalid parameter
                        if ($cwmpFaultCode == '9005' && $this->modelHint == 'HG8145V') {
                            error_log("TR069: Invalid parameter fault (9005) for HG8145V - Moving to next step in discovery");
                            file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Invalid parameter (9005) for HG8145V - Trying next WiFi parameter\n", FILE_APPEND);
                            
                            // Try a different parameter on next empty POST
                            $this->hg8145vDiscoveryStep++;
                            if ($this->hg8145vDiscoveryStep > 5) {
                                $this->hg8145vDiscoveryStep = 1;
                            }
                            
                            // Don't send any response now - wait for next empty POST
                            $this->soapResponse = null;
                            return;
                        }
                        
                        // For other faults, don't send a reply
                        $this->soapResponse = null;
                        return;
                    }
                }
                
                error_log("TR069: SOAP Fault received - Code: {$faultCode}, String: {$faultString}");
                
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
                    error_log("TR069: Found request type: " . $requestName);
                }
            }
            
            // Try alternative detection for response messages
            if (!$foundRequestType && $this->isHuaweiDevice) {
                // Check body XML for common response types
                $bodyXml = $body->asXML();
                if (strpos($bodyXml, 'GetParameterValuesResponse') !== false) {
                    $requestName = 'GetParameterValuesResponse';
                    $foundRequestType = true;
                    error_log("TR069: Detected GetParameterValuesResponse via string search");
                } else if (strpos($bodyXml, 'GetParameterNamesResponse') !== false) {
                    $requestName = 'GetParameterNamesResponse';
                    $foundRequestType = true;
                    error_log("TR069: Detected GetParameterNamesResponse via string search");
                }
                
                // Check all namespaces for response types
                if (!$foundRequestType) {
                    foreach ($namespace as $prefix => $uri) {
                        $testRequest = $body->children($uri);
                        if (count($testRequest) > 0) {
                            $testName = $testRequest->getName();
                            if ($testName == 'GetParameterValuesResponse' || $testName == 'GetParameterNamesResponse') {
                                $request = $testRequest;
                                $requestName = $testName;
                                $foundRequestType = true;
                                error_log("TR069: Found {$requestName} in namespace: " . $uri);
                                break;
                            }
                        }
                    }
                }
            }

            if (!$foundRequestType) {
                // If we have a session, try to continue despite unknown request type
                if (!empty($this->sessionId)) {
                    error_log("TR069: Unknown request type, but session exists. Continuing.");
                    $this->soapResponse = null;
                    return;
                }
                
                throw new Exception("Unknown request type in SOAP body");
            }

            file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Processing: " . $requestName . "\n", FILE_APPEND);

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
                    error_log("TR069: Unknown request type: " . $requestName);
                    // For Huawei devices with a session, don't throw exception
                    if ($this->isHuaweiDevice && !empty($this->sessionId)) {
                        $this->soapResponse = null;
                        return;
                    }
                    throw new Exception("Unknown request type: $requestName");
            }
        } catch (Exception $e) {
            error_log("TR069: Error in processRequest: " . $e->getMessage());
            file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Error in processRequest: " . $e->getMessage() . "\n", FILE_APPEND);
            throw $e;
        }
    }

    // Handle GetParameterNamesResponse - focus on WiFi parameters
    private function handleGetParameterNamesResponse($request, $rawXml = '') {
        try {
            error_log("TR069: Processing GetParameterNamesResponse");
            file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Processing GetParameterNamesResponse\n", FILE_APPEND);
            
            // Extract parameter names from the response
            $parameterList = [];
            $detectedWifiInterfaces = [];
            $detectedSecurityParams = [];
            
            // Create a new XML parser for parameter extraction
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
                    
                    error_log("TR069: Discovered parameter: " . $paramName);
                    file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Discovered: " . $paramName . "\n", FILE_APPEND);
                    
                    // Detect WiFi interfaces
                    if (preg_match('/WLANConfiguration\.(\d+)$/', $paramName, $matches)) {
                        $detectedWifiInterfaces[] = $matches[1];
                    }
                    
                    // Try to identify WiFi security parameter names
                    if (stripos($paramName, 'KeyPassphrase') !== false || 
                        stripos($paramName, 'PreSharedKey') !== false || 
                        stripos($paramName, 'WPAKey') !== false || 
                        stripos($paramName, 'X_HW_') !== false ||
                        stripos($paramName, 'SSID') !== false) {
                        $detectedSecurityParams[] = $paramName;
                    }
                }
            }
            
            // Save discovered parameters for later use
            $this->discoveredParameters = array_merge($this->discoveredParameters, $parameterList);
            
            // For HG8145V, handle the discovery response based on the current step
            if ($this->modelHint == 'HG8145V') {
                // If we found WLAN interfaces, request the SSID values
                if (!empty($detectedWifiInterfaces)) {
                    $ssidParams = [];
                    foreach ($detectedWifiInterfaces as $ifaceNum) {
                        $ssidParams[] = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$ifaceNum}.SSID";
                    }
                    
                    file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Found WiFi interfaces: " . implode(", ", $detectedWifiInterfaces) . "\n", FILE_APPEND);
                    
                    $this->soapResponse = $this->responseGenerator->createCustomGetParameterValuesRequest(
                        $this->sessionId,
                        $ssidParams
                    );
                    return;
                }
                
                // If we found security parameters, try to request their values
                if (!empty($detectedSecurityParams)) {
                    file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Found security parameters: " . implode(", ", $detectedSecurityParams) . "\n", FILE_APPEND);
                    
                    $this->soapResponse = $this->responseGenerator->createCustomGetParameterValuesRequest(
                        $this->sessionId,
                        $detectedSecurityParams
                    );
                    return;
                }
                
                // Move to next discovery step
                $this->hg8145vDiscoveryStep++;
                if ($this->hg8145vDiscoveryStep > 5) {
                    $this->hg8145vDiscoveryStep = 1;
                }
                
                $this->soapResponse = $this->responseGenerator->createHG8145VWifiDiscoverySequence(
                    $this->sessionId, 
                    $this->hg8145vDiscoveryStep
                );
                return;
            }
            
            // For other devices, handle general discovery
            if (!empty($detectedWifiInterfaces)) {
                // Request SSID values for discovered interfaces
                $ssidParams = [];
                foreach ($detectedWifiInterfaces as $ifaceNum) {
                    $ssidParams[] = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$ifaceNum}.SSID";
                }
                
                $this->soapResponse = $this->responseGenerator->createCustomGetParameterValuesRequest(
                    $this->sessionId,
                    $ssidParams
                );
                return;
            }
            
            // Continue with general parameter discovery
            $this->soapResponse = $this->responseGenerator->createGetParameterValuesRequest($this->sessionId);
            
        } catch (Exception $e) {
            error_log("TR069: Error in handleGetParameterNamesResponse: " . $e->getMessage());
            file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Error in handleGetParameterNamesResponse: " . $e->getMessage() . "\n", FILE_APPEND);
            $this->soapResponse = null;
        }
    }
    
    private function handleGetParameterValuesResponse($request, $rawXml = '') {
        try {
            error_log("TR069: Processing GetParameterValuesResponse");
            file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Processing GetParameterValuesResponse\n", FILE_APPEND);
            
            // Extract parameter values from the response
            $paramValues = [];
            
            // Create a new XML parser for parameter extraction
            libxml_use_internal_errors(true);
            $xmlDoc = new DOMDocument();
            $xmlDoc->loadXML($rawXml);
            $xpath = new DOMXPath($xmlDoc);
            
            // Register namespaces
            foreach ($xmlDoc->documentElement->getAttributesNS() as $attrName => $attrNode) {
                if (preg_match('/^xmlns:(.+)$/', $attrName, $matches)) {
                    $prefix = $matches[1];
                    $uri = $attrNode->nodeValue;
                    $xpath->registerNamespace($prefix, $uri);
                }
            }
            
            // Try multiple XPath expressions to find parameter values
            $xpathExpressions = [
                '//ParameterValueStruct',
                '//cwmp:ParameterValueStruct',
                '//ParameterList/ParameterValueStruct',
                '//cwmp:ParameterList/cwmp:ParameterValueStruct'
            ];
            
            $paramValueNodes = null;
            foreach ($xpathExpressions as $expr) {
                $nodes = $xpath->query($expr);
                if ($nodes && $nodes->length > 0) {
                    $paramValueNodes = $nodes;
                    break;
                }
            }
            
            if ($paramValueNodes) {
                foreach ($paramValueNodes as $paramValueNode) {
                    $nameNode = $xpath->query('.//Name', $paramValueNode)->item(0) 
                              ?? $xpath->query('.//cwmp:Name', $paramValueNode)->item(0);
                              
                    $valueNode = $xpath->query('.//Value', $paramValueNode)->item(0) 
                               ?? $xpath->query('.//cwmp:Value', $paramValueNode)->item(0);
                    
                    if ($nameNode && $valueNode) {
                        $name = $nameNode->textContent;
                        $value = $valueNode->textContent;
                        $paramValues[$name] = $value;
                        
                        error_log("TR069: Parameter value - {$name}: {$value}");
                        file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Parameter: {$name} = {$value}\n", FILE_APPEND);
                        
                        // Store WiFi-related parameters in the device database
                        if (stripos($name, 'SSID') !== false || 
                            stripos($name, 'KeyPassphrase') !== false ||
                            stripos($name, 'WPAKey') !== false || 
                            stripos($name, 'X_HW_') !== false) {
                            
                            // Log discovery of WiFi parameter
                            file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " !!! FOUND WIFI PARAMETER !!! - {$name}: {$value}\n", FILE_APPEND);
                            
                            // Store in database if we have a device ID
                            if (!empty($this->deviceId)) {
                                $this->deviceManager->storeDeviceParameter($this->deviceId, $name, $value);
                            }
                        }
                    }
                }
            } else {
                error_log("TR069: No parameter values found in response");
                file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " No parameter values found in response\n", FILE_APPEND);
            }
            
            // For HG8145V, continue with the discovery sequence
            if ($this->modelHint == 'HG8145V') {
                $this->hg8145vDiscoveryStep++;
                if ($this->hg8145vDiscoveryStep > 5) {
                    $this->hg8145vDiscoveryStep = 1;
                }
                
                // Don't send a response yet - wait for next empty POST
                $this->soapResponse = null;
                return;
            }
            
            // For other devices, don't send a response
            $this->soapResponse = null;
            
        } catch (Exception $e) {
            error_log("TR069: Error in handleGetParameterValuesResponse: " . $e->getMessage());
            file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Error in handleGetParameterValuesResponse: " . $e->getMessage() . "\n", FILE_APPEND);
            $this->soapResponse = null;
        }
    }
    
    private function handleInform($request, $rawXml = '') {
        // Basic implementation - just extract essentials
        try {
            // Use the Huawei parser for Huawei devices
            if ($this->isHuaweiDevice) {
                $deviceData = $this->huaweiInformParser->parseInform($request);
            } else {
                $deviceData = $this->informParser->parseInform($request);
            }
            
            if (!empty($deviceData)) {
                $this->serialNumber = $deviceData['serialNumber'];
                
                // Log device details - ensuring keys exist
                $modelName = isset($deviceData['modelName']) ? $deviceData['modelName'] : 'Unknown';
                error_log("TR069: Device info - Model: " . $modelName . ", Serial: " . $deviceData['serialNumber']);
                file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Device info - Model: " . $modelName . ", Serial: " . $deviceData['serialNumber'] . "\n", FILE_APPEND);
                
                // Check if model is HG8145V
                if (!empty($modelName) && stripos($modelName, 'HG8145V') !== false) {
                    $this->modelHint = 'HG8145V';
                    error_log("TR069: Confirmed HG8145V model from Inform");
                    file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Confirmed HG8145V model\n", FILE_APPEND);
                }
                
                // Create or update the device record
                $manufacturer = isset($deviceData['manufacturer']) ? $deviceData['manufacturer'] : 'Unknown';
                $hardwareVersion = isset($deviceData['hardwareVersion']) ? $deviceData['hardwareVersion'] : '';
                $softwareVersion = isset($deviceData['softwareVersion']) ? $deviceData['softwareVersion'] : '';
                
                $this->deviceId = $this->deviceManager->updateOrCreateDevice(
                    $deviceData['serialNumber'],
                    $manufacturer,
                    $modelName,
                    $hardwareVersion,
                    $softwareVersion
                );
                
                // Create or update the session
                if (empty($this->sessionId)) {
                    $this->sessionId = (string)$request->Header->ID ?? bin2hex(random_bytes(16));
                }
                
                $this->sessionManager->updateOrCreateSession($deviceData['serialNumber'], $this->sessionId);
                
                // For HG8145V, set flag to send WiFi discovery on next empty POST
                if ($this->modelHint == 'HG8145V') {
                    $this->shouldSendGetParameterValues = true;
                    error_log("TR069: Set flag for WiFi discovery on next empty POST, session: " . $this->sessionId);
                    file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Will send WiFi discovery on next empty POST\n", FILE_APPEND);
                }
                
                // Create the InformResponse
                $this->soapResponse = $this->responseGenerator->createResponse($this->sessionId);
                error_log("TR069: Inform handled successfully. Device ID: " . $this->deviceId . ", Session ID: " . $this->sessionId);
            } else {
                throw new Exception("Failed to parse Inform message");
            }
        } catch (Exception $e) {
            error_log("TR069: Error in handleInform: " . $e->getMessage());
            file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Error in handleInform: " . $e->getMessage() . "\n", FILE_APPEND);
            throw $e;
        }
    }
    
    private function handleEmptyRequest() {
        error_log("TR069: Handling empty request");
        
        // No soap response by default
        $this->soapResponse = null;
        
        // If we've detected a Huawei device and we're in a session, send parameter discovery
        if ($this->isHuaweiDevice && !empty($this->sessionId)) {
            if ($this->modelHint == 'HG8145V') {
                error_log("TR069: Sending HG8145V WiFi discovery sequence, step " . $this->hg8145vDiscoveryStep);
                file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Sending HG8145V WiFi discovery step " . $this->hg8145vDiscoveryStep . "\n", FILE_APPEND);
                
                $this->soapResponse = $this->responseGenerator->createHG8145VWifiDiscoverySequence(
                    $this->sessionId, 
                    $this->hg8145vDiscoveryStep
                );
                
                // Don't increment step here - that's done when processing the response
            } else {
                // For other Huawei devices, start with basic discovery
                error_log("TR069: Sending WiFi discovery request");
                file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Sending WiFi discovery request\n", FILE_APPEND);
                
                $this->soapResponse = $this->responseGenerator->createWifiDiscoveryRequest($this->sessionId);
            }
        }
    }
    
    private function sendResponse() {
        if (!empty($this->soapResponse)) {
            error_log("TR069: Sending SOAP response, length: " . strlen($this->soapResponse));
            
            // Set appropriate headers
            header('Content-Type: text/xml; charset="utf-8"');
            header('Content-Length: ' . strlen($this->soapResponse));
            header('SOAPAction: ""');
            
            // Send the SOAP response
            echo $this->soapResponse;
        } else {
            error_log("TR069: No response to send (empty response)");
            header('Content-Length: 0');
        }
    }
}
