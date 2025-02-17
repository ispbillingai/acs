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
        'Device.Ethernet.Interface.1.orig-mac-address' => 'macAddress',
        'Device.Interface.ether1.MACAddress' => 'macAddress',
        'Device.Interface.ether1.orig-mac-address' => 'macAddress',
        'Device.LAN.Interface.1.orig-mac-address' => 'macAddress',
        'Device.WiFi.SSID.1.SSID' => 'ssid',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID' => 'ssid',
        'Device.WiFi.AccessPoint.1.Security.KeyPassphrase' => 'ssidPassword',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase' => 'ssidPassword',
        'Device.WiFi.Radio.1.SSID' => 'ssid',
        'Device.WiFi.Radio.1.SecurityKey' => 'ssidPassword',
        'Device.WiFi.SSID.1.Name' => 'ssid',
        'Device.WiFi.AccessPoint.1.Security.PreSharedKey' => 'ssidPassword',
        'Device.WiFi.AccessPoint.1.Security.PSKPassphrase' => 'ssidPassword',
        'Device.DeviceInfo.UpTime' => 'uptime',
        'InternetGatewayDevice.DeviceInfo.UpTime' => 'uptime',
        'Device.Interface.ether1.UpTime' => 'uptime'
    ];

    public function parseInform($request) {
        // Register all possible namespaces that Mikrotik might use
        $namespaces = $request->getNamespaces(true);
        error_log("Found XML namespaces: " . print_r($namespaces, true));

        // Extract SOAP and CWMP namespaces
        $soapEnv = isset($namespaces['soapenv']) ? $namespaces['soapenv'] : 'http://schemas.xmlsoap.org/soap/envelope/';
        $cwmp = isset($namespaces['cwmp']) ? $namespaces['cwmp'] : 'urn:dslforum-org:cwmp-1-0';
        
        error_log("Using SOAP namespace: " . $soapEnv);
        error_log("Using CWMP namespace: " . $cwmp);

        try {
            // Get the Inform element using proper namespace
            $inform = $request->children($soapEnv)->Body->children($cwmp)->Inform;
            error_log("Extracted Inform element: " . print_r($inform, true));

            // Extract DeviceId with proper namespace handling
            $deviceId = $inform->DeviceId;
            if (empty($deviceId)) {
                $deviceId = $inform->children()->DeviceId;
            }
            
            error_log("Extracted DeviceId element: " . print_r($deviceId, true));
            
            if (empty($deviceId)) {
                throw new Exception("Missing or invalid DeviceId element");
            }

            // Get ParameterList with proper namespace handling
            $parameterList = $inform->ParameterList->children()->ParameterValueStruct;
            error_log("Parameter List Content: " . print_r($parameterList, true));

            // Extract base device info
            $deviceInfo = $this->extractBaseDeviceInfo($deviceId);
            error_log("Base device info: " . print_r($deviceInfo, true));
            
            // Process parameters with enhanced logging
            $this->processParameters($parameterList, $deviceInfo);
            error_log("After processing parameters: " . print_r($deviceInfo, true));
            
            // Additional parsing for Mikrotik interfaces
            $this->processMikrotikInterfaces($parameterList, $deviceInfo);
            error_log("After processing Mikrotik interfaces: " . print_r($deviceInfo, true));
            
            return $deviceInfo;
            
        } catch (Exception $e) {
            error_log("Error parsing Inform message: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    private function extractBaseDeviceInfo($deviceId) {
        error_log("Extracting base device info from: " . print_r($deviceId, true));
        
        $manufacturer = (string)($deviceId->Manufacturer ?? '');
        $productClass = (string)($deviceId->ProductClass ?? '');
        $serialNumber = (string)($deviceId->SerialNumber ?? '');
        $oui = (string)($deviceId->OUI ?? '');
        
        // If serial number is empty, use OUI + a timestamp
        if (empty($serialNumber) && !empty($oui)) {
            $serialNumber = $oui . '_' . time();
            error_log("Generated serial number from OUI: $serialNumber");
        }
        
        error_log("Extracted manufacturer: $manufacturer");
        error_log("Extracted product class: $productClass");
        error_log("Extracted serial number: $serialNumber");
        error_log("Extracted OUI: $oui");
        
        return [
            'manufacturer' => $manufacturer,
            'modelName' => $productClass,
            'serialNumber' => $serialNumber,
            'status' => 'online',
            'macAddress' => null,
            'softwareVersion' => null,
            'hardwareVersion' => null,
            'ssid' => null,
            'ssidPassword' => null,
            'uptime' => null,
            'tr069Password' => null,
            'connectedClients' => []
        ];
    }

    private function processWiFiParameters($parameterList, &$deviceInfo) {
        error_log("=== BEGIN WIFI PARAMETER PROCESSING ===");
        foreach ($parameterList as $param) {
            $name = (string)($param->Name ?? $param->children()->Name ?? '');
            $value = (string)($param->Value ?? $param->children()->Value ?? '');
            
            error_log("Checking WiFi parameter: [$name] = [$value]");

            // Check for SSID
            if (strpos($name, '.SSID') !== false || 
                strpos($name, '.wlan1.name') !== false || 
                strpos($name, '.WLANConfiguration') !== false) {
                $deviceInfo['ssid'] = $value;
                error_log("SUCCESS: Found WiFi SSID: $value");
            }
            
            // Check for Password
            if (strpos($name, '.SecurityKey') !== false || 
                strpos($name, '.KeyPassphrase') !== false || 
                strpos($name, '.WPAPassphrase') !== false || 
                strpos($name, '.PreSharedKey') !== false) {
                $deviceInfo['ssidPassword'] = $value;
                error_log("SUCCESS: Found WiFi password: $value");
            }
        }
        error_log("=== END WIFI PARAMETER PROCESSING ===");
    }

    private function processParameters($parameterList, &$deviceInfo) {
        error_log("=== BEGIN PARAMETER PROCESSING ===");
        if (empty($parameterList)) {
            error_log("WARNING: No parameters to process");
            return;
        }

        error_log("Looking for critical parameters:");
        error_log("- SSID (WiFi name)");
        error_log("- SSID Password");
        error_log("- ether1 MAC Address");
        error_log("- Device Uptime");

        foreach ($parameterList as $param) {
            $name = (string)($param->Name ?? $param->children()->Name ?? '');
            $value = (string)($param->Value ?? $param->children()->Value ?? '');
            
            error_log("Processing parameter: [$name] = [$value]");

            if (isset($this->parameterMap[$name])) {
                $key = $this->parameterMap[$name];
                $deviceInfo[$key] = $value;
                error_log("SUCCESS: Mapped parameter $name to $key with value $value");
            } else {
                error_log("INFO: No mapping found for parameter: $name");
            }

            // Enhanced logging for critical parameters
            if (strpos($name, 'WiFi') !== false || strpos($name, 'WLAN') !== false) {
                error_log("Found WiFi-related parameter: $name = $value");
            }
            if (strpos($name, 'Interface') !== false && strpos($name, 'MAC') !== false) {
                error_log("Found MAC address-related parameter: $name = $value");
            }
            if (strpos($name, 'UpTime') !== false) {
                error_log("Found uptime-related parameter: $name = $value");
            }
        }
        error_log("=== END PARAMETER PROCESSING ===");
    }

    private function processMikrotikInterfaces($parameterList, &$deviceInfo) {
        error_log("=== BEGIN MIKROTIK INTERFACE PROCESSING ===");
        error_log("Looking for ether1 MAC address...");
        
        foreach ($parameterList as $param) {
            $name = (string)($param->Name ?? $param->children()->Name ?? '');
            $value = (string)($param->Value ?? $param->children()->Value ?? '');
            
            error_log("Checking interface parameter: [$name] = [$value]");

            if (strpos($name, 'Device.Ethernet.Interface.') === 0 || 
                strpos($name, 'Device.Interface.ether1') === 0) {
                error_log("Found ethernet interface parameter");
                
                if (strpos($name, '.MACAddress') !== false || 
                    strpos($name, '.orig-mac-address') !== false) {
                    $deviceInfo['macAddress'] = $value;
                    error_log("SUCCESS: Found Mikrotik ethernet MAC address: $value");
                }
            }
        }
        error_log("=== END MIKROTIK INTERFACE PROCESSING ===");
        error_log("Final MAC address value: " . ($deviceInfo['macAddress'] ?? 'not found'));
    }

    private function processConnectedClient($name, $value, &$deviceInfo) {
        $patterns = [
            'Device.WiFi.AccessPoint.1.AssociatedDevice.',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDevice.',
            // Add Mikrotik specific patterns if needed
        ];

        foreach ($patterns as $pattern) {
            if (strpos($name, $pattern) === 0) {
                error_log("Processing connected client parameter: $name = $value");
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
    }

    private function ensureRequiredFields(&$deviceInfo) {
        if (empty($deviceInfo['serialNumber'])) {
            // Try to use MAC address if available
            if (!empty($deviceInfo['macAddress'])) {
                $deviceInfo['serialNumber'] = $deviceInfo['macAddress'];
                error_log("Using MAC address as serial number: " . $deviceInfo['serialNumber']);
            } else {
                // Generate a unique ID as last resort
                $deviceInfo['serialNumber'] = md5(uniqid() . $_SERVER['REMOTE_ADDR']);
                error_log("Generated serial number for device: " . $deviceInfo['serialNumber']);
            }
        }
    }
}
