
<?php

class DeviceInfoUpdater
{
    private $logger;
    private $db;
    private $logFile;

    public function __construct($logger = null)
    {
        $this->logger = $logger;
        $this->logFile = $_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt';
        
        // Get database connection
        require_once __DIR__ . '/../../config/database.php';
        $database = new Database();
        $this->db = $database->getConnection();
    }

    /**
     * Update device info in database from log file
     * 
     * @return bool Success status
     */
    public function updateFromLogFile()
    {
        if (!file_exists($this->logFile)) {
            $this->log("Log file not found: {$this->logFile}");
            return false;
        }
        
        $content = file_get_contents($this->logFile);
        if (empty($content)) {
            $this->log("Log file is empty");
            return false;
        }
        
        $this->log("Processing log file for device info updates");
        
        // Parse parameters from log file
        $params = [];
        $serialNumber = null;
        
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue; // Skip empty lines and comments
            }
            
            // Parse key-value pairs
            if (strpos($line, '=') !== false) {
                list($key, $value) = array_map('trim', explode('=', $line, 2));
                $params[$key] = $value;
                
                // Look for serial number specifically
                if (strpos($key, 'SerialNumber') !== false) {
                    $serialNumber = $value;
                }
            }
        }
        
        if (empty($serialNumber)) {
            $this->log("No serial number found in log file");
            return false;
        }
        
        // Map parameters to database columns
        $dbParams = $this->mapParamsToDbColumns($params);
        
        if (empty($dbParams)) {
            $this->log("No valid parameters found to update");
            return false;
        }
        
        // Update database
        return $this->updateDeviceInfo($serialNumber, $dbParams);
    }
    
    /**
     * Map TR-069 parameters to database columns
     * 
     * @param array $params TR-069 parameters
     * @return array Database column => value mapping
     */
    public function mapParamsToDbColumns($params)
    {
        $mapping = [
            'InternetGatewayDevice.DeviceInfo.SerialNumber' => 'serial_number',
            'InternetGatewayDevice.DeviceInfo.HardwareVersion' => 'hardware_version',
            'InternetGatewayDevice.DeviceInfo.SoftwareVersion' => 'software_version',
            'InternetGatewayDevice.DeviceInfo.UpTime' => 'uptime',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress' => 'ip_address',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID' => 'ssid',
            'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries' => 'connected_clients',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable' => 'wifi_enabled'
        ];
        
        $result = [];
        foreach ($params as $name => $value) {
            // For each parameter, check if it matches any mapping key
            foreach ($mapping as $paramName => $columnName) {
                if (strpos($name, $paramName) !== false) {
                    $result[$columnName] = $value;
                    break;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Update device info in the database
     * 
     * @param string $serialNumber Device serial number
     * @param array $params Database column => value mapping
     * @return bool Success status
     */
    public function updateDeviceInfo($serialNumber, $params)
    {
        if (empty($serialNumber) || empty($params)) {
            $this->log("Missing serial number or parameters for database update");
            return false;
        }
        
        try {
            $this->log("Updating device info for serial: $serialNumber with " . count($params) . " parameters");
            
            // First, check if device exists
            $checkStmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
            $checkStmt->execute([':serial' => $serialNumber]);
            $deviceId = $checkStmt->fetchColumn();
            
            if (!$deviceId) {
                // Create new device if it doesn't exist
                $this->log("Device not found, creating new device record");
                $insertStmt = $this->db->prepare("
                    INSERT INTO devices (serial_number, status, last_contact) 
                    VALUES (:serial, 'online', NOW())
                ");
                $insertStmt->execute([':serial' => $serialNumber]);
                $deviceId = $this->db->lastInsertId();
            }
            
            // Update device info
            $updateFields = [];
            $updateParams = [':serial' => $serialNumber];
            
            foreach ($params as $column => $value) {
                if ($column === 'serial_number') continue; // Skip serial number
                $updateFields[] = "$column = :$column";
                $updateParams[":$column"] = $value;
            }
            
            // Always update last_contact
            $updateFields[] = "last_contact = NOW()";
            $updateFields[] = "status = 'online'";
            
            if (empty($updateFields)) {
                $this->log("No fields to update");
                return false;
            }
            
            $sql = "UPDATE devices SET " . implode(", ", $updateFields) . " WHERE serial_number = :serial";
            $this->log("Update SQL: $sql");
            
            $updateStmt = $this->db->prepare($sql);
            $result = $updateStmt->execute($updateParams);
            
            $rowCount = $updateStmt->rowCount();
            $this->log("Database update " . ($result ? "successful" : "failed") . ", affected rows: $rowCount");
            
            return $result;
        } catch (Exception $e) {
            $this->log("Database error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log message using the provided logger or fallback
     */
    private function log($message)
    {
        if ($this->logger && method_exists($this->logger, 'logToFile')) {
            $this->logger->logToFile("DeviceInfoUpdater: $message");
        } else {
            // Fallback logging to error_log
            error_log("DeviceInfoUpdater: $message");
        }
    }
}
