
<?php

class InfoTaskGenerator {
    private $logger;
    
    public function __construct($logger) {
        $this->logger = $logger;
    }

    public function generateParameters(array $data) {
        // Core device info parameters
        $names = [
            'InternetGatewayDevice.DeviceInfo.HardwareVersion',
            'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'InternetGatewayDevice.DeviceInfo.UpTime',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey",
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DNSServers',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.SubnetMask',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DefaultGateway',
            'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries'
        ];

        // First get number of hosts to know how many host parameters to request
        $hostCount = $data['host_count'] ?? 0;
        
        // If we have hosts, add parameters for each host
        if ($hostCount > 0) {
            for ($i = 1; $i <= $hostCount; $i++) {
                $names[] = "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.Active";
                $names[] = "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.IPAddress";
                $names[] = "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.HostName";
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

