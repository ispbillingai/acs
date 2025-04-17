
<?php

class WifiTaskGenerator {
    
    private $logger;
    
    public function __construct($logger) {
        $this->logger = $logger;
    }
    
    public function generateParameters($data) {
        $ssid = $data['ssid'] ?? null;
        $password = $data['password'] ?? null;
        
        if (!$ssid) {
            $this->logger->logToFile("SSID is required for WiFi configuration");
            return null;
        }
        
        $parameters = [
            [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                'value' => $ssid,
                'type' => 'xsd:string'
            ],
            // Add the enable parameter to make sure WiFi is active
            [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable',
                'value' => 'true',
                'type' => 'xsd:boolean'
            ]
        ];
        
        // Add Huawei-specific enable parameter if present
        $parameters[] = [
            'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_Enable',
            'value' => 'true',
            'type' => 'xsd:boolean'
        ];
        
        // Only add password parameter if provided
        if ($password) {
            // Try both common password parameter paths
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
                'value' => $password,
                'type' => 'xsd:string'
            ];
            
            // Some devices use PreSharedKey instead of KeyPassphrase
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey',
                'value' => $password,
                'type' => 'xsd:string'
            ];
            
            // Make sure security is enabled when password is set
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',
                'value' => 'WPAand11i',
                'type' => 'xsd:string'
            ];
        }
        
        $this->logger->logToFile("Generated WiFi parameters - SSID: $ssid, Password length: " . 
                 ($password ? strlen($password) : 0) . " chars");
        
        return [
            'method' => 'SetParameterValues',
            'parameters' => $parameters
        ];
    }
}

