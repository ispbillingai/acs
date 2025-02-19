
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

            error_log("Request content: " . print_r($request, true));

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
            if (isset($request->DeviceId)) {
                error_log("Found DeviceId section");
                $deviceId = $request->DeviceId;
                
                // Check each field individually and log it
                if (isset($deviceId->Manufacturer)) {
                    $deviceInfo['manufacturer'] = (string)$deviceId->Manufacturer;
                    error_log("Found Manufacturer: " . $deviceInfo['manufacturer']);
                }
                
                if (isset($deviceId->ProductClass)) {
                    $deviceInfo['modelName'] = (string)$deviceId->ProductClass;
                    error_log("Found ProductClass: " . $deviceInfo['modelName']);
                }
                
                if (isset($deviceId->SerialNumber)) {
                    $deviceInfo['serialNumber'] = (string)$deviceId->SerialNumber;
                    error_log("Found SerialNumber: " . $deviceInfo['serialNumber']);
                }
                
                // Generate serial number from OUI if needed
                if (empty($deviceInfo['serialNumber']) && isset($deviceId->OUI)) {
                    $deviceInfo['serialNumber'] = (string)$deviceId->OUI . '_' . time();
                    error_log("Generated SerialNumber from OUI: " . $deviceInfo['serialNumber']);
                }
            }

            // Extract Parameters
            if (isset($request->ParameterList) && isset($request->ParameterList->ParameterValueStruct)) {
                foreach ($request->ParameterList->ParameterValueStruct as $param) {
                    $name = (string)($param->Name ?? '');
                    $value = (string)($param->Value ?? '');

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
