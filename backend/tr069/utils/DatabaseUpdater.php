
<?php

class DatabaseUpdater
{
    private $logger;
    private $db;

    public function __construct($logger)
    {
        $this->logger = $logger;
        
        // Get database connection
        require_once __DIR__ . '/../../../config/database.php';
        $database = new Database();
        $this->db = $database->getConnection();
    }

    /**
     * Update WiFi settings in the database
     * 
     * @param string $serialNumber Device serial number
     * @param array $wifiData WiFi data (ssid, password)
     * @return bool Success status
     */
    public function updateWifiSettings($serialNumber, $wifiData)
    {
        if (empty($serialNumber) || empty($wifiData)) {
            $this->log("Missing serial number or WiFi data for database update");
            return false;
        }

        try {
            $this->log("Updating WiFi settings for device: $serialNumber");
            $this->log("WiFi data: " . json_encode($wifiData));
            
            $updateFields = [];
            $params = [':serial' => $serialNumber];
            
            if (isset($wifiData['ssid'])) {
                $updateFields[] = "ssid = :ssid";
                $params[':ssid'] = $wifiData['ssid'];
            }
            
            if (isset($wifiData['password'])) {
                $updateFields[] = "ssid_password = :password";
                $params[':password'] = $wifiData['password'];
            }
            
            if (empty($updateFields)) {
                $this->log("No WiFi fields to update");
                return false;
            }
            
            $sql = "UPDATE devices SET " . implode(", ", $updateFields) . " WHERE serial_number = :serial";
            $this->log("Update SQL: $sql");
            
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute($params);
            
            if ($result) {
                $rowCount = $stmt->rowCount();
                $this->log("Database update successful, affected rows: $rowCount");
                return true;
            } else {
                $this->log("Database update failed");
                return false;
            }
        } catch (\PDOException $e) {
            $this->log("Database error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log message using the provided logger
     */
    private function log($message)
    {
        if ($this->logger && method_exists($this->logger, 'logToFile')) {
            $this->logger->logToFile("DatabaseUpdater: $message");
        }
    }
}
