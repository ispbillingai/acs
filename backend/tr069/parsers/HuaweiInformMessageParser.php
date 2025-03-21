
<?php
class HuaweiInformMessageParser {
    private $parameterMap = [
        // Standard Device Info Parameters
        'Device.DeviceInfo.Manufacturer' => 'manufacturer',
        'InternetGatewayDevice.DeviceInfo.Manufacturer' => 'manufacturer',
        'Device.DeviceInfo.ManufacturerOUI' => 'manufacturerOUI',
        'InternetGatewayDevice.DeviceInfo.ManufacturerOUI' => 'manufacturerOUI',
        'Device.DeviceInfo.ModelName' => 'modelName',
        'InternetGatewayDevice.DeviceInfo.ModelName' => 'modelName',
        'Device.DeviceInfo.ProductClass' => 'modelName', // Huawei often uses ProductClass
        'InternetGatewayDevice.DeviceInfo.ProductClass' => 'modelName',
        'Device.DeviceInfo.SerialNumber' => 'serialNumber',
        'InternetGatewayDevice.DeviceInfo.SerialNumber' => 'serialNumber',
        'Device.DeviceInfo.HardwareVersion' => 'hardwareVersion',
        'InternetGatewayDevice.DeviceInfo.HardwareVersion' => 'hardwareVersion',
        'Device.DeviceInfo.SoftwareVersion' => 'softwareVersion',
        'InternetGatewayDevice.DeviceInfo.SoftwareVersion' => 'softwareVersion',
        'Device.DeviceInfo.UpTime' => 'uptime',
        'InternetGatewayDevice.DeviceInfo.UpTime' => 'uptime',
        
        // MAC Addresses
        'Device.LAN.MACAddress' => 'macAddress',
        'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1.MACAddress' => 'macAddress',
        
        // IP Address
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress' => 'ipAddress',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANIPConnection.1.ExternalIPAddress' => 'ipAddress',
        'Device.IP.Interface.1.IPv4Address.1.IPAddress' => 'ipAddress',
        
        // Management Server Parameters
        'Device.ManagementServer.ConnectionRequestURL' => 'connectionRequestURL',
        'InternetGatewayDevice.ManagementServer.ConnectionRequestURL' => 'connectionRequestURL',
        'Device.ManagementServer.ParameterKey' => 'parameterKey',
        'InternetGatewayDevice.ManagementServer.ParameterKey' => 'parameterKey',
        
        // PON Specific Parameters
        'Device.X_HW_PON.1.OpticalTransceiverMonitoring.TxPower' => 'ponTxPower',
        'InternetGatewayDevice.X_HW_PON.1.OpticalTransceiverMonitoring.TxPower' => 'ponTxPower',
        'Device.X_HW_PON.1.OpticalTransceiverMonitoring.RxPower' => 'ponRxPower',
        'InternetGatewayDevice.X_HW_PON.1.OpticalTransceiverMonitoring.RxPower' => 'ponRxPower',
        'Device.X_HW_PON.1.ONTAuthentication.Mode' => 'ontAuthMode',
        'InternetGatewayDevice.X_HW_PON.1.ONTAuthentication.Mode' => 'ontAuthMode',
        'Device.X_HW_PON.1.LaserState' => 'laserState',
        'InternetGatewayDevice.X_HW_PON.1.LaserState' => 'laserState',
        
        // WiFi Parameters
        'Device.WiFi.SSID.1.SSID' => 'ssid1',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID' => 'ssid1',
        'Device.WiFi.AccessPoint.1.Security.KeyPassphrase' => 'ssidPassword1',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase' => 'ssidPassword1',
        'Device.WiFi.Radio.1.Channel' => 'channel1',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Channel' => 'channel1',
        'Device.WiFi.SSID.2.SSID' => 'ssid2',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID' => 'ssid2',
        'Device.WiFi.AccessPoint.2.Security.KeyPassphrase' => 'ssidPassword2',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.KeyPassphrase' => 'ssidPassword2',
        'Device.WiFi.Radio.2.Channel' => 'channel2',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.Channel' => 'channel2',
        
        // Device Summary
        'Device.DeviceSummary' => 'deviceSummary',
        'InternetGatewayDevice.DeviceSummary' => 'deviceSummary',
        
        // Management Server Parameters
        'Device.ManagementServer.EnableCWMP' => 'cwmpEnabled',
        'InternetGatewayDevice.ManagementServer.EnableCWMP' => 'cwmpEnabled',
        'Device.ManagementServer.URL' => 'acsUrl',
        'InternetGatewayDevice.ManagementServer.URL' => 'acsUrl',
        'Device.ManagementServer.Username' => 'acsUsername',
        'InternetGatewayDevice.ManagementServer.Username' => 'acsUsername',
        'Device.ManagementServer.PeriodicInformInterval' => 'informInterval',
        'InternetGatewayDevice.ManagementServer.PeriodicInformInterval' => 'informInterval',
        
        // Upgrade Parameters
        'Device.X_HW_Upgrade.Image.1.Version' => 'currentFirmware',
        'InternetGatewayDevice.X_HW_Upgrade.Image.1.Version' => 'currentFirmware',
        'Device.X_HW_Upgrade.Image.1.Active' => 'firmwareActive',
        'InternetGatewayDevice.X_HW_Upgrade.Image.1.Active' => 'firmwareActive'
    ];

