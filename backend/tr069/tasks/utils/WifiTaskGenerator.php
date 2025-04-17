
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
            // Always include Enable parameter to ensure WiFi is active
            [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable',
                'value' => 'true',
                'type' => 'xsd:boolean'
            ]
        ];
        
        // Only add password parameter if provided
        if ($password) {
            // Add Huawei-specific security mode parameter
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_SecurityMode',
                'value' => 'WPA2-PSK',  // Using WPA2-PSK as the default security mode
                'type' => 'xsd:string'
            ];
            
            // For Huawei devices, use KeyPassphrase
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
                'value' => $password,
                'type' => 'xsd:string'
            ];
            
            // Add security mode parameter to ensure password is applied
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',
                'value' => 'WPAand11i',
                'type' => 'xsd:string'
            ];
            
            // Make sure encryption is enabled
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.WPAEncryptionModes',
                'value' => 'AESEncryption',
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
