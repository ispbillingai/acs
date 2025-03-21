
<?php
class HuaweiInformMessageParser {
    private $parameterMap = [
        // Standard Device Info Parameters
        'Device.DeviceInfo.Manufacturer' => 'manufacturer',
        'Device.DeviceInfo.ManufacturerOUI' => 'manufacturerOUI',
        'Device.DeviceInfo.ModelName' => 'modelName',
        'Device.DeviceInfo.SerialNumber' => 'serialNumber',
        'Device.DeviceInfo.HardwareVersion' => 'hardwareVersion',
        'Device.DeviceInfo.SoftwareVersion' => 'softwareVersion',
        'Device.DeviceInfo.UpTime' => 'uptime',
        
        // PON Specific Parameters
        'Device.X_HW_PON.1.OpticalTransceiverMonitoring.TxPower' => 'ponTxPower',
        'Device.X_HW_PON.1.OpticalTransceiverMonitoring.RxPower' => 'ponRxPower',
        'Device.X_HW_PON.1.ONTAuthentication.Mode' => 'ontAuthMode',
        'Device.X_HW_PON.1.LaserState' => 'laserState',
        
        // WiFi Parameters
        'Device.LAN.WLANConfiguration.1.SSID' => 'ssid1',
        'Device.LAN.WLANConfiguration.1.KeyPassphrase' => 'ssidPassword1',
        'Device.LAN.WLANConfiguration.1.Channel' => 'channel1',
        'Device.LAN.WLANConfiguration.2.SSID' => 'ssid2',
        'Device.LAN.WLANConfiguration.2.KeyPassphrase' => 'ssidPassword2',
        'Device.LAN.WLANConfiguration.2.Channel' => 'channel2',
        
        // Management Server Parameters
        'Device.ManagementServer.EnableCWMP' => 'cwmpEnabled',
        'Device.ManagementServer.URL' => 'acsUrl',
        'Device.ManagementServer.Username' => 'acsUsername',
        'Device.ManagementServer.PeriodicInformInterval' => 'informInterval',
        
        // Upgrade Parameters
        'Device.X_HW_Upgrade.Image.1.Version' => 'currentFirmware',
        'Device.X_HW_Upgrade.Image.1.Active' => 'firmwareActive'
    ];

    public function parseInform($request) {
        try {
            // Log the raw XML for Huawei devices
            error_log("=== HUAWEI DEVICE INFORM XML START ===");
            error_log($request->asXML());
            error_log("=== HUAWEI DEVICE INFORM XML END ===");
            
            $deviceInfo = [
                'manufacturer' => '',
                'manufacturerOUI' => '',
                'modelName' => '',
                'serialNumber' => '',
                'hardwareVersion' => '',
                'softwareVersion' => '',
                'uptime' => 0,
                'status' => 'online',
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
                'localAdminPassword' => ''
            ];

            if (!$request) {
                error_log("ERROR: Huawei parser received empty request");
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
                error_log("Huawei parser found Inform section");

                // Extract DeviceId information
                $deviceId = $inform->xpath('.//DeviceId')[0];
                if ($deviceId) {
                    $deviceInfo['manufacturer'] = (string)$deviceId->Manufacturer;
                    $deviceInfo['manufacturerOUI'] = (string)$deviceId->OUI;
                    $deviceInfo['modelName'] = (string)$deviceId->ProductClass;
                    $deviceInfo['serialNumber'] = (string)$deviceId->SerialNumber;
                    
                    error_log("Huawei Device ID Info:");
                    error_log("- Manufacturer: " . $deviceInfo['manufacturer']);
                    error_log("- OUI: " . $deviceInfo['manufacturerOUI']);
                    error_log("- Model: " . $deviceInfo['modelName']);
                    error_log("- Serial: " . $deviceInfo['serialNumber']);
                }

                // Log event codes
                $eventCodes = $inform->xpath('.//Event/EventCode');
                if ($eventCodes) {
                    error_log("Huawei Event Codes:");
                    foreach ($eventCodes as $eventCode) {
                        error_log("- Event: " . (string)$eventCode);
                    }
                }

                // Extract parameters
                $parameters = $inform->xpath('.//ParameterList/ParameterValueStruct');

                if ($parameters) {
                    error_log("Huawei Parameters (Total: " . count($parameters) . "):");
                    foreach ($parameters as $param) {
                        $name = (string)$param->Name;
                        $value = (string)$param->Value;
                        $valueType = $param->Value->attributes() ? (string)$param->Value->attributes()->type : 'string';

                        // Log ALL parameters for Huawei devices
                        error_log("- Parameter: $name = $value (Type: $valueType)");

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
                    }
                } else {
                    error_log("WARNING: No parameters found in Huawei Inform message");
                }

                // Enhanced logging for Huawei devices
                error_log("TR-069 Huawei Device Parameters Summary:");
                error_log("- Device Model: " . $deviceInfo['modelName']);
                error_log("- Serial Number: " . $deviceInfo['serialNumber']);
                error_log("- PON Tx Power: " . ($deviceInfo['ponTxPower'] !== null ? $deviceInfo['ponTxPower'] . " dBm" : "Not provided"));
                error_log("- PON Rx Power: " . ($deviceInfo['ponRxPower'] !== null ? $deviceInfo['ponRxPower'] . " dBm" : "Not provided"));
                error_log("- Laser State: " . ($deviceInfo['laserState'] ?: "Not provided"));
                error_log("- SSID 1: " . ($deviceInfo['ssid1'] ?: "Not provided"));
                error_log("- SSID 2: " . ($deviceInfo['ssid2'] ?: "Not provided"));
                error_log("- Uptime: " . $deviceInfo['uptime'] . " seconds");
                error_log("- Firmware Version: " . ($deviceInfo['currentFirmware'] ?: "Not provided"));
            } else {
                error_log("ERROR: No Inform section found in Huawei request");
            }

            if (empty($deviceInfo['serialNumber'])) {
                error_log("ERROR: Missing required field: serialNumber for Huawei device");
                throw new Exception("Missing required field: serialNumber");
            }

            return $deviceInfo;

        } catch (Exception $e) {
            error_log("ERROR parsing Huawei Inform message: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
}
