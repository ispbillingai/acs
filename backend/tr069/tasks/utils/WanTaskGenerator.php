
<?php

class WanTaskGenerator {
    
    private $logger;
    
    public function __construct($logger) {
        $this->logger = $logger;
    }
    
    public function generateParameters($data) {
        $connectionType = $data['connection_type'] ?? 'DHCP';
        $this->logger->logToFile("Generating WAN parameters for connection type: $connectionType");
        
        // Get methods based on connection type
        switch ($connectionType) {
            case 'Static':
                return $this->generateStaticIPParameters($data);
            case 'PPPoE':
                return $this->generatePPPoEParameters($data);
            case 'DHCP':
            default:
                return $this->generateDHCPParameters($data);
        }
    }
    
    private function generateDHCPParameters($data) {
        $parameters = [
            [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.AddressingType',
                'value' => 'DHCP',
                'type' => 'xsd:string'
            ],
            [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.X_HW_DHCP_Enable',
                'value' => '1',
                'type' => 'xsd:boolean'
            ]
        ];
        
        $this->logger->logToFile("Generated DHCP WAN parameters");
        
        return [
            'method' => 'SetParameterValues',
            'parameters' => $parameters
        ];
    }
    
    private function generateStaticIPParameters($data) {
        $ipAddress = $data['ip_address'] ?? null;
        $subnetMask = $data['subnet_mask'] ?? null;
        $gateway = $data['gateway'] ?? null;
        $dnsServer1 = $data['dns_server1'] ?? null;
        $dnsServer2 = $data['dns_server2'] ?? null;
        
        if (!$ipAddress || !$subnetMask) {
            $this->logger->logToFile("IP Address and Subnet Mask are required for Static IP configuration");
            return null;
        }
        
        $parameters = [
            [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.AddressingType',
                'value' => 'Static',
                'type' => 'xsd:string'
            ],
            [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.X_HW_DHCP_Enable',
                'value' => '0',
                'type' => 'xsd:boolean'
            ],
            [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
                'value' => $ipAddress,
                'type' => 'xsd:string'
            ],
            [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.SubnetMask',
                'value' => $subnetMask,
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
        
        // DNS Settings (primary and secondary)
        if ($dnsServer1) {
            $parameters[] = [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DNSServers',
                'value' => $dnsServer2 ? "$dnsServer1,$dnsServer2" : $dnsServer1,
                'type' => 'xsd:string'
            ];
        }
        
        $this->logger->logToFile("Generated Static IP WAN parameters - IP: $ipAddress, Subnet: $subnetMask, Gateway: $gateway, DNS: " . ($dnsServer1 ?? 'none'));
        
        return [
            'method' => 'SetParameterValues',
            'parameters' => $parameters
        ];
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
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Enable',
                'value' => '1',
                'type' => 'xsd:boolean'
            ],
            [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ConnectionType',
                'value' => 'IP_Routed',
                'type' => 'xsd:string'
            ],
            [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
                'value' => $username,
                'type' => 'xsd:string'
            ]
        ];
        
        if ($password) {
            $parameters[] = [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Password',
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
