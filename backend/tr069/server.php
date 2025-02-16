
<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/session_manager.php';
require_once __DIR__ . '/device_manager.php';

class TR069Server {
    private $db;
    private $sessionManager;
    private $deviceManager;
    private $soapResponse;
    private $deviceId;
    private $sessionId;

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->sessionManager = new SessionManager($this->db);
        $this->deviceManager = new DeviceManager($this->db);
        $this->soapResponse = null;
        $this->deviceId = null;
        $this->sessionId = null;
    }

    public function handleRequest() {
        // Enable error logging
        error_log("TR-069 Request received: " . date('Y-m-d H:i:s'));
        
        // Basic security headers
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        
        $method = $_SERVER['REQUEST_METHOD'];
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        
        // Handle HTTP authentication
        if (!isset($_SERVER['PHP_AUTH_USER'])) {
            header('WWW-Authenticate: Basic realm="TR-069 ACS"');
            header('HTTP/1.1 401 Unauthorized');
            exit('Authentication required');
        }

        if ($method !== 'POST') {
            if ($method === 'GET') {
                // Handle connection request without body
                $this->handleEmptyRequest();
                return;
            }
            header('HTTP/1.1 405 Method Not Allowed');
            exit('Invalid request method');
        }

        $rawPost = file_get_contents('php://input');
        error_log("Raw request: " . $rawPost);

        if (empty($rawPost)) {
            $this->handleEmptyRequest();
            return;
        }

        try {
            $xml = new SimpleXMLElement($rawPost);
            $this->processRequest($xml);
        } catch (Exception $e) {
            error_log("TR-069 Error: " . $e->getMessage());
            header('HTTP/1.1 500 Internal Server Error');
            exit('Error processing request: ' . $e->getMessage());
        }

        $this->sendResponse();
    }

    private function handleEmptyRequest() {
        // Handle empty POST request (CPE establishing connection)
        header('Content-Type: text/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"><SOAP-ENV:Body></SOAP-ENV:Body></SOAP-ENV:Envelope>';
    }

    private function processRequest($xml) {
        $namespace = $xml->getNamespaces(true);
        $soapEnv = $namespace['soapenv'];
        $cwmp = $namespace['cwmp'];

        // Extract session ID from header if present
        $header = $xml->children($soapEnv)->Header;
        if (isset($header->children($cwmp)->ID)) {
            $this->sessionId = (string)$header->children($cwmp)->ID;
        }

        $body = $xml->children($soapEnv)->Body;
        $request = $body->children($cwmp);
        $requestName = $request->getName();

        error_log("Processing request type: " . $requestName);

        switch ($requestName) {
            case 'Inform':
                $this->handleInform($request);
                break;
            case 'TransferComplete':
                $this->handleTransferComplete($request);
                break;
            case 'GetRPCMethods':
                $this->handleGetRPCMethods();
                break;
            case 'GetParameterValuesResponse':
                $this->handleGetParameterValuesResponse($request);
                break;
            case 'SetParameterValuesResponse':
                $this->handleSetParameterValuesResponse($request);
                break;
            case 'DownloadResponse':
                $this->handleDownloadResponse($request);
                break;
            case 'RebootResponse':
                $this->handleRebootResponse($request);
                break;
            default:
                throw new Exception("Unknown request type: $requestName");
        }
    }

    private function handleInform($request) {
        $deviceId = $request->DeviceId;
        $events = $request->Event->EventStruct;
        $parameters = $request->ParameterList->ParameterValueStruct;

        // Process device information
        $deviceInfo = [
            'manufacturer' => (string)$deviceId->Manufacturer,
            'oui' => (string)$deviceId->OUI,
            'productClass' => (string)$deviceId->ProductClass,
            'serialNumber' => (string)$deviceId->SerialNumber,
            'softwareVersion' => '',
            'hardwareVersion' => ''
        ];

        // Process parameters
        foreach ($parameters as $param) {
            $name = (string)$param->Name;
            $value = (string)$param->Value;
            
            if (strpos($name, 'DeviceInfo.SoftwareVersion') !== false) {
                $deviceInfo['softwareVersion'] = $value;
            } elseif (strpos($name, 'DeviceInfo.HardwareVersion') !== false) {
                $deviceInfo['hardwareVersion'] = $value;
            }
        }

        // Process events
        foreach ($events as $event) {
            $eventCode = (string)$event->EventCode;
            // Log the event
            error_log("Device event received: " . $eventCode);
            // Store event in database
            $this->deviceManager->logEvent($deviceInfo['serialNumber'], $eventCode);
        }

        // Update device in database
        $this->deviceId = $this->deviceManager->updateDevice($deviceInfo);

        // Create new session
        $this->sessionId = $this->sessionManager->createSession($deviceInfo['serialNumber']);

        // Prepare Inform response
        $this->soapResponse = $this->createInformResponse();
    }

    private function handleGetRPCMethods() {
        $methods = [
            'GetRPCMethods',
            'SetParameterValues',
            'GetParameterValues',
            'GetParameterNames',
            'SetParameterAttributes',
            'GetParameterAttributes',
            'AddObject',
            'DeleteObject',
            'Reboot',
            'Download',
            'Upload'
        ];

        $this->soapResponse = $this->createGetRPCMethodsResponse($methods);
    }

    private function createGetRPCMethodsResponse($methods) {
        $response = '<?xml version="1.0" encoding="UTF-8"?>
        <SOAP-ENV:Envelope 
            xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" 
            xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
            <SOAP-ENV:Header>
                <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $this->sessionId . '</cwmp:ID>
            </SOAP-ENV:Header>
            <SOAP-ENV:Body>
                <cwmp:GetRPCMethodsResponse>
                    <MethodList>';

        foreach ($methods as $method) {
            $response .= '<string>' . $method . '</string>';
        }

        $response .= '</MethodList>
                </cwmp:GetRPCMethodsResponse>
            </SOAP-ENV:Body>
        </SOAP-ENV:Envelope>';

        return $response;
    }

    private function createInformResponse() {
        return '<?xml version="1.0" encoding="UTF-8"?>
        <SOAP-ENV:Envelope 
            xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" 
            xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
            <SOAP-ENV:Header>
                <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $this->sessionId . '</cwmp:ID>
            </SOAP-ENV:Header>
            <SOAP-ENV:Body>
                <cwmp:InformResponse>
                    <MaxEnvelopes>1</MaxEnvelopes>
                </cwmp:InformResponse>
            </SOAP-ENV:Body>
        </SOAP-ENV:Envelope>';
    }

    private function createGetParameterValues($parameters) {
        return '<?xml version="1.0" encoding="UTF-8"?>
        <SOAP-ENV:Envelope 
            xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" 
            xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
            <SOAP-ENV:Header>
                <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $this->sessionId . '</cwmp:ID>
            </SOAP-ENV:Header>
            <SOAP-ENV:Body>
                <cwmp:GetParameterValues>
                    <ParameterNames>
                        <string>' . implode('</string><string>', $parameters) . '</string>
                    </ParameterNames>
                </cwmp:GetParameterValues>
            </SOAP-ENV:Body>
        </SOAP-ENV:Envelope>';
    }

    private function sendResponse() {
        if ($this->soapResponse) {
            header('Content-Type: text/xml; charset=utf-8');
            echo $this->soapResponse;
            error_log("Response sent: " . $this->soapResponse);
        }
    }
}
