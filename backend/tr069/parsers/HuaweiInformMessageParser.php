<?php
class HuaweiInformMessageParser {
    /**
     * Maps known TR-069 or Huawei-specific parameter paths to
     * a key in $deviceInfo.
     */
    private $parameterMap = [
        // ============= Standard Device Info =============
        'Device.DeviceInfo.Manufacturer'                          => 'manufacturer',
        'InternetGatewayDevice.DeviceInfo.Manufacturer'           => 'manufacturer',
        'Device.DeviceInfo.ManufacturerOUI'                       => 'manufacturerOUI',
        'InternetGatewayDevice.DeviceInfo.ManufacturerOUI'        => 'manufacturerOUI',
        'Device.DeviceInfo.ModelName'                             => 'modelName',
        'InternetGatewayDevice.DeviceInfo.ModelName'              => 'modelName',
        // Huawei often uses ProductClass for the model
        'Device.DeviceInfo.ProductClass'                          => 'modelName',
        'InternetGatewayDevice.DeviceInfo.ProductClass'           => 'modelName',
        'Device.DeviceInfo.SerialNumber'                          => 'serialNumber',
        'InternetGatewayDevice.DeviceInfo.SerialNumber'           => 'serialNumber',
        'Device.DeviceInfo.HardwareVersion'                       => 'hardwareVersion',
        'InternetGatewayDevice.DeviceInfo.HardwareVersion'        => 'hardwareVersion',
        'Device.DeviceInfo.SoftwareVersion'                       => 'softwareVersion',
        'InternetGatewayDevice.DeviceInfo.SoftwareVersion'        => 'softwareVersion',
        'Device.DeviceInfo.UpTime'                                => 'uptime',
        'InternetGatewayDevice.DeviceInfo.UpTime'                 => 'uptime',

        // ============= MAC Addresses =============
        'Device.LAN.MACAddress'                                   => 'macAddress',
        // Common path for LAN MAC
        'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1.MACAddress' => 'macAddress',

        // ============= WAN / IP Address =============
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress' => 'ipAddress',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANIPConnection.1.ExternalIPAddress' => 'ipAddress',
        'Device.IP.Interface.1.IPv4Address.1.IPAddress'           => 'ipAddress',

        // ============= Management Server Params =============
        'Device.ManagementServer.ConnectionRequestURL'            => 'connectionRequestURL',
        'InternetGatewayDevice.ManagementServer.ConnectionRequestURL' => 'connectionRequestURL',
        'Device.ManagementServer.ParameterKey'                    => 'parameterKey',
        'InternetGatewayDevice.ManagementServer.ParameterKey'     => 'parameterKey',

        // ============= PON (Huawei Specific) =============
        'Device.X_HW_PON.1.OpticalTransceiverMonitoring.TxPower'  => 'ponTxPower',
        'InternetGatewayDevice.X_HW_PON.1.OpticalTransceiverMonitoring.TxPower' => 'ponTxPower',
        'Device.X_HW_PON.1.OpticalTransceiverMonitoring.RxPower'  => 'ponRxPower',
        'InternetGatewayDevice.X_HW_PON.1.OpticalTransceiverMonitoring.RxPower' => 'ponRxPower',
        'Device.X_HW_PON.1.ONTAuthentication.Mode'                => 'ontAuthMode',
        'InternetGatewayDevice.X_HW_PON.1.ONTAuthentication.Mode' => 'ontAuthMode',
        'Device.X_HW_PON.1.LaserState'                            => 'laserState',
        'InternetGatewayDevice.X_HW_PON.1.LaserState'             => 'laserState',

        // ============= WiFi (2.4 GHz) =============
        // Generic
        'Device.WiFi.SSID.1.SSID'                                 => 'ssid1',
        'Device.WiFi.AccessPoint.1.Security.KeyPassphrase'        => 'ssidPassword1',
        'Device.WiFi.Radio.1.Channel'                             => 'channel1',
        // InternetGatewayDevice form
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID'         => 'ssid1',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase'=> 'ssidPassword1',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Channel'      => 'channel1',

        // Some Huawei ONTs also use X_HW_ or X_HW_WLAN forms, e.g.:
        'InternetGatewayDevice.X_HW_WLANConfiguration.1.SSID'     => 'ssid1',
        'InternetGatewayDevice.X_HW_WLANConfiguration.1.KeyPassphrase' => 'ssidPassword1',
        'InternetGatewayDevice.X_HW_WLANConfiguration.1.Channel'  => 'channel1',

        // ============= WiFi (5 GHz) =============
        // Generic
        'Device.WiFi.SSID.2.SSID'                                 => 'ssid2',
        'Device.WiFi.AccessPoint.2.Security.KeyPassphrase'        => 'ssidPassword2',
        'Device.WiFi.Radio.2.Channel'                             => 'channel2',
        // InternetGatewayDevice form
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID'         => 'ssid2',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.KeyPassphrase'=> 'ssidPassword2',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.Channel'      => 'channel2',

        // Possible additional Huawei forms:
        'InternetGatewayDevice.X_HW_WLANConfiguration.2.SSID'     => 'ssid2',
        'InternetGatewayDevice.X_HW_WLANConfiguration.2.KeyPassphrase' => 'ssidPassword2',
        'InternetGatewayDevice.X_HW_WLANConfiguration.2.Channel'  => 'channel2',

        // In case device has more SSIDs:
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.3.SSID'         => 'ssid3',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.SSID'         => 'ssid4',

        // ============= Device Summary =============
        'Device.DeviceSummary'                                    => 'deviceSummary',
        'InternetGatewayDevice.DeviceSummary'                     => 'deviceSummary',

        // ============= Management Server Additional =============
        'Device.ManagementServer.EnableCWMP'                      => 'cwmpEnabled',
        'InternetGatewayDevice.ManagementServer.EnableCWMP'       => 'cwmpEnabled',
        'Device.ManagementServer.URL'                             => 'acsUrl',
        'InternetGatewayDevice.ManagementServer.URL'              => 'acsUrl',
        'Device.ManagementServer.Username'                        => 'acsUsername',
        'InternetGatewayDevice.ManagementServer.Username'         => 'acsUsername',
        'Device.ManagementServer.PeriodicInformInterval'          => 'informInterval',
        'InternetGatewayDevice.ManagementServer.PeriodicInformInterval' => 'informInterval',

        // ============= Firmware Upgrade Params =============
        'Device.X_HW_Upgrade.Image.1.Version'                     => 'currentFirmware',
        'InternetGatewayDevice.X_HW_Upgrade.Image.1.Version'      => 'currentFirmware',
        'Device.X_HW_Upgrade.Image.1.Active'                      => 'firmwareActive',
        'InternetGatewayDevice.X_HW_Upgrade.Image.1.Active'       => 'firmwareActive',
    ];

