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
        'InternetGatewayDevice.DeviceInfo.UpTime' => 'uptime'
    ];

    public function parseInform($request) {
        // Log the raw XML content
        $rawXml = $request->asXML();
        error_log("Raw XML content: " . $rawXml);
        
        // Log the full request object structure
        error_log("Full request object structure: " . print_r($request, true));
        
        // Check if this is a Mikrotik device
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        error_log("Processing request from device: " . $userAgent);
        
        try {
            // Extract DeviceId information with namespace handling
            $deviceId = $request->DeviceId ?? $request->children()->DeviceId;
            error_log("Extracted DeviceId: " . print_r($deviceId, true));
            
            // Try different ways to access ParameterList
            $parameterList = [];
            if (isset($request->ParameterList->ParameterValueStruct)) {
                $parameterList = $request->ParameterList->ParameterValueStruct;
            } elseif (isset($request->children()->ParameterList)) {
                $parameterList = $request->children()->ParameterList->children()->ParameterValueStruct;
            }
            error_log("Extracted ParameterList: " . print_r($parameterList, true));
            
            // Extract base device info
            $deviceInfo = $this->extractBaseDeviceInfo($deviceId);
            error_log("Base device info: " . print_r($deviceInfo, true));
            
            // Process parameters
            $this->processParameters($parameterList, $deviceInfo);
            error_log("After processing parameters: " . print_r($deviceInfo, true));
            
            // Additional parsing for Mikrotik ethernet interfaces
            $this->processMikrotikInterfaces($parameterList, $deviceInfo);
            error_log("After processing Mikrotik interfaces: " . print_r($deviceInfo, true));
            
            // Ensure required fields
            $this->ensureRequiredFields($deviceInfo);
            error_log("Final device info: " . print_r($deviceInfo, true));
            
            return $deviceInfo;
            
        } catch (Exception $e) {
            error_log("Error parsing Inform message: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    private function extractBaseDeviceInfo($deviceId) {
        error_log("Extracting base device info from: " . print_r($deviceId, true));
        
        // Try different methods to access DeviceId properties
        $manufacturer = (string)($deviceId->Manufacturer ?? $deviceId->children()->Manufacturer ?? '');
        $productClass = (string)($deviceId->ProductClass ?? $deviceId->children()->ProductClass ?? '');
        $serialNumber = (string)($deviceId->SerialNumber ?? $deviceId->children()->SerialNumber ?? '');
        
        error_log("Extracted manufacturer: $manufacturer");
        error_log("Extracted product class: $productClass");
        error_log("Extracted serial number: $serialNumber");
        
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

    private function processParameters($parameterList, &$deviceInfo) {
        if (empty($parameterList)) {
            error_log("No parameters to process");
            return;
        }

        foreach ($parameterList as $param) {
            // Try different ways to access parameter properties
            $name = (string)($param->Name ?? $param->children()->Name ?? '');
            $value = (string)($param->Value ?? $param->children()->Value ?? '');
            
            error_log("Processing parameter: $name = $value");

            if (isset($this->parameterMap[$name])) {
                $key = $this->parameterMap[$name];
                $deviceInfo[$key] = $value;
                error_log("Mapped parameter $name to $key with value $value");
            }

            // Special handling for Mikrotik Interface parameters
            if (strpos($name, 'Device.Interface.') === 0) {
                $this->processMikrotikInterface($name, $value, $deviceInfo);
            }

            // Special handling for WiFi parameters
            if (strpos($name, 'Device.WiFi.') === 0) {
                $this->processWiFiParameter($name, $value, $deviceInfo);
            }

            $this->processConnectedClient($name, $value, $deviceInfo);
        }
    }

    private function processWiFiParameter($name, $value, &$deviceInfo) {
        // Additional WiFi parameter processing for Mikrotik
        if (strpos($name, '.SSID') !== false || strpos($name, '.Name') !== false) {
            $deviceInfo['ssid'] = $value;
            error_log("Found WiFi SSID: $value");
        } elseif (strpos($name, '.SecurityKey') !== false || 
                  strpos($name, '.PreSharedKey') !== false || 
                  strpos($name, '.PSKPassphrase') !== false || 
                  strpos($name, '.KeyPassphrase') !== false) {
            $deviceInfo['ssidPassword'] = $value;
            error_log("Found WiFi password: $value");
        }
    }

    private function processMikrotikInterface($name, $value, &$deviceInfo) {
        // Specific handling for ether1 interface
        if (strpos($name, '.MAC') !== false) {
            $deviceInfo['macAddress'] = $value;
            error_log("Found ether1 MAC address: $value");
        }
    }

    private function processMikrotikInterfaces($parameterList, &$deviceInfo) {
        error_log("Processing Mikrotik interfaces...");
        
        foreach ($parameterList as $param) {
            $name = (string)($param->Name ?? $param->children()->Name ?? '');
            $value = (string)($param->Value ?? $param->children()->Value ?? '');
            
            error_log("Checking interface parameter: $name = $value");

            // Check for ethernet interface parameters
            if (strpos($name, 'Device.Ethernet.Interface.') === 0 || 
                strpos($name, 'Device.Interface.ether1') === 0) {
                
                if (strpos($name, '.MACAddress') !== false || 
                    strpos($name, '.orig-mac-address') !== false) {
                    $deviceInfo['macAddress'] = $value;
                    error_log("Found Mikrotik ethernet MAC address: $value");
                }
            }
        }
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
