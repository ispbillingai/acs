
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

    private function handleEmptyRequest() {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            error_log("TR069Server: Handling GET request with HTML response");
            header('Content-Type: text/html; charset=utf-8');
            echo "TR-069 ACS Server";
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // For Huawei devices, always send GetParameterValues on empty POST
            if ($this->isHuaweiDevice) {
                // Use a generated session ID if we don't have one
                if (empty($this->sessionId)) {
                    $this->sessionId = bin2hex(random_bytes(16));
                    error_log("TR069Server: Generated new session ID for empty POST in handleEmptyRequest: " . $this->sessionId);
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Generated new session ID in handleEmptyRequest: " . $this->sessionId . "\n", FILE_APPEND);
                }
                
                error_log("TR069Server: Sending Huawei GetParameterValues on empty POST with session ID: " . $this->sessionId);
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Sending GetParameterValues from handleEmptyRequest\n", FILE_APPEND);
                $this->soapResponse = $this->responseGenerator->createHuaweiGetParameterValuesRequest($this->sessionId);
                $this->sendResponse();
                return;
            }
            
            error_log("TR069Server: Handling empty POST request with 204 response for non-Huawei device");
            header('HTTP/1.1 204 No Content');
            return;
        }
        
        error_log("TR069Server: Received unsupported method: " . $_SERVER['REQUEST_METHOD']);
        header('HTTP/1.1 405 Method Not Allowed');
        header('Allow: GET, POST');
        exit('Method Not Allowed');
    }

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
            
            // If not found, try to look for GetParameterValuesResponse differently
            if (!$foundRequestType && $this->isHuaweiDevice) {
                // Huawei devices might use a different namespace or structure for responses
                // Try direct detection of GetParameterValuesResponse
                $bodyXml = $body->asXML();
                if (strpos($bodyXml, 'GetParameterValuesResponse') !== false) {
                    $requestName = 'GetParameterValuesResponse';
                    $foundRequestType = true;
                    error_log("TR069Server: Detected GetParameterValuesResponse in body via string search");
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Detected GetParameterValuesResponse via string search\n", FILE_APPEND);
                }
                
                // Try all potential namespaces for GetParameterValuesResponse
                if (!$foundRequestType) {
                    foreach ($namespace as $prefix => $uri) {
                        $testRequest = $body->children($uri);
                        if (count($testRequest) > 0) {
                            $testName = $testRequest->getName();
                            if ($testName == 'GetParameterValuesResponse') {
                                $request = $testRequest;
                                $requestName = $testName;
                                $foundRequestType = true;
                                error_log("TR069Server: Found GetParameterValuesResponse in namespace: " . $uri);
                                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Found GetParameterValuesResponse in namespace: " . $uri . "\n", FILE_APPEND);
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
            
            // Log the raw XML for debugging
            if ($this->isHuaweiDevice) {
                error_log("=== HUAWEI GetParameterValuesResponse XML START ===");
                error_log($rawXml);
                error_log("=== HUAWEI GetParameterValuesResponse XML END ===");
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Raw GetParameterValuesResponse: " . $rawXml . "\n", FILE_APPEND);
            }
            
            // Process the parameters in the response
            $parameterList = null;
            $parameters = [];
            
            // Try to find parameter list in various ways
            if (isset($request->ParameterList) && isset($request->ParameterList->ParameterValueStruct)) {
                $parameters = $request->ParameterList->ParameterValueStruct;
            } else {
                // Try alternative ways to get parameters based on XML structure
                $bodyXml = $rawXml;
                
                // Create a new XML parser just for the parameter extraction
                libxml_use_internal_errors(true);
                $xmlDoc = new DOMDocument();
                $xmlDoc->loadXML($bodyXml);
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
                    '//ParameterValueStruct',
                    '//cwmp:ParameterValueStruct',
                    '//ParameterList/ParameterValueStruct',
                    '//cwmp:ParameterList/cwmp:ParameterValueStruct',
                    '//SOAP-ENV:Body//ParameterValueStruct',
                ];
                
                foreach ($xpathExpressions as $expr) {
                    $nodes = $xpath->query($expr);
                    if ($nodes && $nodes->length > 0) {
                        $paramNodes = $nodes;
                        break;
                    }
                }
                
                if (count($paramNodes) > 0) {
                    // Manually extract parameter name-value pairs
                    foreach ($paramNodes as $paramNode) {
                        $nameNode = $xpath->query('.//Name', $paramNode)->item(0);
                        $valueNode = $xpath->query('.//Value', $paramNode)->item(0);
                        
                        if ($nameNode && $valueNode) {
                            $name = $nameNode->textContent;
                            $value = $valueNode->textContent;
                            
                            error_log("TR069Server: Extracted parameter via XPath - " . $name . " = " . $value);
                            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Extracted parameter via XPath: " . $name . " = " . $value . "\n", FILE_APPEND);
                            
                            // Add to device info based on parameter name
                            $this->processParameterValue($name, $value);
                        }
                    }
                    
                    // Mark that we've processed parameters
                    $parameters = true;
                }
            }
            
            if (empty($parameters)) {
                error_log("TR069Server: No parameters found in GetParameterValuesResponse");
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " No parameters found in GetParameterValuesResponse\n", FILE_APPEND);
                return;
            }
            
            // If we got a SimpleXML object with parameters
            if (is_object($parameters)) {
                error_log("TR069Server: Found " . count($parameters) . " parameters in GetParameterValuesResponse");
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Found " . count($parameters) . " parameters in GetParameterValuesResponse\n", FILE_APPEND);
                
                // Extract parameters of interest
                $deviceInfo = [];
                foreach ($parameters as $param) {
                    $name = (string)$param->Name;
                    $value = (string)$param->Value;
                    
                    error_log("TR069Server: Parameter in GetParameterValuesResponse - " . $name . " = " . $value);
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Parameter: " . $name . " = " . $value . "\n", FILE_APPEND);
                    
                    // Process this parameter value
                    $this->processParameterValue($name, $value);
                }
            }
            
            // For Huawei devices, we don't need to send a response after GetParameterValuesResponse
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
    
    private function processParameterValue($name, $value) {
        // Extract key parameters that we're interested in
        $deviceInfo = [];
        
        if (stripos($name, 'SSID') !== false) {
            if (stripos($name, 'WLANConfiguration.1') !== false) {
                $deviceInfo['ssid1'] = $value;
                $deviceInfo['ssid'] = $value; // Main SSID
            } elseif (stripos($name, 'WLANConfiguration.2') !== false) {
                $deviceInfo['ssid2'] = $value;
            }
        } elseif (stripos($name, 'KeyPassphrase') !== false) {
            if (stripos($name, 'WLANConfiguration.1') !== false) {
                $deviceInfo['ssidPassword1'] = $value;
                $deviceInfo['ssidPassword'] = $value; // Main password
            } elseif (stripos($name, 'WLANConfiguration.2') !== false) {
                $deviceInfo['ssidPassword2'] = $value;
            }
        } elseif (stripos($name, 'TxPower') !== false) {
            $deviceInfo['ponTxPower'] = $value;
        } elseif (stripos($name, 'RxPower') !== false) {
            $deviceInfo['ponRxPower'] = $value;
        } elseif (stripos($name, 'MACAddress') !== false) {
            $deviceInfo['macAddress'] = $value;
        } elseif (stripos($name, 'ExternalIPAddress') !== false) {
            $deviceInfo['ipAddress'] = $value;
        } elseif (stripos($name, 'SerialNumber') !== false) {
            $deviceInfo['serialNumber'] = $value;
            $this->serialNumber = $value; // Save for session management
        } elseif (stripos($name, 'UpTime') !== false) {
            $deviceInfo['uptime'] = (int)$value;
        }
        
        // If we have extracted any device info, update the device
        if (!empty($deviceInfo)) {
            $deviceInfo['status'] = 'online';
            
            // If we have a serial number, use it to update the device
            if (isset($deviceInfo['serialNumber'])) {
                $deviceId = $this->deviceManager->updateDevice($deviceInfo);
                error_log("TR069Server: Updated device ID: " . $deviceId . " with parameter: " . $name);
                file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Updated device with parameter: " . $name . "\n", FILE_APPEND);
            } 
            // If we don't have a serial number but have a session ID, try to find the device from the session
            else if (!empty($this->sessionId)) {
                $session = $this->sessionManager->validateSession($this->sessionId);
                if ($session) {
                    $deviceInfo['serialNumber'] = $session['device_serial'];
                    $deviceId = $this->deviceManager->updateDevice($deviceInfo);
                    error_log("TR069Server: Updated device using session serial number: " . $deviceInfo['serialNumber']);
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Updated device using session serial: " . $deviceInfo['serialNumber'] . "\n", FILE_APPEND);
                } else {
                    error_log("TR069Server: No valid session found for ID: " . $this->sessionId);
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " No valid session for ID: " . $this->sessionId . "\n", FILE_APPEND);
                }
            }
        }
    }

    private function sendResponse() {
        if ($this->soapResponse) {
            header('Content-Type: text/xml; charset=utf-8');
            
            // Log response before sending
            if ($this->isHuaweiDevice) {
                error_log("TR069Server: Sending response to Huawei device");
                error_log("=== HUAWEI RESPONSE XML START ===");
                error_log($this->soapResponse);
                error_log("=== HUAWEI RESPONSE XML END ===");
                
                // Log to get.log if it's a GetParameterValues request
                if (strpos($this->soapResponse, 'GetParameterValues') !== false) {
                    file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " Sending GetParameterValues: " . $this->soapResponse . "\n", FILE_APPEND);
                }
            } else {
                error_log("TR069Server: Sending response, length: " . strlen($this->soapResponse));
            }
            
            echo $this->soapResponse;
        } else {
            error_log("TR069Server: No response generated to send");
            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " No response generated to send\n", FILE_APPEND);
        }
    }
}