    /**
     * Parses the incoming Inform XML (SimpleXMLElement) for Huawei devices.
     *
     * @param SimpleXMLElement $request
     * @return array $deviceInfo
     * @throws Exception
     */
    public function parseInform($request) {
        try {
            // Log raw XML for debugging
            error_log("=== HUAWEI DEVICE INFORM XML START ===");
            if (is_object($request)) {
                error_log($request->asXML());
            } else {
                error_log("Warning: Request is not a valid SimpleXMLElement");
            }
            error_log("=== HUAWEI DEVICE INFORM XML END ===");

            // Prepare default device info structure
            $deviceInfo = [
                'manufacturer'       => 'Huawei', // Default
                'manufacturerOUI'    => '',
                'modelName'          => '',
                'serialNumber'       => '',
                'hardwareVersion'    => '',
                'softwareVersion'    => '',
                'uptime'             => 0,
                'status'             => 'online',
                'macAddress'         => '',
                'ipAddress'          => '',
                'deviceSummary'      => '',
                'connectionRequestURL'=> '',
                'parameterKey'       => '',
                // PON
                'ponTxPower'         => null,
                'ponRxPower'         => null,
                'ontAuthMode'        => null,
                'laserState'         => null,
                // WiFi
                'ssid1'              => null,
                'ssidPassword1'      => null,
                'channel1'           => null,
                'ssid2'              => null,
                'ssidPassword2'      => null,
                'channel2'           => null,
                'ssid3'              => null,
                'ssid4'              => null,
                // Management fields
                'cwmpEnabled'        => true,
                'acsUrl'             => null,
                'acsUsername'        => null,
                'informInterval'     => 300,
                // Firmware
                'currentFirmware'    => null,
                'firmwareActive'     => null,
                // Required by base system
                'tr069Password'      => '',
                'localAdminPassword' => '',
                // Additional
                'ssid'               => null,
                'ssidPassword'       => null,
                'connectedClients'   => 0,
            ];

            if (!$request) {
                error_log("ERROR: Huawei parser received empty request");
                throw new Exception("Empty request received");
            }

            // Register both standard and Huawei namespaces in the request
            $namespaces = [
                'soapenv' => 'http://schemas.xmlsoap.org/soap/envelope/',
                'soap'    => 'http://schemas.xmlsoap.org/soap/envelope/',
                'cwmp'    => 'urn:dslforum-org:cwmp-1-0',
                'xsd'     => 'http://www.w3.org/2001/XMLSchema',
                'xsi'     => 'http://www.w3.org/2001/XMLSchema-instance'
            ];
            foreach ($namespaces as $prefix => $ns) {
                if (is_object($request)) {
                    $request->registerXPathNamespace($prefix, $ns);
                }
            }

            // Try to get the <cwmp:Inform> section first, then fallback to <Inform>
            $inform = $request->xpath('//cwmp:Inform');
            if (empty($inform)) {
                $inform = $request->xpath('//Inform');
            }

            if (!empty($inform)) {
                $inform = $inform[0];
                error_log("Huawei parser found Inform section");

                // Extract DeviceId
                $deviceId = $inform->xpath('.//DeviceId');
                if (empty($deviceId)) {
                    $deviceId = $request->xpath('//DeviceId');
                }
                if (!empty($deviceId)) {
                    $deviceId = $deviceId[0];
                    $deviceInfo['manufacturer']    = (string)$deviceId->Manufacturer ?: 'Huawei';
                    $deviceInfo['manufacturerOUI'] = (string)$deviceId->OUI ?: '';
                    $deviceInfo['modelName']       = (string)$deviceId->ProductClass ?: '';
                    $deviceInfo['serialNumber']    = (string)$deviceId->SerialNumber ?: '';

                    error_log("Huawei Device ID Info:");
                    error_log("- Manufacturer: " . $deviceInfo['manufacturer']);
                    error_log("- OUI: " . $deviceInfo['manufacturerOUI']);
                    error_log("- Model: " . $deviceInfo['modelName']);
                    error_log("- Serial: " . $deviceInfo['serialNumber']);
                }

                // Log event codes
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

                // Extract Parameter List
                $parameters = $inform->xpath('.//ParameterList/ParameterValueStruct');
                if (empty($parameters)) {
                    $parameters = $request->xpath('//ParameterList/ParameterValueStruct');
                }

                if (!empty($parameters)) {
                    error_log("Huawei Parameters (Total: " . count($parameters) . "):");
                    foreach ($parameters as $param) {
                        $name      = (string)$param->Name;
                        $value     = (string)$param->Value;
                        $valueAttr = $param->Value->attributes('xsi', true);
                        $valueType = isset($valueAttr['type']) ? (string)$valueAttr['type'] : 'string';

                        error_log("- Parameter: $name = $value (Type: $valueType)");

                        // If we haven't yet identified modelName or serialNumber, try to glean it
                        if (empty($deviceInfo['modelName']) &&
                            (stripos($name, 'ProductClass') !== false || stripos($name, 'ModelName') !== false)) {
                            $deviceInfo['modelName'] = $value;
                            error_log("Found model name from parameter: $value");
                        }
                        if (empty($deviceInfo['serialNumber']) && stripos($name, 'SerialNumber') !== false) {
                            $deviceInfo['serialNumber'] = $value;
                            error_log("Found serial number from parameter: $value");
                        }

                        // Map the parameter if we know it
                        if (isset($this->parameterMap[$name])) {
                            $key = $this->parameterMap[$name];
                            switch ($key) {
                                case 'uptime':
                                    $deviceInfo[$key] = (int)$value;
                                    break;
                                case 'ponTxPower':
                                case 'ponRxPower':
                                    $deviceInfo[$key] = ($value === '') ? null : floatval($value);
                                    break;
                                case 'cwmpEnabled':
                                    // Convert "1"/"true" => true, else false
                                    $deviceInfo[$key] = ($value === '1' || strtolower($value) === 'true');
                                    break;
                                default:
                                    $deviceInfo[$key] = ($value === '') ? null : $value;
                                    break;
                            }
                        }

                        // Special-case IP detection
                        if (stripos($name, 'ExternalIPAddress') !== false && !empty($value)) {
                            $deviceInfo['ipAddress'] = $value;
                            error_log("Found IP address: $value");
                        }
                    }
                } else {
                    error_log("WARNING: No parameters found in Huawei Inform message");
                }

                // Enhanced summary logging
                error_log("TR-069 Huawei Device Parameters Summary:");
                error_log("- Device Model: " . ($deviceInfo['modelName'] ?: "Unknown"));
                error_log("- Serial Number: " . ($deviceInfo['serialNumber'] ?: "Unknown"));
                error_log("- Hardware Version: " . ($deviceInfo['hardwareVersion'] ?: "Not provided"));
                error_log("- Software Version: " . ($deviceInfo['softwareVersion'] ?: "Not provided"));
                error_log("- IP Address: " . ($deviceInfo['ipAddress'] ?: "Not provided"));
                error_log("- Connection Request URL: " . ($deviceInfo['connectionRequestURL'] ?: "Not provided"));
                error_log("- PON Tx Power: " . ($deviceInfo['ponTxPower'] !== null ? $deviceInfo['ponTxPower']." dBm" : "Not provided"));
                error_log("- PON Rx Power: " . ($deviceInfo['ponRxPower'] !== null ? $deviceInfo['ponRxPower']." dBm" : "Not provided"));
                error_log("- Laser State: " . ($deviceInfo['laserState'] ?: "Not provided"));
                error_log("- SSID 1: " . ($deviceInfo['ssid1'] ?: "Not provided"));
                error_log("- SSID 2: " . ($deviceInfo['ssid2'] ?: "Not provided"));
                error_log("- SSID 3: " . ($deviceInfo['ssid3'] ?: "Not provided"));
                error_log("- SSID 4: " . ($deviceInfo['ssid4'] ?: "Not provided"));
                error_log("- Uptime: " . $deviceInfo['uptime'] . " seconds");
                error_log("- Firmware Version: " . ($deviceInfo['currentFirmware'] ?: "Not provided"));

                // Ensure device_manager.php sees the main WiFi details
                $deviceInfo['ssid']         = $deviceInfo['ssid1'] ?: null;
                $deviceInfo['ssidPassword'] = $deviceInfo['ssidPassword1'] ?: null;
                $deviceInfo['connectedClients'] = 0;

            } else {
                error_log("ERROR: No Inform section found in Huawei request");
            }

            // Fallback if no serial number found
            if (empty($deviceInfo['serialNumber'])) {
                error_log("WARNING: Missing required field: serialNumber for Huawei device, using fallback");
                if (isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'HW_') !== false) {
                    $deviceInfo['serialNumber'] = "HUAWEI_" . md5($_SERVER['HTTP_USER_AGENT'] . $_SERVER['REMOTE_ADDR']);
                    error_log("Generated serial number from User-Agent: " . $deviceInfo['serialNumber']);
                } else {
                    $deviceInfo['serialNumber'] = "HUAWEI_" . md5($_SERVER['REMOTE_ADDR'] . time());
                    error_log("Generated serial number from IP/time: " . $deviceInfo['serialNumber']);
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
