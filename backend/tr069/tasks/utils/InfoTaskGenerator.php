
<?php
class InfoTaskGenerator {
    private $logger;
    
    public function __construct($logger) {
        $this->logger = $logger;
    }

    public function generateParameters(array $data) {
        // Check if this is a follow-up request for host details
        if (isset($data['host_count']) && is_numeric($data['host_count']) && $data['host_count'] > 0) {
            // This is a follow-up request with known host count
            $hostCount = (int)$data['host_count'];
            $this->logger->logToFile("Follow-up request with known host count: {$hostCount}");
            
            $names = [];
            // Add parameters for each connected host
            for ($i = 1; $i <= $hostCount; $i++) {
                $names[] = "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.Active";
                $names[] = "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.IPAddress";
                $names[] = "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.HostName";
                $names[] = "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.MACAddress";
                $this->logger->logToFile("Adding parameters for host {$i}");
            }
            
            $this->logger->logToFile("InfoTaskGenerator building " . count($names) . " host parameters");
            foreach ($names as $param) {
                $this->logger->logToFile("Parameter to retrieve: " . $param);
            }
            
            return [
                'method' => 'GetParameterValues',
                'parameterNames' => $names
            ];
        }
        
        // Original initial request: first get core parameters including HostNumberOfEntries
        $names = [
            'InternetGatewayDevice.DeviceInfo.HardwareVersion',
            'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'InternetGatewayDevice.DeviceInfo.UpTime',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',

           // 'InternetGatewayDevice.WANDevice.1.',//  When i want to check which parameters are correct
            'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.TXPower',
            'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.RXPower',
            'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries'
        ];
        
        $this->logger->logToFile("InfoTaskGenerator building " . count($names) . " core parameters");
        foreach ($names as $param) {
            $this->logger->logToFile("Parameter to retrieve: " . $param);
        }

        return [
            'method' => 'GetParameterValues',
            'parameterNames' => $names
        ];
    }
}
