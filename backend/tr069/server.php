
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
    
    // Default credentials - you should change these
    private $validUsername = "admin";
    private $validPassword = "admin";

    public function __construct() {
        error_log("TR069Server: Constructing new server instance");
        $database = new Database();
        $this->db = $database->getConnection();
        $this->sessionManager = new SessionManager($this->db);
        $this->deviceManager = new DeviceManager($this->db);
        error_log("TR069Server: Construction completed");
    }

    public function handleRequest() {
        error_log("TR069Server: Beginning request handling");
        error_log("TR-069 Request received: " . date('Y-m-d H:i:s'));
        
        $method = $_SERVER['REQUEST_METHOD'];
        error_log("TR069Server: Request method: " . $method);
        
        if (!$this->authenticateRequest()) {
            error_log("TR069Server: Authentication failed");
            header('WWW-Authenticate: Basic realm="TR-069 ACS"');
            header('HTTP/1.1 401 Unauthorized');
            exit('Authentication required');
        }

        error_log("TR069Server: Authentication successful");
        
        $rawPost = file_get_contents('php://input');
        if (empty($rawPost)) {
            error_log("TR069Server: Empty POST data received");
            $this->handleEmptyRequest();
            return;
        }

        error_log("TR069Server: Processing POST data of length: " . strlen($rawPost));
        error_log("TR069Server: POST data preview: " . substr($rawPost, 0, 500));

        try {
            $xml = new SimpleXMLElement($rawPost);
            error_log("TR069Server: XML parsed successfully");
            $this->processRequest($xml);
        } catch (Exception $e) {
            error_log("TR069Server XML Error: " . $e->getMessage());
            error_log("TR069Server Error trace: " . $e->getTraceAsString());
            header('HTTP/1.1 500 Internal Server Error');
            exit('Error processing request');
        }

        $this->sendResponse();
    }

    private function handleEmptyRequest() {
        error_log("TR069Server: Handling empty request");
        
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            error_log("TR069Server: Handling GET request - sending empty response");
            header('Content-Type: text/html; charset=utf-8');
            echo "TR-069 ACS Server";
            return;
        }
        
        // For empty POST requests, send an empty 204 response
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            error_log("TR069Server: Handling empty POST request - sending 204 response");
            header('HTTP/1.1 204 No Content');
            return;
        }
        
        // For any other method, send 405 Method Not Allowed
        error_log("TR069Server: Invalid method " . $_SERVER['REQUEST_METHOD']);
        header('HTTP/1.1 405 Method Not Allowed');
        header('Allow: GET, POST');
        exit('Method Not Allowed');
    }

    private function authenticateRequest() {
        error_log("TR069Server: Starting authentication");
        
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
            error_log("TR069Server: Missing authentication credentials");
            return false;
        }

        $username = $_SERVER['PHP_AUTH_USER'];
        $password = $_SERVER['PHP_AUTH_PW'];
        
        error_log("TR069Server: Attempting authentication for user: " . $username);
        
        $result = ($username === $this->validUsername && $password === $this->validPassword);
        error_log("TR069Server: Authentication result: " . ($result ? "success" : "failure"));
        
        return $result;
    }

    private function processRequest($xml) {
        $namespace = $xml->getNamespaces(true);
        $soapEnv = $namespace['soapenv'];
        $cwmp = $namespace['cwmp'];

        $body = $xml->children($soapEnv)->Body;
        $request = $body->children($cwmp);
        $requestName = $request->getName();

        switch ($requestName) {
            case 'Inform':
                $this->handleInform($request);
                break;
            case 'GetParameterValuesResponse':
                $this->handleGetParameterValuesResponse($request);
                break;
            case 'SetParameterValuesResponse':
                $this->handleSetParameterValuesResponse($request);
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
        $parameters = $request->ParameterList->ParameterValueStruct;

        $deviceInfo = [
            'manufacturer' => (string)$deviceId->Manufacturer,
            'serialNumber' => (string)$deviceId->SerialNumber,
            'modelName' => (string)$deviceId->ProductClass,
            'status' => 'online'
        ];

        // Process parameters to get SSID and connected clients
        foreach ($parameters as $param) {
            $name = (string)$param->Name;
            $value = (string)$param->Value;
            
            if (strpos($name, 'WLANConfiguration.SSID') !== false) {
                $deviceInfo['ssid'] = $value;
            } elseif (strpos($name, 'AssociatedDeviceNumberOfEntries') !== false) {
                $deviceInfo['connected_clients'] = (int)$value;
            }
        }

        $this->deviceId = $this->deviceManager->updateDevice($deviceInfo);
        $this->sessionId = $this->sessionManager->createSession($deviceInfo['serialNumber']);
        $this->soapResponse = $this->createInformResponse();
    }

    private function createWifiConfigRequest() {
        return $this->createGetParameterValues([
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDeviceNumberOfEntries'
        ]);
    }

    private function createSetSSIDRequest($ssid, $password) {
        // Create SOAP request to change SSID and password
        // Implementation here
    }

    private function createRebootRequest() {
        return '<?xml version="1.0" encoding="UTF-8"?>
        <SOAP-ENV:Envelope 
            xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" 
            xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
            <SOAP-ENV:Header>
                <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $this->sessionId . '</cwmp:ID>
            </SOAP-ENV:Header>
            <SOAP-ENV:Body>
                <cwmp:Reboot>
                    <CommandKey>Reboot_' . time() . '</CommandKey>
                </cwmp:Reboot>
            </SOAP-ENV:Body>
        </SOAP-ENV:Envelope>';
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
