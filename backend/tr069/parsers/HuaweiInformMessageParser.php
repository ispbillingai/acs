<?php
class HuaweiInformMessageParser {
    private $parameterMap = [
        // Most important WiFi parameters for Huawei HG8145V
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID' => 'ssid1',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID' => 'ssid2',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.3.SSID' => 'ssid3',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.SSID' => 'ssid4',
        
        // WiFi passwords - multiple possible paths
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase' => 'ssidPassword1',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.KeyPassphrase' => 'ssidPassword2',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.3.KeyPassphrase' => 'ssidPassword3',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.KeyPassphrase' => 'ssidPassword4',
        
        // Alternative password paths
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase' => 'ssidPassword1',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.PreSharedKey.1.KeyPassphrase' => 'ssidPassword2',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.3.PreSharedKey.1.KeyPassphrase' => 'ssidPassword3',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.PreSharedKey.1.KeyPassphrase' => 'ssidPassword4',
        
        // More alternative password paths (Huawei specific)
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_WPAKey' => 'ssidPassword1',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.X_HW_WPAKey' => 'ssidPassword2',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.3.X_HW_WPAKey' => 'ssidPassword3',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.X_HW_WPAKey' => 'ssidPassword4',
        
        // Security modes
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType' => 'securityMode1',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.BeaconType' => 'securityMode2',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.3.BeaconType' => 'securityMode3',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.BeaconType' => 'securityMode4',
        
        // Essential info to identify the device
        'InternetGatewayDevice.DeviceInfo.Manufacturer' => 'manufacturer',
        'InternetGatewayDevice.DeviceInfo.ModelName' => 'modelName',
        'InternetGatewayDevice.DeviceInfo.ProductClass' => 'modelName',
        'InternetGatewayDevice.DeviceInfo.SerialNumber' => 'serialNumber',
        
        // Additional parameters for uptime and software version
        'InternetGatewayDevice.DeviceInfo.UpTime' => 'uptime',
        'InternetGatewayDevice.DeviceInfo.SoftwareVersion' => 'softwareVersion',
        'InternetGatewayDevice.DeviceInfo.X_HW_SoftwareVersion' => 'softwareVersion',
    ];

    public function parseInform($request) {
        try {
            // Prepare default device info structure
            $deviceInfo = [
                'manufacturer' => 'Huawei', // Always set to Huawei for this parser
                'modelName' => '',
                'serialNumber' => '',
                'softwareVersion' => '',
                'uptime' => '',
                // WiFi parameters
                'ssid1' => null,
                'ssid2' => null,
                'ssid3' => null,
                'ssid4' => null,
                'ssidPassword1' => null,
                'ssidPassword2' => null,
                'ssidPassword3' => null,
                'ssidPassword4' => null,
                'securityMode1' => null,
                'securityMode2' => null,
                'securityMode3' => null,
                'securityMode4' => null,
            ];

            if (!$request) {
                throw new Exception("Empty request received");
            }

            // Register namespaces
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

            // Get Inform section
            $inform = $request->xpath('//cwmp:Inform');
            if (empty($inform)) {
                $inform = $request->xpath('//Inform');
            }

            if (!empty($inform)) {
                $inform = $inform[0];
                
                // Extract DeviceId
                $deviceId = $inform->xpath('.//DeviceId');
                if (empty($deviceId)) {
                    $deviceId = $request->xpath('//DeviceId');
                }
                if (!empty($deviceId)) {
                    $deviceId = $deviceId[0];
                    // We'll keep the manufacturer as Huawei, but still extract from XML if available
                    if (!empty($deviceId->Manufacturer)) {
                        // Just for logging, we'll still use Huawei
                        $extractedManufacturer = (string)$deviceId->Manufacturer;
                    }
                    $deviceInfo['modelName'] = (string)$deviceId->ProductClass ?: '';
                    $deviceInfo['serialNumber'] = (string)$deviceId->SerialNumber ?: '';
                }

                // Extract Parameter List
                $parameters = $inform->xpath('.//ParameterList/ParameterValueStruct');
                if (empty($parameters)) {
                    $parameters = $request->xpath('//ParameterList/ParameterValueStruct');
                }

                if (!empty($parameters)) {
                    foreach ($parameters as $param) {
                        $name = (string)$param->Name;
                        $value = (string)$param->Value;
                        
                        // Map the parameter if we know it
                        if (isset($this->parameterMap[$name])) {
                            $key = $this->parameterMap[$name];
                            $deviceInfo[$key] = $value;
                        }
                        
                        // Special parsing for uptime or software version if found in other parameters
                        if (stripos($name, 'UpTime') !== false) {
                            $deviceInfo['uptime'] = $value;
                        } else if (stripos($name, 'SoftwareVersion') !== false) {
                            $deviceInfo['softwareVersion'] = $value;
                        }
                    }
                }
            }

            // Generate serial number if not found
            if (empty($deviceInfo['serialNumber'])) {
                $deviceInfo['serialNumber'] = "HUAWEI_" . md5($_SERVER['REMOTE_ADDR'] . time());
            }

            return $deviceInfo;

        } catch (Exception $e) {
            throw $e;
        }
    }
}
