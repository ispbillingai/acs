
<?php

class WanTaskGenerator {
    
    private $logger;
    
    public function __construct($logger) {
        $this->logger = $logger;
    }
    
    public function generateParameters($data) {
        $ipAddress = $data['ip_address'] ?? null;
        $gateway = $data['gateway'] ?? null;
        
        if (!$ipAddress) {
            $this->logger->logToFile("IP Address is required for WAN configuration");
            return null;
        }
        
        $parameters = [
            [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
                'value' => $ipAddress,
                'type' => 'xsd:string'
            ]
        ];
        
        if ($gateway) {
            $parameters[] = [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DefaultGateway',
                'value' => $gateway,
                'type' => 'xsd:string'
            ];
        }
        
        $this->logger->logToFile("Generated WAN parameters - IP: $ipAddress, Gateway: $gateway");
        
        return [
            'method' => 'SetParameterValues',
            'parameters' => $parameters
        ];
    }
}