    public function parseInform($request) {
        try {
            // Log the raw XML for Huawei devices for debugging
            error_log("=== HUAWEI DEVICE INFORM XML START ===");
            error_log(is_object($request) ? $request->asXML() : "Warning: Request is not a valid SimpleXMLElement");
            error_log("=== HUAWEI DEVICE INFORM XML END ===");
            
            $deviceInfo = [
                'manufacturer' => 'Huawei', // Set default for Huawei devices
                'manufacturerOUI' => '',
                'modelName' => '',
                'serialNumber' => '',
                'hardwareVersion' => '',
                'softwareVersion' => '',
                'uptime' => 0,
                'status' => 'online',
                'macAddress' => '',
                'ipAddress' => '',
                'deviceSummary' => '',
                'connectionRequestURL' => '',
                'parameterKey' => '',
                // PON specific fields
                'ponTxPower' => null,
                'ponRxPower' => null,
                'ontAuthMode' => null,
                'laserState' => null,
                // WiFi fields
                'ssid1' => null,
                'ssidPassword1' => null,
                'channel1' => null,
                'ssid2' => null,
                'ssidPassword2' => null,
                'channel2' => null,
                // Management fields
                'cwmpEnabled' => true,
                'acsUrl' => null,
                'acsUsername' => null,
                'informInterval' => 300,
                // Firmware
                'currentFirmware' => null,
                'firmwareActive' => null,
                // Required by base system
                'tr069Password' => '',
                'localAdminPassword' => '',
                // Values needed by device_manager.php
                'ssid' => null,
                'ssidPassword' => null,
                'connectedClients' => 0
            ];

            if (!$request) {
                error_log("ERROR: Huawei parser received empty request");
                throw new Exception("Empty request received");
            }

            // Register both standard and Huawei-specific namespaces
            $namespaces = [
                'soapenv' => 'http://schemas.xmlsoap.org/soap/envelope/',
                'soap' => 'http://schemas.xmlsoap.org/soap/envelope/',
                'cwmp' => 'urn:dslforum-org:cwmp-1-0',
                'xsd' => 'http://www.w3.org/2001/XMLSchema',
                'xsi' => 'http://www.w3.org/2001/XMLSchema-instance'
            ];

            foreach ($namespaces as $prefix => $ns) {
                if (is_object($request)) {
                    $request->registerXPathNamespace($prefix, $ns);
                }
            }

            // Get Inform section - try both with and without namespace
            $inform = $request->xpath('//cwmp:Inform');
            if (empty($inform)) {
                $inform = $request->xpath('//Inform');
            }

            if (!empty($inform)) {
                $inform = $inform[0];
                error_log("Huawei parser found Inform section");

                // Extract DeviceId information - try both with and without namespace
                $deviceId = $inform->xpath('.//DeviceId');
                if (empty($deviceId)) {
                    $deviceId = $request->xpath('//DeviceId');
                }
                
                if (!empty($deviceId)) {
                    $deviceId = $deviceId[0];
                    // Extract all possible Device ID fields
                    $deviceInfo['manufacturer'] = (string)$deviceId->Manufacturer ?: 'Huawei';
                    $deviceInfo['manufacturerOUI'] = (string)$deviceId->OUI ?: '';
                    $deviceInfo['modelName'] = (string)$deviceId->ProductClass ?: '';
                    $deviceInfo['serialNumber'] = (string)$deviceId->SerialNumber ?: '';
                    
                    error_log("Huawei Device ID Info:");
                    error_log("- Manufacturer: " . $deviceInfo['manufacturer']);
                    error_log("- OUI: " . $deviceInfo['manufacturerOUI']);
                    error_log("- Model: " . $deviceInfo['modelName']);
                    error_log("- Serial: " . $deviceInfo['serialNumber']);
                }

                // Log event codes - try both with and without namespace
                $eventCodes = $inform->xpath('.//Event/EventCode');
                if (empty($eventCodes)) {
                    $eventCodes = $request->xpath('//Event/EventCode');
                }
                
                if (!empty($eventCodes)) {
                    error_log("Huawei Event Codes:");
                    foreach ($eventCodes as $eventCode) {
                        error_log("- Event: " . (string)$eventCode);
                    }
                }

                // Extract parameters - try both with and without namespace
                $parameters = $inform->xpath('.//ParameterList/ParameterValueStruct');
                if (empty($parameters)) {
                    $parameters = $request->xpath('//ParameterList/ParameterValueStruct');
                }

                if (!empty($parameters)) {
                    error_log("Huawei Parameters (Total: " . count($parameters) . "):");
                    foreach ($parameters as $param) {
                        $name = (string)$param->Name;
                        $value = (string)$param->Value;
                        $valueType = isset($param->Value->attributes()['type']) ? 
                                    (string)$param->Value->attributes()['type'] : 'string';

                        // Log ALL parameters for Huawei devices
                        error_log("- Parameter: $name = $value (Type: $valueType)");

                        // Try to identify device model from any parameter if not set yet
                        if (empty($deviceInfo['modelName']) && 
                            (strpos($name, 'ProductClass') !== false || 
                             strpos($name, 'ModelName') !== false)) {
                            $deviceInfo['modelName'] = $value;
                            error_log("Found model name from parameter: " . $value);
                        }

                        // Try to identify serial number from any parameter if not set yet
                        if (empty($deviceInfo['serialNumber']) && strpos($name, 'SerialNumber') !== false) {
                            $deviceInfo['serialNumber'] = $value;
                            error_log("Found serial number from parameter: " . $value);
                        }

                        // Map parameters
                        if (isset($this->parameterMap[$name])) {
                            $key = $this->parameterMap[$name];
                            switch ($key) {
                                case 'uptime':
                                    $deviceInfo[$key] = empty($value) ? 0 : (int)$value;
                                    break;
                                case 'ponTxPower':
                                case 'ponRxPower':
                                    $deviceInfo[$key] = empty($value) ? null : floatval($value);
                                    break;
                                case 'cwmpEnabled':
                                    $deviceInfo[$key] = ($value === '1' || strtolower($value) === 'true');
                                    break;
                                default:
                                    $deviceInfo[$key] = empty($value) ? null : $value;
                            }
                        }
                        
                        // Special handling for IP address from ExternalIPAddress
                        if (strpos($name, 'ExternalIPAddress') !== false && !empty($value)) {
                            $deviceInfo['ipAddress'] = $value;
                            error_log("Found IP address: " . $value);
                        }
                    }
                } else {
                    error_log("WARNING: No parameters found in Huawei Inform message");
                }

                // Enhanced logging for Huawei devices
                error_log("TR-069 Huawei Device Parameters Summary:");
                error_log("- Device Model: " . ($deviceInfo['modelName'] ?: "Unknown"));
                error_log("- Serial Number: " . ($deviceInfo['serialNumber'] ?: "Unknown"));
                error_log("- Hardware Version: " . ($deviceInfo['hardwareVersion'] ?: "Not provided"));
                error_log("- Software Version: " . ($deviceInfo['softwareVersion'] ?: "Not provided"));
                error_log("- IP Address: " . ($deviceInfo['ipAddress'] ?: "Not provided"));
                error_log("- Connection Request URL: " . ($deviceInfo['connectionRequestURL'] ?: "Not provided"));
                error_log("- PON Tx Power: " . ($deviceInfo['ponTxPower'] !== null ? $deviceInfo['ponTxPower'] . " dBm" : "Not provided"));
                error_log("- PON Rx Power: " . ($deviceInfo['ponRxPower'] !== null ? $deviceInfo['ponRxPower'] . " dBm" : "Not provided"));
                error_log("- Laser State: " . ($deviceInfo['laserState'] ?: "Not provided"));
                error_log("- SSID 1: " . ($deviceInfo['ssid1'] ?: "Not provided"));
                error_log("- SSID 2: " . ($deviceInfo['ssid2'] ?: "Not provided"));
                error_log("- Uptime: " . $deviceInfo['uptime'] . " seconds");
                error_log("- Firmware Version: " . ($deviceInfo['currentFirmware'] ?: "Not provided"));
                
                // Set fields required by device_manager.php
                $deviceInfo['ssid'] = $deviceInfo['ssid1'] ?: null;
                $deviceInfo['ssidPassword'] = $deviceInfo['ssidPassword1'] ?: null;
                // Default clients to 0 if not provided
                $deviceInfo['connectedClients'] = 0;
            } else {
                error_log("ERROR: No Inform section found in Huawei request");
            }

            // Generate a serial number if not found - fallback
            if (empty($deviceInfo['serialNumber'])) {
                error_log("WARNING: Missing required field: serialNumber for Huawei device, using fallback");
                // Extract serial from URL if possible
                if (isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'HW_') !== false) {
                    $deviceInfo['serialNumber'] = "HUAWEI_" . md5($_SERVER['HTTP_USER_AGENT'] . $_SERVER['REMOTE_ADDR']);
                    error_log("Generated serial number from User-Agent: " . $deviceInfo['serialNumber']);
                } else {
                    $deviceInfo['serialNumber'] = "HUAWEI_" . md5($_SERVER['REMOTE_ADDR'] . time());
                    error_log("Generated serial number from IP: " . $deviceInfo['serialNumber']);
                }
            }

            return $deviceInfo;

        } catch (Exception $e) {
            error_log("ERROR parsing Huawei Inform message: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
}
