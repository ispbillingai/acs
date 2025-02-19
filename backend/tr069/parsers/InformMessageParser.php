
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
                'uptime' => 0, // Set default integer value
                'tr069Password' => '',
                'connectedClients' => []
            ];

            // Extract DeviceId information from the SOAP message
            if (isset($request->DeviceId)) {
                $deviceId = $request->DeviceId;
                $deviceInfo['manufacturer'] = (string)$deviceId->Manufacturer;
                $deviceInfo['modelName'] = (string)$deviceId->ProductClass;
                $deviceInfo['serialNumber'] = (string)$deviceId->SerialNumber;
                
                if (empty($deviceInfo['serialNumber']) && isset($deviceId->OUI)) {
                    $deviceInfo['serialNumber'] = (string)$deviceId->OUI . '_' . time();
                }
            }

            // Extract Parameters from ParameterList
            if (isset($request->ParameterList) && isset($request->ParameterList->ParameterValueStruct)) {
                foreach ($request->ParameterList->ParameterValueStruct as $param) {
                    $name = (string)$param->Name;
                    $value = (string)$param->Value;

                    error_log("Processing parameter: $name = $value");

                    if (isset($this->parameterMap[$name])) {
                        $key = $this->parameterMap[$name];
                        
                        // Special handling for uptime to ensure it's an integer
                        if ($key === 'uptime') {
                            $deviceInfo[$key] = empty($value) ? 0 : (int)$value;
                        } else {
                            $deviceInfo[$key] = $value;
                        }
                        
                        error_log("Mapped $name to $key: " . $deviceInfo[$key]);
                    }
                }
            }

            error_log("Parsed device info: " . print_r($deviceInfo, true));
            return $deviceInfo;

        } catch (Exception $e) {
            error_log("Error parsing Inform message: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
}
