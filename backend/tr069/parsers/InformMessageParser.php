
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
        'Device.WiFi.SSID.1.SSID' => 'ssid',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID' => 'ssid',
        'Device.DeviceInfo.UpTime' => 'uptime',
        'InternetGatewayDevice.DeviceInfo.UpTime' => 'uptime'
    ];

    public function parseInform($request) {
        error_log("Raw Inform request: " . print_r($request, true));
        
        $deviceId = $request->DeviceId;
        $parameterList = $request->ParameterList->ParameterValueStruct ?? [];
        
        $deviceInfo = $this->extractBaseDeviceInfo($deviceId);
        $this->processParameters($parameterList, $deviceInfo);
        $this->ensureRequiredFields($deviceInfo);
        
        return $deviceInfo;
    }

    private function extractBaseDeviceInfo($deviceId) {
        error_log("Device ID information: " . print_r($deviceId, true));
        
        return [
            'manufacturer' => (string)$deviceId->Manufacturer,
            'modelName' => (string)$deviceId->ProductClass,
            'serialNumber' => (string)$deviceId->SerialNumber,
            'status' => 'online',
            'macAddress' => null,
            'softwareVersion' => null,
            'hardwareVersion' => null,
            'ssid' => null,
            'uptime' => null,
            'tr069Password' => null,
            'connectedClients' => []
        ];
    }

    private function processParameters($parameterList, &$deviceInfo) {
        foreach ($parameterList as $param) {
            $name = (string)$param->Name;
            $value = (string)$param->Value;
            
            error_log("Processing parameter: $name = $value");

            if (isset($this->parameterMap[$name])) {
                $key = $this->parameterMap[$name];
                $deviceInfo[$key] = $value;
                error_log("Mapped parameter $name to $key with value $value");
            }

            $this->processConnectedClient($name, $value, $deviceInfo);
        }
    }

    private function processConnectedClient($name, $value, &$deviceInfo) {
        if (strpos($name, 'Device.WiFi.AccessPoint.1.AssociatedDevice.') === 0 ||
            strpos($name, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDevice.') === 0) {
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

    private function ensureRequiredFields(&$deviceInfo) {
        if (empty($deviceInfo['serialNumber'])) {
            $deviceInfo['serialNumber'] = $deviceInfo['macAddress'] ?? md5(uniqid());
            error_log("Generated serial number for device: " . $deviceInfo['serialNumber']);
        }
    }
}
