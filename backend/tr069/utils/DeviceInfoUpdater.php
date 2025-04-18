
<?php

class DeviceInfoUpdater 
{
    private $logger;
    private $db;
    private $logFile;

    public function __construct($logger = null) 
    {
        $this->logger = $logger;
        $this->logFile = $_SERVER['DOCUMENT_ROOT'] . '/device_info.log';
        
        // Initialize database connection
        require_once __DIR__ . '/../../../config/database.php';
        $database = new Database();
        $this->db = $database->getConnection();
        
        // Ensure log file exists
        if (!file_exists($this->logFile)) {
            touch($this->logFile);
            chmod($this->logFile, 0666);
        }
    }
    
    /**
     * Process and update device information from router_ssids.txt file
     */
    public function updateFromLogFile() 
    {
        $this->log("Starting device info update from log file");
        $logFilePath = $_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt';
        
        if (!file_exists($logFilePath)) {
            $this->log("Log file not found: $logFilePath");
            return false;
        }
        
        $content = file_get_contents($logFilePath);
        if (empty($content)) {
            $this->log("Log file is empty");
            return false;
        }
        
        $this->log("Processing log file content");
        
        // Extract parameters
        $params = [];
        $serialNumber = null;
        
        // Parse lines like "Parameter = Value"
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue; // Skip comments and empty lines
            }
            
            $parts = explode(' = ', $line, 2);
            if (count($parts) === 2) {
                $paramName = trim($parts[0]);
                $paramValue = trim($parts[1]);
                
                $params[$paramName] = $paramValue;
                
                // Check if this is serial number
                if (strpos($paramName, 'SerialNumber') !== false) {
                    $serialNumber = $paramValue;
                }
            }
        }
        
        if (empty($serialNumber)) {
            $this->log("No serial number found in log file");
            return false;
        }
        
        $this->log("Found device with serial number: $serialNumber");
        $this->log("Extracted parameters: " . json_encode($params));
        
        // Map parameters to database columns
        $dbParams = $this->mapParamsToDbColumns($params);
        
        if (empty($dbParams)) {
            $this->log("No database parameters mapped");
            return false;
        }
        
        // Update database
        return $this->updateDeviceInfo($serialNumber, $dbParams);
    }
    
    /**
     * Map TR-069 parameters to database columns
     */
    private function mapParamsToDbColumns($params) 
    {
        $dbParams = [];
        $mapping = [
            'InternetGatewayDevice.DeviceInfo.HardwareVersion' => 'hardware_version',
            'InternetGatewayDevice.DeviceInfo.SoftwareVersion' => 'software_version',
            'InternetGatewayDevice.DeviceInfo.UpTime' => 'uptime',
            'InternetGatewayDevice.DeviceInfo.Manufacturer' => 'manufacturer',
            'InternetGatewayDevice.DeviceInfo.ProductClass' => 'model_name',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress' => 'ip_address',
            'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries' => 'connected_clients',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID' => 'ssid'
        ];
        
        foreach ($mapping as $tr069Param => $dbColumn) {
            if (isset($params[$tr069Param]) && !empty($params[$tr069Param])) {
                $dbParams[$dbColumn] = $params[$tr069Param];
            }
        }
        
        return $dbParams;
    }
    
    /**
     * Update device information in database
     */
    public function updateDeviceInfo($serialNumber, $params) 
    {
        if (empty($serialNumber) || empty($params)) {
            $this->log("Missing serial number or parameters");
            return false;
        }
        
        try {
            $this->log("Updating device info for: $serialNumber");
            
            $updateFields = [];
            $queryParams = [':serial' => $serialNumber];
            
            foreach ($params as $column => $value) {
                $updateFields[] = "$column = :$column";
                $queryParams[":$column"] = $value;
            }
            
            // Also update last_contact time
            $updateFields[] = "last_contact = NOW()";
            
            $sql = "UPDATE devices SET " . implode(", ", $updateFields) . " WHERE serial_number = :serial";
            $this->log("Update SQL: $sql");
            
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute($queryParams);
            
            if ($result) {
                $rowCount = $stmt->rowCount();
                $this->log("Device info update successful, affected rows: $rowCount");
                return true;
            } else {
                $this->log("Device info update failed");
                return false;
            }
        } catch (\PDOException $e) {
            $this->log("Database error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log message to file and using the provided logger
     */
    private function log($message) 
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] DeviceInfoUpdater: $message\n";
        
        // Log to dedicated file
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        
        // Also use the provided logger if available
        if ($this->logger && method_exists($this->logger, 'logToFile')) {
            $this->logger->logToFile("DeviceInfoUpdater: $message");
        }
    }
}
