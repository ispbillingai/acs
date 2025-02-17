
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
    
    private $validUsername = "admin";
    private $validPassword = "admin";

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->sessionManager = new SessionManager($this->db);
        $this->deviceManager = new DeviceManager($this->db);
    }

    public function handleRequest() {
        error_log("TR069Server: Beginning request handling");
        error_log("TR-069 Request received: " . date('Y-m-d H:i:s'));
        
        if (!$this->authenticateRequest()) {
            header('WWW-Authenticate: Basic realm="TR-069 ACS"');
            header('HTTP/1.1 401 Unauthorized');
            exit('Authentication required');
        }

        $rawPost = file_get_contents('php://input');
        
        if (empty($rawPost)) {
            $this->handleEmptyRequest();
            return;
        }

        try {
            $xml = new SimpleXMLElement($rawPost);
            $this->processRequest($xml);
        } catch (Exception $e) {
            error_log("TR069Server Error: " . $e->getMessage());
            header('HTTP/1.1 500 Internal Server Error');
            exit('Error processing request');
        }

        $this->sendResponse();
    }

    private function handleEmptyRequest() {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            header('Content-Type: text/html; charset=utf-8');
            echo "TR-069 ACS Server";
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('HTTP/1.1 204 No Content');
            return;
        }
        
        header('HTTP/1.1 405 Method Not Allowed');
        header('Allow: GET, POST');
        exit('Method Not Allowed');
    }

    private function authenticateRequest() {
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
            return false;
        }

        $username = $_SERVER['PHP_AUTH_USER'];
        $password = $_SERVER['PHP_AUTH_PW'];
        
        return ($username === $this->validUsername && $password === $this->validPassword);
    }

    private function processRequest($xml) {
        $namespace = $xml->getNamespaces(true);
        $soapEnv = $namespace['soapenv'];
        $cwmp = $namespace['cwmp'];
        $xsi = $namespace['xsi'];

        $body = $xml->children($soapEnv)->Body;
        $request = $body->children($cwmp);
        $requestName = $request->getName();

        switch ($requestName) {
            case 'Inform':
                $this->handleInform($request);
                break;
            default:
                throw new Exception("Unknown request type: $requestName");
        }
    }

    private function handleInform($request) {
        try {
            $deviceId = $request->DeviceId;
            $parameterList = $request->ParameterList->ParameterValueStruct ?? [];
            
            // Extract device information
            $deviceInfo = [
                'manufacturer' => (string)$deviceId->Manufacturer,
                'modelName' => (string)$deviceId->ProductClass,
                'serialNumber' => (string)$deviceId->SerialNumber,
                'status' => 'online',
                'macAddress' => null,
                'softwareVersion' => null,
                'hardwareVersion' => null,
                'ssid' => null,
                'uptime' => null,
                'tr069Password' => null,
                'connectedClients' => []
            ];

            // Process parameters
            foreach ($parameterList as $param) {
                $name = (string)$param->Name;
                $value = (string)$param->Value;
                
                // Log the parameter for debugging
                error_log("Processing parameter: $name = $value");
                
                switch ($name) {
                    case 'Device.DeviceInfo.SoftwareVersion':
                    case 'InternetGatewayDevice.DeviceInfo.SoftwareVersion':
                        $deviceInfo['softwareVersion'] = $value;
                        break;
                    case 'Device.DeviceInfo.HardwareVersion':
                    case 'InternetGatewayDevice.DeviceInfo.HardwareVersion':
                        $deviceInfo['hardwareVersion'] = $value;
                        break;
                    case 'Device.Ethernet.Interface.1.MACAddress':
                    case 'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1.MACAddress':
                        $deviceInfo['macAddress'] = $value;
                        break;
                    case 'Device.WiFi.SSID.1.SSID':
                    case 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID':
                        $deviceInfo['ssid'] = $value;
                        break;
                    case 'Device.DeviceInfo.UpTime':
                    case 'InternetGatewayDevice.DeviceInfo.UpTime':
                        $deviceInfo['uptime'] = intval($value);
                        break;
                    case 'Device.ManagementServer.Password':
                    case 'InternetGatewayDevice.ManagementServer.Password':
                        $deviceInfo['tr069Password'] = $value;
                        break;
                }

                // Handle connected clients
                if (strpos($name, 'Device.WiFi.AccessPoint.1.AssociatedDevice.') === 0 ||
                    strpos($name, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDevice.') === 0) {
                    preg_match('/AssociatedDevice\.(\d+)\./', $name, $matches);
                    if ($matches) {
                        $index = $matches[1];
                        if (!isset($deviceInfo['connectedClients'][$index])) {
                            $deviceInfo['connectedClients'][$index] = [];
                        }
                        if (strpos($name, '.MACAddress') !== false) {
                            $deviceInfo['connectedClients'][$index]['macAddress'] = $value;
                        }
                    }
                }
            }

            // Log the final device info for debugging
            error_log("Final device info: " . print_r($deviceInfo, true));

            // Update device in database
            $this->deviceId = $this->deviceManager->updateDevice($deviceInfo);
            $this->sessionId = $this->sessionManager->createSession($deviceInfo['serialNumber']);
            
            // Create inform response
            $this->soapResponse = $this->createInformResponse();
        } catch (Exception $e) {
            error_log("Error in handleInform: " . $e->getMessage());
            throw $e;
        }
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

    private function sendResponse() {
        if ($this->soapResponse) {
            header('Content-Type: text/xml; charset=utf-8');
            echo $this->soapResponse;
            error_log("Response sent: " . $this->soapResponse);
        }
    }
}
