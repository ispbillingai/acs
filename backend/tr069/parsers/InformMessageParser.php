
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

            // Initialize deviceInfo array
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

            // Register namespaces
            $namespaces = [
                'soapenv' => 'http://schemas.xmlsoap.org/soap/envelope/',
                'cwmp' => 'urn:dslforum-org:cwmp-1-0'
            ];

            foreach ($namespaces as $prefix => $ns) {
                $request->registerXPathNamespace($prefix, $ns);
            }

            // Use XPath to get Inform section
            $inform = $request->xpath('//cwmp:Inform');
            error_log("Found Inform section: " . print_r($inform, true));

            if (!empty($inform)) {
                $inform = $inform[0];

                // Extract DeviceId information using xpath
                $deviceId = $inform->xpath('.//DeviceId')[0];
                error_log("Found DeviceId section: " . print_r($deviceId, true));

                if ($deviceId) {
                    $deviceInfo['manufacturer'] = (string)$deviceId->Manufacturer;
                    $deviceInfo['modelName'] = (string)$deviceId->ProductClass;
                    $deviceInfo['serialNumber'] = (string)$deviceId->SerialNumber;

                    error_log("Extracted device info - Manufacturer: {$deviceInfo['manufacturer']}, Model: {$deviceInfo['modelName']}, Serial: {$deviceInfo['serialNumber']}");
                }

                // Extract parameters using xpath
                $parameters = $inform->xpath('.//ParameterList/ParameterValueStruct');
                error_log("Found parameters: " . print_r($parameters, true));

                if ($parameters) {
                    foreach ($parameters as $param) {
                        $name = (string)$param->Name;
                        $value = (string)$param->Value;
                        error_log("Processing parameter: $name = $value");

                        // Direct parameter mappings
                        if ($name === 'Device.DeviceInfo.HardwareVersion') {
                            $deviceInfo['hardwareVersion'] = $value;
                        } elseif ($name === 'Device.DeviceInfo.SoftwareVersion') {
                            $deviceInfo['softwareVersion'] = $value;
                        }

                        // Map other parameters
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
