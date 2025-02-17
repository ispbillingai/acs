
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
            // Log the entire request for debugging
            error_log("Raw Inform request: " . print_r($request, true));

            // Extract DeviceId information
            $deviceId = $request->DeviceId;
            error_log("Device ID information: " . print_r($deviceId, true));

            // Extract ParameterList
            $parameterList = $request->ParameterList->ParameterValueStruct ?? [];
            error_log("Parameter List: " . print_r($parameterList, true));
            
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

            // Debug log for initial device info
            error_log("Initial device info: " . print_r($deviceInfo, true));

            // Mikrotik specific parameter mapping
            $parameterMap = [
                'Device.DeviceInfo.Manufacturer' => 'manufacturer',
                'InternetGatewayDevice.DeviceInfo.Manufacturer' => 'manufacturer',
                'Device.DeviceInfo.ModelName' => 'modelName',
                'InternetGatewayDevice.DeviceInfo.ModelName' => 'modelName',
                'Device.DeviceInfo.SerialNumber' => 'serialNumber',
                'InternetGatewayDevice.DeviceInfo.SerialNumber' => 'serialNumber',
                'Device.DeviceInfo.HardwareVersion' => 'hardwareVersion',
                'InternetGatewayDevice.DeviceInfo.HardwareVersion' => 'hardwareVersion',
                'Device.DeviceInfo.SoftwareVersion' => 'softwareVersion',
                'InternetGatewayDevice.DeviceInfo.SoftwareVersion' => 'softwareVersion',
                'Device.LAN.MACAddress' => 'macAddress',
                'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1.MACAddress' => 'macAddress',
                'Device.WiFi.SSID.1.SSID' => 'ssid',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID' => 'ssid',
                'Device.DeviceInfo.UpTime' => 'uptime',
                'InternetGatewayDevice.DeviceInfo.UpTime' => 'uptime'
            ];

            // Process parameters
            foreach ($parameterList as $param) {
                $name = (string)$param->Name;
                $value = (string)$param->Value;
                
                error_log("Processing parameter: $name = $value");

                // Check if parameter exists in our mapping
                if (isset($parameterMap[$name])) {
                    $key = $parameterMap[$name];
                    $deviceInfo[$key] = $value;
                    error_log("Mapped parameter $name to $key with value $value");
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
                        } elseif (strpos($name, '.IPAddress') !== false) {
                            $deviceInfo['connectedClients'][$index]['ipAddress'] = $value;
                        }
                    }
                }
            }

            // Ensure we have required fields
            if (empty($deviceInfo['serialNumber'])) {
                $deviceInfo['serialNumber'] = $deviceInfo['macAddress'] ?? md5(uniqid());
                error_log("Generated serial number for device: " . $deviceInfo['serialNumber']);
            }

            // Log the final device info for debugging
            error_log("Final device info before database update: " . print_r($deviceInfo, true));

            // Update device in database
            $this->deviceId = $this->deviceManager->updateDevice($deviceInfo);
            $this->sessionId = $this->sessionManager->createSession($deviceInfo['serialNumber']);
            
            // Create inform response
            $this->soapResponse = $this->createInformResponse();
        } catch (Exception $e) {
            error_log("Error in handleInform: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
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
