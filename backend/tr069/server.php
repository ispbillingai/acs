
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
        }
        
        if (empty($rawPost)) {
            // If empty POST, send GetParameterValues request if flag is set
            if (isset($_SERVER['HTTP_COOKIE']) && strpos($_SERVER['HTTP_COOKIE'], 'session_id=') !== false) {
                preg_match('/session_id=([^;]+)/', $_SERVER['HTTP_COOKIE'], $matches);
                $this->sessionId = $matches[1];
                error_log("TR069Server: Session ID from cookie: " . $this->sessionId);
                
                if ($this->isHuaweiDevice) {
                    error_log("TR069Server: Sending Huawei GetParameterValues request");
                    $this->soapResponse = $this->responseGenerator->createHuaweiGetParameterValuesRequest($this->sessionId);
                } else {
                    error_log("TR069Server: Sending standard GetParameterValues request");
                    $this->soapResponse = $this->responseGenerator->createGetParameterValuesRequest($this->sessionId);
                }
            } else {
                error_log("TR069Server: No session ID found, handling as empty request");
                $this->handleEmptyRequest();
            }
            return;
        }

        try {
            libxml_use_internal_errors(true);
            $xml = new SimpleXMLElement($rawPost);
            $this->processRequest($xml, $rawPost);
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
            
            header('HTTP/1.1 500 Internal Server Error');
            echo "Internal Server Error: " . $e->getMessage();
            exit;
        }

        $this->sendResponse();
    }

    private function handleEmptyRequest() {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            error_log("TR069Server: Handling GET request with HTML response");
            header('Content-Type: text/html; charset=utf-8');
            echo "TR-069 ACS Server";
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            error_log("TR069Server: Handling empty POST request with 204 response");
            header('HTTP/1.1 204 No Content');
            return;
        }
        
        error_log("TR069Server: Received unsupported method: " . $_SERVER['REQUEST_METHOD']);
        header('HTTP/1.1 405 Method Not Allowed');
        header('Allow: GET, POST');
        exit('Method Not Allowed');
    }

    private function processRequest($xml, $rawXml = '') {
        $namespace = $xml->getNamespaces(true);
        $soapEnv = isset($namespace['soapenv']) ? $namespace['soapenv'] : 'http://schemas.xmlsoap.org/soap/envelope/';
        $cwmp = isset($namespace['cwmp']) ? $namespace['cwmp'] : 'urn:dslforum-org:cwmp-1-0';

        error_log("TR069Server: Detected namespaces - SOAP: {$soapEnv}, CWMP: {$cwmp}");

        $body = $xml->children($soapEnv)->Body;
        if (empty($body)) {
            error_log("TR069Server: Empty SOAP Body received");
            throw new Exception("Empty SOAP Body");
        }

        $request = $body->children($cwmp);
        $requestName = $request->getName();

        error_log("TR069Server: Processing request type: " . $requestName);

        switch ($requestName) {
            case 'Inform':
                $this->handleInform($request, $rawXml);
                // Set flag to send GetParameterValues on the next empty POST
                if ($this->isHuaweiDevice) {
                    error_log("TR069Server: Setting flag to send Huawei GetParameterValues on next request");
                    $this->shouldSendGetParameterValues = true;
                    // Set a cookie to identify this session later
                    setcookie('session_id', $this->sessionId, 0, '/');
                }
                break;
            case 'GetParameterValuesResponse':
                $this->handleGetParameterValuesResponse($request, $rawXml);
                break;
            default:
                error_log("TR069Server: Unknown request type: " . $requestName);
                throw new Exception("Unknown request type: $requestName");
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
            $this->sessionId = $this->sessionManager->createSession($deviceInfo['serialNumber']);
            $this->soapResponse = $this->responseGenerator->createResponse($this->sessionId);
            
            error_log("TR069Server: Inform handled successfully. Device ID: " . $this->deviceId . ", Session ID: " . $this->sessionId);
        } catch (Exception $e) {
            error_log("TR069Server: Error in handleInform: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    private function handleGetParameterValuesResponse($request, $rawXml = '') {
        try {
            error_log("TR069Server: Processing GetParameterValuesResponse");
            
            // Log the raw XML for debugging
            if ($this->isHuaweiDevice) {
                error_log("=== HUAWEI GetParameterValuesResponse XML START ===");
                error_log($request->asXML());
                error_log("=== HUAWEI GetParameterValuesResponse XML END ===");
            }
            
            // Process the parameters in the response
            $parameters = $request->ParameterList->ParameterValueStruct;
            if (empty($parameters)) {
                error_log("TR069Server: No parameters found in GetParameterValuesResponse");
                return;
            }
            
            error_log("TR069Server: Found " . count($parameters) . " parameters in GetParameterValuesResponse");
            
            // Extract parameters of interest
            $deviceInfo = [];
            foreach ($parameters as $param) {
                $name = (string)$param->Name;
                $value = (string)$param->Value;
                
                error_log("TR069Server: Parameter in GetParameterValuesResponse - " . $name . " = " . $value);
                
                // Extract key parameters that we're interested in
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
                } elseif (stripos($name, 'UpTime') !== false) {
                    $deviceInfo['uptime'] = (int)$value;
                }
            }
            
            // Log what we found
            error_log("TR069Server: GetParameterValuesResponse extracted data: " . json_encode($deviceInfo));
            
            // Update device with new information
            if (!empty($deviceInfo) && isset($deviceInfo['serialNumber'])) {
                $deviceInfo['status'] = 'online';
                $deviceId = $this->deviceManager->updateDevice($deviceInfo);
                error_log("TR069Server: Updated device ID: " . $deviceId . " with GetParameterValuesResponse data");
            }
            
            // For Huawei devices, we don't need to send a response after GetParameterValuesResponse
            $this->soapResponse = null;
            
        } catch (Exception $e) {
            error_log("TR069Server: Error in handleGetParameterValuesResponse: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
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
            } else {
                error_log("TR069Server: Sending response, length: " . strlen($this->soapResponse));
            }
            
            echo $this->soapResponse;
        } else {
            error_log("TR069Server: No response generated to send");
        }
    }
}
