
<?php

require_once __DIR__ . '/../../utils/DeviceInfoUpdater.php';

class InfoTaskGenerator {
    private $logger;
    private $dbUpdater;
    
    public function __construct($logger) {
        $this->logger = $logger;
        $this->dbUpdater = new DeviceInfoUpdater($logger);
    }

    public function generateParameters(array $data) {
        // Core device info parameters
        $names = [
            'InternetGatewayDevice.DeviceInfo.HardwareVersion',
            'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'InternetGatewayDevice.DeviceInfo.UpTime',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DNSServers',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.SubnetMask',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DefaultGateway',
            'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries',
            // Also get WiFi information
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable'
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

        // If serial number is provided, register a completion callback to update database
        if (isset($data['serial_number']) && !empty($data['serial_number'])) {
            $this->logger->logToFile("Serial number provided: " . $data['serial_number'] . ", will update database after retrieval");
            
            // Store serial number for potential database update
            $serialNumber = $data['serial_number'];
            
            // Add callback function to be called after parameter retrieval
            $updateCallback = function($responseData) use ($serialNumber) {
                $this->logger->logToFile("Processing parameter response data for database update");
                
                // Extract parameters from response data
                $params = [];
                if (is_array($responseData) && !empty($responseData)) {
                    foreach ($responseData as $paramName => $paramValue) {
                        $params[$paramName] = $paramValue;
                    }
                    
                    // Update database with parameters
                    $this->dbUpdater->updateDeviceInfo($serialNumber, $this->dbUpdater->mapParamsToDbColumns($params));
                }
            };
            
            return [
                'method' => 'GetParameterValues',
                'parameterNames' => $names,
                'updateCallback' => $updateCallback
            ];
        }

        return [
            'method' => 'GetParameterValues',
            'parameterNames' => $names
        ];
    }
}
