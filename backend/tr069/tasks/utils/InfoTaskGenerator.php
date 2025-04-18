
<?php

class InfoTaskGenerator {
    private $logger;
    private $db;
    
    public function __construct($logger) {
        $this->logger = $logger;
        
        // Initialize database connection
        require_once __DIR__ . '/../../../../config/database.php';
        $database = new Database();
        $this->db = $database->getConnection();
    }

    private function updateDeviceInfo($data, $serialNumber) {
        try {
            $sql = "UPDATE devices SET 
                    hardware_version = :hardware_version,
                    software_version = :software_version,
                    uptime = :uptime,
                    ip_address = :ip_address,
                    connected_clients = :connected_clients,
                    ssid = :ssid,
                    last_contact = NOW()
                    WHERE serial_number = :serial_number";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':hardware_version' => $data['hardware_version'] ?? null,
                ':software_version' => $data['software_version'] ?? null,
                ':uptime' => $data['uptime'] ?? null,
                ':ip_address' => $data['ip_address'] ?? null,
                ':connected_clients' => $data['connected_clients'] ?? 0,
                ':ssid' => $data['ssid'] ?? null,
                ':serial_number' => $serialNumber
            ]);
            
            $this->logger->logToFile("Updated device info for serial: $serialNumber");
        } catch (Exception $e) {
            $this->logger->logToFile("Error updating device info: " . $e->getMessage());
        }
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
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Status'
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
            'parameterNames' => $names,
            'updateCallback' => function($params, $serialNumber) {
                // Process and map the parameters to database columns
                $deviceData = [];
                
                foreach ($params as $param) {
                    $name = $param[1];
                    $value = $param[2];
                    
                    switch ($name) {
                        case 'InternetGatewayDevice.DeviceInfo.HardwareVersion':
                            $deviceData['hardware_version'] = $value;
                            break;
                        case 'InternetGatewayDevice.DeviceInfo.SoftwareVersion':
                            $deviceData['software_version'] = $value;
                            break;
                        case 'InternetGatewayDevice.DeviceInfo.UpTime':
                            $deviceData['uptime'] = intval($value);
                            break;
                        case 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress':
                            $deviceData['ip_address'] = $value;
                            break;
                        case 'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries':
                            $deviceData['connected_clients'] = intval($value);
                            break;
                        case 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID':
                            $deviceData['ssid'] = $value;
                            break;
                    }
                }
                
                // Update the database
                if (!empty($deviceData)) {
                    $this->updateDeviceInfo($deviceData, $serialNumber);
                }
            }
        ];
    }
}

