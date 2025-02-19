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
        try {
            error_log("Starting to parse Inform message");
            error_log("Raw request: " . print_r($request, true));

            // Get namespaces from the SOAP envelope
            $namespaces = $request->getNamespaces(true);
            error_log("Found XML namespaces: " . print_r($namespaces, true));

            // Extract SOAP and CWMP namespaces
            $soapEnv = isset($namespaces['soap']) ? $namespaces['soap'] : 'http://schemas.xmlsoap.org/soap/envelope/';
            $cwmp = isset($namespaces['cwmp']) ? $namespaces['cwmp'] : 'urn:dslforum-org:cwmp-1-0';
            
            error_log("Using SOAP namespace: " . $soapEnv);
            error_log("Using CWMP namespace: " . $cwmp);

            // Get the Body element
            $body = $request->children($soapEnv)->Body;
            if (empty($body)) {
                error_log("No SOAP Body found");
                throw new Exception("Missing SOAP Body");
            }

            // Get the Inform element using CWMP namespace
            $inform = $body->children($cwmp)->Inform;
            if (empty($inform)) {
                error_log("No Inform element found in Body");
                throw new Exception("Missing Inform element");
            }

            error_log("Extracted Inform element: " . print_r($inform, true));

            // Extract DeviceId with proper namespace handling
            $deviceId = $inform->DeviceId;
            if (empty($deviceId)) {
                $deviceId = $inform->children($cwmp)->DeviceId;
            }

            error_log("Extracted DeviceId element: " . print_r($deviceId, true));
            
            if (empty($deviceId)) {
                throw new Exception("Missing or invalid DeviceId element");
            }

            // Extract base device info
            $deviceInfo = $this->extractBaseDeviceInfo($deviceId);
            error_log("Base device info: " . print_r($deviceInfo, true));

            // Get ParameterList
            $parameterList = $inform->ParameterList->ParameterValueStruct;
            if (empty($parameterList)) {
                $parameterList = $inform->children($cwmp)->ParameterList->children($cwmp)->ParameterValueStruct;
            }

            if ($parameterList) {
                error_log("Parameter List Content: " . print_r($parameterList, true));
                $this->processParameters($parameterList, $deviceInfo);
                $this->processMikrotikInterfaces($parameterList, $deviceInfo);
            } else {
                error_log("No parameters found in Inform message");
            }

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
        error_log("=== BEGIN PARAMETER PROCESSING ===");
        
        foreach ($parameterList as $param) {
            $name = (string)($param->Name ?? '');
            $value = (string)($param->Value ?? '');
            
            error_log("Processing parameter: [$name] = [$value]");

            if (isset($this->parameterMap[$name])) {
                $key = $this->parameterMap[$name];
                $deviceInfo[$key] = $value;
                error_log("SUCCESS: Mapped parameter $name to $key with value $value");
            }
        }
        
        error_log("=== END PARAMETER PROCESSING ===");
    }

    private function processMikrotikInterfaces($parameterList, &$deviceInfo) {
        error_log("=== BEGIN MIKROTIK INTERFACE PROCESSING ===");
        
        foreach ($parameterList as $param) {
            $name = (string)($param->Name ?? '');
            $value = (string)($param->Value ?? '');
            
            if (strpos($name, 'Device.Ethernet.Interface.') === 0 || 
                strpos($name, 'Device.Interface.ether1') === 0) {
                
                if (strpos($name, '.MACAddress') !== false || 
                    strpos($name, '.orig-mac-address') !== false) {
                    $deviceInfo['macAddress'] = $value;
                    error_log("SUCCESS: Found Mikrotik ethernet MAC address: $value");
                }
            }
        }
        
        error_log("=== END MIKROTIK INTERFACE PROCESSING ===");
    }
}
