
<?php
class InfoTaskGenerator {
    private $logger;
    
    public function __construct($logger) {
        $this->logger = $logger;
    }

    public function generateParameters(array $data) {
        // First get core parameters including HostNumberOfEntries
        $names = [
            'InternetGatewayDevice.DeviceInfo.HardwareVersion',
            'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'InternetGatewayDevice.DeviceInfo.UpTime',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DNSServers',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.SubnetMask',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DefaultGateway',
            'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries'
        ];

        // Get number of hosts from the response data
        $hostCount = isset($data['HostNumberOfEntries']) ? (int)$data['HostNumberOfEntries'] : 0;
        
        $this->logger->logToFile("Detected {$hostCount} hosts from HostNumberOfEntries");
        
        // Add parameters for each connected host
        if ($hostCount > 0) {
            for ($i = 1; $i <= $hostCount; $i++) {
                $names[] = "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.Active";
                $names[] = "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.IPAddress";
                $names[] = "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.HostName";
                $this->logger->logToFile("Adding parameters for host {$i}");
            }
        }

        $this->logger->logToFile("InfoTaskGenerator building " . count($names) . " parameters");
        foreach ($names as $param) {
            $this->logger->logToFile("Parameter to retrieve: " . $param);
        }

        return [
            'method' => 'GetParameterValues',
            'parameterNames' => $names
        ];
    }
}
