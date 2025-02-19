
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
                    $deviceInfo['manufacturerOUI'] = (string)$deviceId->OUI;
                    $deviceInfo['modelName'] = (string)$deviceId->ProductClass;
                    $deviceInfo['serialNumber'] = (string)$deviceId->SerialNumber;
                }

                // Extract parameters
                $parameters = $inform->xpath('.//ParameterList/ParameterValueStruct');

                if ($parameters) {
                    foreach ($parameters as $param) {
                        $name = (string)$param->Name;
                        $value = (string)$param->Value;

                        // Enhanced parameter logging for Huawei devices
                        if (strpos($name, 'Device.DeviceInfo') !== false ||
                            strpos($name, 'Device.X_HW_PON') !== false ||
                            strpos($name, 'Device.LAN.WLANConfiguration') !== false) {
                            error_log("TR-069 Huawei Parameter - $name: $value");
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
                    }
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
            }

            if (empty($deviceInfo['serialNumber'])) {
                throw new Exception("Missing required field: serialNumber");
            }

            return $deviceInfo;

        } catch (Exception $e) {
            error_log("Error parsing Huawei Inform message: " . $e->getMessage());
            throw $e;
        }
    }
}
