<?php

class WanTaskGenerator {
    
    private $logger;
    
    public function __construct($logger) {
        $this->logger = $logger;
    }
    
    public function generateParameters($data) {
        $this->logger->logToFile("Generating PPPoE WAN parameters");
        return $this->generatePPPoEParameters($data);
    }
    
    private function generatePPPoEParameters($data) {
        $username = $data['pppoe_username'] ?? null;
        $password = $data['pppoe_password'] ?? null;
        
        if (!$username) {
            $this->logger->logToFile("Username is required for PPPoE configuration");
            return null;
        }
        
        $parameters = [
            [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Enable',
                'value' => '1',
                'type' => 'xsd:boolean'
            ],
            [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.ConnectionType',
                'value' => 'IP_Routed',
                'type' => 'xsd:string'
            ],
            [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username',
                'value' => $username,
                'type' => 'xsd:string'
            ]
        ];
        
        if ($password) {
            $parameters[] = [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Password',
                'value' => $password,
                'type' => 'xsd:string'
            ];
        }
        
        $this->logger->logToFile("Generated PPPoE WAN parameters - Username: $username, Password: " . ($password ? 'provided' : 'not provided'));
        
        return [
            'method' => 'SetParameterValues',
            'parameters' => $parameters
        ];
    }
}
?>