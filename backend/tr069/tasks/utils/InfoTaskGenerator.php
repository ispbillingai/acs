
<?php

class InfoTaskGenerator {
    private $logger;
    
    public function __construct($logger) {
        $this->logger = $logger;
    }

    public function generateParameters(array $data) {
        // Default parameter names to retrieve if not specified
        $names = $data['names'] ?? [
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'InternetGatewayDevice.DeviceInfo.HardwareVersion',
            'InternetGatewayDevice.DeviceInfo.UpTime',  
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries'
        ];

        $this->logger->logToFile("InfoTaskGenerator building " . count($names) . " parameters");

        return [
            'method' => 'GetParameterValues',
            'parameterNames' => $names
        ];
    }
}
