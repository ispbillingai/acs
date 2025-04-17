
<?php

class WifiTaskGenerator {
    
    private $logger;
    
    public function __construct($logger) {
        $this->logger = $logger;
    }
    
    public function generateParameters($data) {
        $ssid = trim($data['ssid'] ?? '');
        $password = $data['password'] ?? '';
        $security = $data['security'] ?? 'WPA2-PSK';
        
        // Log the incoming request for debugging
        $this->logger->logToFile("Attempting WiFi configuration with SSID: $ssid");
        
        // Validate SSID
        if (empty($ssid)) {
            $this->logger->logToFile("SSID is required for WiFi configuration");
            return null;
        }
        
        // Test different password parameter paths based on device model
        $parameters = [
            [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable',
                'value' => 'true',
                'type' => 'xsd:boolean'
            ],
            [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                'value' => $ssid,
                'type' => 'xsd:string'
            ]
        ];
        
        if (!empty($password)) {
            // Try TR-098 path first
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey',
                'value' => $password,
                'type' => 'xsd:string'
            ];
            
            // Alternative: Try direct WPA PSK path
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_WPAPSK',
                'value' => $password,
                'type' => 'xsd:string'
            ];
            
            // Alternative: Try TR-181 path
            $parameters[] = [
                'name' => 'Device.WiFi.SSID.1.PreSharedKey',
                'value' => $password,
                'type' => 'xsd:string'
            ];
            
            // Security mode parameters
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_SecurityMode',
                'value' => $security,
                'type' => 'xsd:string'
            ];
            
            // Encryption parameters
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.WPAEncryptionModes',
                'value' => 'AESEncryption',
                'type' => 'xsd:string'
            ];
            
            // Additional security parameters to try
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',
                'value' => 'WPAand11i',
                'type' => 'xsd:string'
            ];
        } else {
            // If no password, set security to None (open network)
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_SecurityMode',
                'value' => 'None',
                'type' => 'xsd:string'
            ];
        }
        
        $this->logger->logToFile("Generated WiFi parameters - SSID: $ssid, Testing multiple password paths");
        
        return [
            'method' => 'SetParameterValues',
            'parameters' => $parameters
        ];
    }
}
