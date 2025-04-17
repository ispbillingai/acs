<?php

class WifiTaskGenerator {
    
    private $logger;
    
    public function __construct($logger) {
        $this->logger = $logger;
    }
    
    public function generateParameters($data) {
        $ssid = trim($data['ssid'] ?? ''); // Trim to remove trailing spaces
        $password = $data['password'] ?? '';
        $security = $data['security'] ?? 'WPA2-PSK'; // Default to WPA2-PSK
        
        // Validate SSID
        if (empty($ssid)) {
            $this->logger->logToFile("SSID is required for WiFi configuration");
            return null;
        }
        
        // Validate password if provided
        if (!empty($password)) {
            if (strlen($password) < 8 || strlen($password) > 63) {
                $this->logger->logToFile("Password must be 8–63 characters for WPA2-PSK, got " . strlen($password));
                return null;
            }
            // Check for invalid characters (basic validation, adjust as needed)
            if (!preg_match('/^[a-zA-Z0-9!@#$%^&*()_+\-=\[\]{}|;:,.<>?]*$/', $password)) {
                $this->logger->logToFile("Password contains invalid characters: $password");
                return null;
            }
        }
        
        // Core parameters
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
        
        // Add password and security parameters if password is provided
        if (!empty($password)) {
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_SecurityMode',
                'value' => 'WPA2-PSK', // Huawei-specific, consistent with HG8546M
                'type' => 'xsd:string'
            ];
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_WPAPSK',
                'value' => $password, // Huawei-specific for WPA pre-shared key
                'type' => 'xsd:string'
            ];
            // Set encryption to AES, required for WPA2-PSK
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.WPAEncryptionModes',
                'value' => 'AESEncryption',
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
        
        $this->logger->logToFile("Generated WiFi parameters - SSID: $ssid, Password length: " . 
                                 strlen($password) . " chars, Security: $security");
        
        return [
            'method' => 'SetParameterValues',
            'parameters' => $parameters
        ];
    }
}