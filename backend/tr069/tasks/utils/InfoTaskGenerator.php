<?php
class InfoTaskGenerator {
    private $logger;
    
    public function __construct($logger) {
        $this->logger = $logger;
    }

    public function generateParameters(array $data) {
        $requests = [];

        // Check if this is a follow-up request for host details
        if (isset($data['host_count']) && is_numeric($data['host_count']) && $data['host_count'] > 0) {
            // Follow-up request with known host count
            $hostCount = (int)$data['host_count'];
            $this->logger->logToFile("Follow-up request with known host count: {$hostCount}");
            
            // Add one request per host with all host parameters
            for ($i = 1; $i <= $hostCount; $i++) {
                $hostParams = [
                    "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.Active",
                    "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.IPAddress",
                    "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.HostName",
                    "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.MACAddress"
                ];
                $this->logger->logToFile("Adding host parameters for host {$i}");
                $requests[] = [
                    'method' => 'GetParameterValues',
                    'parameterNames' => $hostParams,
                    'context' => "Host {$i}"
                ];
            }
            
            $this->logger->logToFile("InfoTaskGenerator building " . count($requests) . " host parameter requests");
            return $requests;
        }

        // Initial request: define parameter groups
        $coreParams = [
            'InternetGatewayDevice.DeviceInfo.HardwareVersion',
            'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'InternetGatewayDevice.DeviceInfo.UpTime',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.',
            'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries'
        ];

        $wanIpParams = [
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DNSServers',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.SubnetMask',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DefaultGateway'
        ];

        $wanPppoeParams = [
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.DNSServers',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.DefaultGateway'
        ];

        $gponParams = [
            'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.TXPower',
            'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.RXPower'
        ];

        // Add core parameters as one request (assumed always supported)
        $this->logger->logToFile("Adding core parameters");
        $requests[] = [
            'method' => 'GetParameterValues',
            'parameterNames' => $coreParams,
            'context' => 'Core'
        ];

        // Add WANIPConnection parameters as one request
        $this->logger->logToFile("Adding WANIPConnection parameters");
        $requests[] = [
            'method' => 'GetParameterValues',
            'parameterNames' => $wanIpParams,
            'context' => 'WANIPConnection'
        ];

        // Add WANPPPConnection parameters as one request
        $this->logger->logToFile("Adding WANPPPConnection parameters");
        $requests[] = [
            'method' => 'GetParameterValues',
            'parameterNames' => $wanPppoeParams,
            'context' => 'WANPPPConnection'
        ];

        // Add GPON parameters as individual requests
        foreach ($gponParams as $param) {
            $this->logger->logToFile("Adding GPON parameter: {$param}");
            $requests[] = [
                'method' => 'GetParameterValues',
                'parameterNames' => [$param],
                'context' => 'GPON'
            ];
        }

        $this->logger->logToFile("InfoTaskGenerator building " . count($requests) . " parameter requests");
        return $requests;
    }
}