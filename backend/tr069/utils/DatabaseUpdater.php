
<?php

class DatabaseUpdater {
    private $db;
    private $logger;
    
    public function __construct($logger) {
        $this->logger = $logger;
        
        // Initialize database connection
        require_once __DIR__ . '/../../config/database.php';
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    /**
     * Update device information in the database
     * This function is called after parameter values are retrieved
     */
    public function updateDeviceFromParameters($serialNumber, $parameters) {
        if (empty($serialNumber) || empty($parameters)) {
            $this->log("Cannot update device: missing serial number or parameters");
            return false;
        }
        
        $this->log("Updating device info for serial: $serialNumber");
        
        // Create a map of parameter name patterns to database columns
        $parameterMap = [
            'HardwareVersion' => 'hardware_version',
            'SoftwareVersion' => 'software_version',
            'UpTime' => 'uptime',
            'ExternalIPAddress' => 'ip_address',
            'HostNumberOfEntries' => 'connected_clients',
            'SSID' => 'ssid',
            'Enable' => 'wifi_enabled'
        ];
        
        // Extract values from parameters
        $deviceData = [];
        foreach ($parameters as $param) {
            if (!isset($param['name']) || !isset($param['value'])) {
                continue;
            }
            
            $paramName = $param['name'];
            $paramValue = $param['value'];
            
            foreach ($parameterMap as $pattern => $column) {
                if (strpos($paramName, $pattern) !== false) {
                    $deviceData[$column] = $paramValue;
                    $this->log("Mapped $paramName to $column = $paramValue");
                }
            }
        }
        
        // Always update last_contact
        $deviceData['last_contact'] = date('Y-m-d H:i:s');
        
        // If we have data to update, execute the update
        if (!empty($deviceData)) {
            try {
                // Convert data to SQL update
                $setStatements = [];
                $params = [':serial' => $serialNumber];
                
                foreach ($deviceData as $column => $value) {
                    $setStatements[] = "$column = :$column";
                    $params[":$column"] = $value;
                }
                
                $sql = "UPDATE devices SET " . implode(', ', $setStatements) . " WHERE serial_number = :serial";
                $this->log("SQL: $sql");
                $this->log("Params: " . print_r($params, true));
                
                $stmt = $this->db->prepare($sql);
                $result = $stmt->execute($params);
                
                if ($result) {
                    $this->log("Device $serialNumber updated with " . implode(', ', array_keys($deviceData)));
                    return true;
                } else {
                    $this->log("Error executing update: " . print_r($stmt->errorInfo(), true));
                    return false;
                }
            } catch (\PDOException $e) {
                $this->log("Database error: " . $e->getMessage());
                return false;
            }
        } else {
            $this->log("No device data to update");
            return false;
        }
    }
    
    /**
     * Helper method to log messages
     */
    private function log($message) {
        if ($this->logger && method_exists($this->logger, 'logToFile')) {
            $this->logger->logToFile("DatabaseUpdater: $message");
        }
    }
}
