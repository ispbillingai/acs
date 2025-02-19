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
        'Device.DeviceInfo.UpTime' => 'uptime'
    ];

    public function parseInform($request) {
        try {
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
                'connectedClients' => 0,
                'localAdminPassword' => ''
            ];

            if (!$request) {
                throw new Exception("Empty request received");
            }

            // Register namespaces
            $namespaces = [
                'soapenv' => 'http://schemas.xmlsoap.org/soap/envelope/',
                'cwmp' => 'urn:dslforum-org:cwmp-1-0'
            ];

            foreach ($namespaces as $prefix => $ns) {
                $request->registerXPathNamespace($prefix, $ns);
            }

            // Get Inform section
            $inform = $request->xpath('//cwmp:Inform');

            if (!empty($inform)) {
                $inform = $inform[0];

                // Extract DeviceId information
                $deviceId = $inform->xpath('.//DeviceId')[0];
                if ($deviceId) {
                    $deviceInfo['manufacturer'] = (string)$deviceId->Manufacturer;
                    $deviceInfo['modelName'] = (string)$deviceId->ProductClass;
                    $deviceInfo['serialNumber'] = (string)$deviceId->SerialNumber;
                }

                // Extract parameters
                $parameters = $inform->xpath('.//ParameterList/ParameterValueStruct');

                if ($parameters) {
                    foreach ($parameters as $param) {
                        $name = (string)$param->Name;
                        $value = (string)$param->Value;

                        // Only log specific parameters we're interested in
                        if (strpos($name, 'Device.WiFi.SSID') !== false ||
                            strpos($name, 'Device.WiFi.AccessPoint') !== false ||
                            strpos($name, 'Device.DeviceInfo.UpTime') !== false ||
                            strpos($name, 'Device.Ethernet.Interface.1.MACAddress') !== false) {
                            error_log("TR-069 Parameter - $name: $value");
                        }

                        // Map parameters
                        if (isset($this->parameterMap[$name])) {
                            $key = $this->parameterMap[$name];
                            switch ($key) {
                                case 'uptime':
                                    $deviceInfo[$key] = empty($value) ? 0 : (int)$value;
                                    break;
                                case 'connectedClients':
                                    $deviceInfo[$key] = empty($value) ? 0 : (int)$value;
                                    break;
                                default:
                                    $deviceInfo[$key] = $value;
                            }
                        }
                    }
                }

                // Log only the specific parameters we're tracking
                error_log("TR-069 Device Parameters Summary:");
                error_log("- SSID: " . ($deviceInfo['ssid'] ?: 'Not provided'));
                error_log("- Security Mode: " . ($deviceInfo['securityMode'] ?: 'Not provided'));
                error_log("- Uptime: " . $deviceInfo['uptime'] . " seconds");
                error_log("- MAC Address: " . ($deviceInfo['macAddress'] ?: 'Not provided'));
                error_log("- Connected Clients: " . $deviceInfo['connectedClients']);
            }

            if (empty($deviceInfo['serialNumber'])) {
                throw new Exception("Missing required field: serialNumber");
            }

            return $deviceInfo;

        } catch (Exception $e) {
            error_log("Error parsing Inform message: " . $e->getMessage());
            throw $e;
        }
    }
}
