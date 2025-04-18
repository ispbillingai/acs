<?php

class InfoTaskGenerator {
    private $logger;
    private $opticalTester;
    
    public function __construct($logger) {
        $this->logger = $logger;
        $this->opticalTester = new OpticalParameterTester($logger);
    }

    public function generateParameters(array $data) {
        // If optical test is requested
        if (isset($data['test_optical']) && $data['test_optical'] === true) {
            $this->logger->logToFile("InfoTaskGenerator: Using optical parameter tester");
            return $this->opticalTester->generateTestParameters();
        }

        // Core device info parameters
        $names = [
            'InternetGatewayDevice.DeviceInfo.HardwareVersion',
            'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'InternetGatewayDevice.DeviceInfo.UpTime',
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
