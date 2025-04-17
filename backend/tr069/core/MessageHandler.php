
<?php
require_once __DIR__ . '/../responses/InformResponseGenerator.php';
require_once __DIR__ . '/../device_manager.php';

class MessageHandler {
    private $logger;
    private $sessionManager;
    private $taskHandler;
    private $db;

    public function __construct($db, $logger, $sessionManager, $taskHandler) {
        $this->db = $db;
        $this->logger = $logger;
        $this->sessionManager = $sessionManager;
        $this->taskHandler = $taskHandler;
    }

    public function handleInform($raw_post) {
        // Extract the SOAP ID
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        // Extract device serial number
        preg_match('/<SerialNumber>(.*?)<\/SerialNumber>/s', $raw_post, $serialMatches);
        $serialNumber = isset($serialMatches[1]) ? trim($serialMatches[1]) : null;
        
        if ($serialNumber) {
            $this->logger->logToFile("Device inform received - Serial: $serialNumber");
            
            // Extract additional device parameters from inform message
            $deviceParams = $this->extractDeviceParams($raw_post);
            $this->logger->logToFile("Extracted parameters: " . json_encode($deviceParams));
            
            // Update device with all extracted parameters
            $this->updateDeviceWithParams($serialNumber, $deviceParams);
            
            $this->sessionManager->startNewSession($serialNumber);
            
            // Look for pending tasks
            $pendingTasks = $this->taskHandler->getPendingTasks($serialNumber);
            if (!empty($pendingTasks)) {
                $this->sessionManager->setCurrentTask($pendingTasks[0]);
            }
            
            // After processing the Inform, we should generate a GetParameterValues request
            // for additional parameters we want to track
            $this->logger->logToFile("Checking if we need to request additional parameters");
            
            $needsParameterRequest = true; // Flag to determine if we need to request parameters
            
            // If we received important parameters already, we might not need to request them again
            if (isset($deviceParams['ipAddress']) && isset($deviceParams['ssid']) && isset($deviceParams['uptime'])) {
                $this->logger->logToFile("All important parameters already received in Inform message");
                $needsParameterRequest = false;
            }
            
            if ($needsParameterRequest) {
                $this->logger->logToFile("Important parameters missing, creating parameter request task");
                
                // Create a special task to get these parameters on the next request
                try {
                    // First, get the device ID
                    $stmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
                    $stmt->execute([':serial' => $serialNumber]);
                    $deviceId = $stmt->fetchColumn();
                    
                    if ($deviceId) {
                        $this->logger->logToFile("Creating parameter request task for device ID: $deviceId");
                        
                        // Define parameters to request
                        $parametersToRequest = [
                            'InternetGatewayDevice.DeviceInfo.UpTime',
                            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress'
                        ];
                        
                        // Insert a parameter request task
                        $stmt = $this->db->prepare("
                            INSERT INTO device_tasks 
                                (device_id, task_type, task_data, status, created_at, updated_at) 
                            VALUES 
                                (:deviceId, 'get_parameters', :taskData, 'pending', NOW(), NOW())
                        ");
                        
                        $stmt->execute([
                            ':deviceId' => $deviceId,
                            ':taskData' => json_encode(['parameters' => $parametersToRequest])
                        ]);
                        
                        $taskId = $this->db->lastInsertId();
                        $this->logger->logToFile("Created parameter request task with ID: $taskId");
                    } else {
                        $this->logger->logToFile("Warning: Device ID not found for serial: $serialNumber");
                    }
                } catch (PDOException $e) {
                    $this->logger->logToFile("Database error creating parameter request task: " . $e->getMessage());
                }
            }
        }
        
        // Generate and return InformResponse
        $responseGenerator = new InformResponseGenerator();
        return $responseGenerator->createResponse($soapId);
    }

    private function extractDeviceParams($raw_post) {
        $params = [];
        
        // Extract manufacturer
        preg_match('/<Manufacturer>(.*?)<\/Manufacturer>/s', $raw_post, $matches);
        if (isset($matches[1])) {
            $params['manufacturer'] = trim($matches[1]);
        }
        
        // Extract model name
        preg_match('/<ProductClass>(.*?)<\/ProductClass>/s', $raw_post, $matches);
        if (isset($matches[1])) {
            $params['modelName'] = trim($matches[1]);
        }
        
        // Extract IP address
        if (preg_match('/<Name>InternetGatewayDevice\.WANDevice\.1\.WANConnectionDevice\.1\.WANIPConnection\.1\.ExternalIPAddress<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/s', $raw_post, $matches)) {
            $params['ipAddress'] = trim($matches[1]);
            $this->logger->logToFile("Found IP Address in Inform: " . $params['ipAddress']);
        }
        
        // Extract SSID
        if (preg_match('/<Name>InternetGatewayDevice\.LANDevice\.1\.WLANConfiguration\.1\.SSID<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/s', $raw_post, $matches)) {
            $params['ssid'] = trim($matches[1]);
            $this->logger->logToFile("Found SSID in Inform: " . $params['ssid']);
        }
        
        // Extract uptime
        if (preg_match('/<Name>InternetGatewayDevice\.DeviceInfo\.UpTime<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/s', $raw_post, $matches)) {
            $params['uptime'] = (int)trim($matches[1]);
            $this->logger->logToFile("Found Uptime in Inform: " . $params['uptime']);
        }
        
        // Extract software version
        if (preg_match('/<Name>InternetGatewayDevice\.DeviceInfo\.SoftwareVersion<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/s', $raw_post, $matches)) {
            $params['softwareVersion'] = trim($matches[1]);
        }
        
        // Extract hardware version
        if (preg_match('/<Name>InternetGatewayDevice\.DeviceInfo\.HardwareVersion<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/s', $raw_post, $matches)) {
            $params['hardwareVersion'] = trim($matches[1]);
        }
        
        // Additional logging for debugging all parameters
        preg_match_all('/<ParameterValueStruct>\s*<Name>(.*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>\s*<\/ParameterValueStruct>/s', $raw_post, $allParams, PREG_SET_ORDER);
        
        if (!empty($allParams)) {
            $this->logger->logToFile("All parameters in Inform message:");
            foreach ($allParams as $param) {
                $paramName = trim($param[1]);
                $paramValue = trim($param[2]);
                $this->logger->logToFile("  Parameter: $paramName = $paramValue");
            }
        } else {
            $this->logger->logToFile("No parameters found in ParameterValueStruct blocks");
        }
        
        return $params;
    }

    private function updateDeviceWithParams($serialNumber, $params) {
        try {
            // First, check if the device exists
            $stmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
            $stmt->execute([':serial' => $serialNumber]);
            $deviceId = $stmt->fetchColumn();
            
            if (!$deviceId) {
                // Create a new device record if it doesn't exist
                $this->logger->logToFile("Device not found, creating new entry for serial: $serialNumber");
                $insertFields = ['serial_number', 'status', 'last_contact'];
                $insertValues = [':serial' => $serialNumber, ':status' => 'online', ':lastContact' => date('Y-m-d H:i:s')];
                
                foreach ($params as $key => $value) {
                    $dbField = $this->mapParamToDbField($key);
                    if ($dbField) {
                        $insertFields[] = $dbField;
                        $insertValues[':' . $key] = $value;
                    }
                }
                
                $sql = "INSERT INTO devices (" . implode(', ', $insertFields) . ") 
                        VALUES (:" . implode(', :', array_keys($insertValues)) . ")";
                
                $this->logger->logToFile("Creating device with SQL: $sql");
                $stmt = $this->db->prepare($sql);
                $stmt->execute($insertValues);
            } else {
                // Update existing device
                $this->logger->logToFile("Updating device with ID: $deviceId and Serial: $serialNumber");
                
                $updateFields = ['status = :status', 'last_contact = :lastContact'];
                $updateValues = [':status' => 'online', ':lastContact' => date('Y-m-d H:i:s'), ':serial' => $serialNumber];
                
                foreach ($params as $key => $value) {
                    $dbField = $this->mapParamToDbField($key);
                    if ($dbField) {
                        $updateFields[] = "$dbField = :$key";
                        $updateValues[':' . $key] = $value;
                        $this->logger->logToFile("Will update field: $dbField with value: $value");
                    }
                }
                
                $sql = "UPDATE devices SET " . implode(', ', $updateFields) . " WHERE serial_number = :serial";
                
                $this->logger->logToFile("Updating device with SQL: $sql");
                $this->logger->logToFile("Parameter values: " . json_encode($updateValues));
                
                $stmt = $this->db->prepare($sql);
                $result = $stmt->execute($updateValues);
                
                if ($result) {
                    $this->logger->logToFile("Database update successful");
                } else {
                    $errorInfo = $stmt->errorInfo();
                    $this->logger->logToFile("Database update failed: " . $errorInfo[2]);
                }
            }
            
            $this->logger->logToFile("Device $serialNumber updated successfully with parameters: " . json_encode($params));
        } catch (PDOException $e) {
            $this->logger->logToFile("Database error updating device: " . $e->getMessage());
        }
    }
    
    private function mapParamToDbField($paramName) {
        $mapping = [
            'manufacturer' => 'manufacturer',
            'modelName' => 'model_name',
            'ipAddress' => 'ip_address',
            'ssid' => 'ssid',
            'uptime' => 'uptime',
            'softwareVersion' => 'software_version',
            'hardwareVersion' => 'hardware_version'
        ];
        
        return isset($mapping[$paramName]) ? $mapping[$paramName] : null;
    }
}
