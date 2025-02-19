
<?php
class InformMessageParser {
    private $parameterMap = [
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
        'Device.Ethernet.Interface.1.MACAddress' => 'macAddress',
        'Device.Interface.ether1.MACAddress' => 'macAddress',
        'Device.WiFi.SSID.1.SSID' => 'ssid',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID' => 'ssid',
        'Device.WiFi.AccessPoint.1.Security.KeyPassphrase' => 'ssidPassword',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase' => 'ssidPassword',
        'Device.DeviceInfo.UpTime' => 'uptime',
        'InternetGatewayDevice.DeviceInfo.UpTime' => 'uptime'
    ];

    public function parseInform($request) {
        try {
            error_log("Starting to parse Inform message");
            
            if (!$request) {
                throw new Exception("Empty request received");
            }

            // Get all namespaces
            $namespaces = $request->getNamespaces(true);
            error_log("Namespaces: " . print_r($namespaces, true));

            // Get the SOAP body using the correct namespace
            $soapenvNS = isset($namespaces['soapenv']) ? $namespaces['soapenv'] : 'http://schemas.xmlsoap.org/soap/envelope/';
            $cwmpNS = isset($namespaces['cwmp']) ? $namespaces['cwmp'] : 'urn:dslforum-org:cwmp-1-0';

            $body = $request->children($soapenvNS)->Body;
            $inform = $body->children($cwmpNS)->Inform;

            error_log("SOAP Body content: " . print_r($body, true));
            error_log("Inform content: " . print_r($inform, true));

            $deviceInfo = [
                'manufacturer' => '',
                'modelName' => '',
                'serialNumber' => '',
                'status' => 'online',
                'macAddress' => '',
                'softwareVersion' => '',
                'hardwareVersion' => '',
                'ssid' => '',
                'ssidPassword' => '',
                'uptime' => 0,
                'tr069Password' => '',
                'connectedClients' => [],
                'localAdminPassword' => ''
            ];

            // Extract DeviceId information
            if (isset($inform->DeviceId)) {
                error_log("Found DeviceId section: " . print_r($inform->DeviceId, true));
                $deviceId = $inform->DeviceId;
                
                if (isset($deviceId->Manufacturer)) {
                    $deviceInfo['manufacturer'] = (string)$deviceId->Manufacturer;
                    error_log("Manufacturer: " . $deviceInfo['manufacturer']);
                }
                
                if (isset($deviceId->ProductClass)) {
                    $deviceInfo['modelName'] = (string)$deviceId->ProductClass;
                    error_log("ProductClass: " . $deviceInfo['modelName']);
                }
                
                if (isset($deviceId->SerialNumber)) {
                    $deviceInfo['serialNumber'] = (string)$deviceId->SerialNumber;
                    error_log("SerialNumber: " . $deviceInfo['serialNumber']);
                }
            }

            // Extract Parameters
            if (isset($inform->ParameterList)) {
                foreach ($inform->ParameterList->children() as $param) {
                    $name = (string)$param->Name;
                    $value = (string)$param->Value;
                    error_log("Processing parameter: $name = $value");

                    // Map parameters
                    if (isset($this->parameterMap[$name])) {
                        $key = $this->parameterMap[$name];
                        if ($key === 'uptime') {
                            $deviceInfo[$key] = empty($value) ? 0 : (int)$value;
                        } else {
                            $deviceInfo[$key] = $value;
                        }
                        error_log("Mapped $name to $key: " . $deviceInfo[$key]);
                    }
                }
            }

            // Validate required fields
            if (empty($deviceInfo['serialNumber'])) {
                error_log("Serial number is empty after parsing");
                throw new Exception("Missing required field: serialNumber");
            }

            error_log("Final parsed device info: " . print_r($deviceInfo, true));
            return $deviceInfo;

        } catch (Exception $e) {
            error_log("Error parsing Inform message: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
}
