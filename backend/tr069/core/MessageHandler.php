<?php
require_once __DIR__ . '/../responses/InformResponseGenerator.php';
require_once __DIR__ . '/../device_manager.php';
require_once __DIR__ . '/XMLGenerator.php';

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

            // Define critical parameters to request
            $parametersToRequest = [
                'InternetGatewayDevice.DeviceInfo.UpTime',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
                'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
                'InternetGatewayDevice.DeviceInfo.HardwareVersion'
            ];
            
            // Get the device ID for logging
            try {
                $stmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
                $stmt->execute([':serial' => $serialNumber]);
                $deviceId = $stmt->fetchColumn();
                
                if ($deviceId) {
                    $this->logger->logToFile("Will request parameters for device ID: $deviceId");
                } else {
                    $this->logger->logToFile("Warning: Device ID not found for serial: $serialNumber");
                }
            } catch (PDOException $e) {
                $this->logger->logToFile("Database error: " . $e->getMessage());
            }
            
            // Generate the InformResponse
            $responseGenerator = new InformResponseGenerator();
            $informResponse = $responseGenerator->createResponse($soapId);
            
            // Generate GetParameterValues XML with our requested parameters
            $getParamXml = XMLGenerator::generateGetParameterValuesXML(
                uniqid(), 
                $parametersToRequest
            );
            
            $this->logger->logToFile("Sending GetParameterValues request with InformResponse");
            
            // Build a compound SOAP body: InformResponse + GetParameterValues
            // We need to inject the GetParameterValues into the SOAP body before it closes
            $compound = str_replace(
                '</soapenv:Body>',
                $getParamXml . "\n  </soapenv:Body>",
                $informResponse
            );
            
            return $compound;
        }
        
        // Generate and return InformResponse if no serial number
        $responseGenerator = new InformResponseGenerator();
        return $responseGenerator->createResponse($soapId);
    }

    public function handleGetParameterValuesResponse($raw_post) {
        $this->logger->logToFile("=== Processing GetParameterValuesResponse ===");
        
        // Extract the SOAP ID
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        // Extract all parameter values
        preg_match_all('/<ParameterValueStruct>\s*<Name>(.*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>\s*<\/ParameterValueStruct>/s', $raw_post, $params, PREG_SET_ORDER);
        
        if (!empty($params)) {
            $this->logger->logToFile("Found " . count($params) . " parameters in response");
            
            $serialNumber = $this->sessionManager->getCurrentSessionDeviceSerial();
            if ($serialNumber) {
                $this->logger->logToFile("Found session for device: " . $serialNumber);
                
                // Get device ID
                $stmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
                $stmt->execute([':serial' => $serialNumber]);
                $deviceId = $stmt->fetchColumn();
                
                if ($deviceId) {
                    $updateValues = [];
                    
                    foreach ($params as $param) {
                        $paramName = trim($param[1]);
                        $paramValue = trim($param[2]);
                        
                        $this->logger->logToFile("Processing parameter: $paramName = $paramValue");
                        
                        // Store all parameters in the parameters table
                        try {
                            $stmt = $this->db->prepare("
                                INSERT INTO parameters (device_id, param_name, param_value, created_at, updated_at)
                                VALUES (:deviceId, :paramName, :paramValue, NOW(), NOW())
                                ON DUPLICATE KEY UPDATE param_value = :paramValue, updated_at = NOW()
                            ");
                            
                            $stmt->execute([
                                ':deviceId' => $deviceId,
                                ':paramName' => $paramName,
                                ':paramValue' => $paramValue
                            ]);
                            
                            $this->logger->logToFile("Stored parameter in parameters table");
                        } catch (PDOException $e) {
                            $this->logger->logToFile("Error storing parameter: " . $e->getMessage());
                        }
                        
                        // Update specific fields in the devices table
                        if (strpos($paramName, '.UpTime') !== false) {
                            $updateValues['uptime'] = (int)$paramValue;
                            $this->logger->logToFile("Will update uptime: $paramValue");
                        } else if (strpos($paramName, '.SSID') !== false) {
                            $updateValues['ssid'] = $paramValue;
                            $this->logger->logToFile("Will update SSID: $paramValue");
                        } else if (strpos($paramName, '.ExternalIPAddress') !== false) {
                            $updateValues['ip_address'] = $paramValue;
                            $this->logger->logToFile("Will update IP: $paramValue");
                        } else if (strpos($paramName, '.SoftwareVersion') !== false) {
                            $updateValues['software_version'] = $paramValue;
                            $this->logger->logToFile("Will update Software Version: $paramValue");
                        } else if (strpos($paramName, '.HardwareVersion') !== false) {
                            $updateValues['hardware_version'] = $paramValue;
                            $this->logger->logToFile("Will update Hardware Version: $paramValue");
                        }
                    }
                    
                    // Update the device record if we have values to update
                    if (!empty($updateValues)) {
                        $updateSql = "UPDATE devices SET ";
                        $updateParams = [':deviceId' => $deviceId];
                        
                        foreach ($updateValues as $field => $value) {
                            $updateSql .= "$field = :$field, ";
                            $updateParams[":$field"] = $value;
                        }
                        
                        $updateSql .= "updated_at = NOW() WHERE id = :deviceId";
                        
                        try {
                            $stmt = $this->db->prepare($updateSql);
                            $stmt->execute($updateParams);
                            $this->logger->logToFile("Updated device record with parameters: " . json_encode($updateValues));
                        } catch (PDOException $e) {
                            $this->logger->logToFile("Error updating device record: " . $e->getMessage());
                        }
                    }
                }
            }
        }
        
        // Return an empty response to complete this transaction
        return XMLGenerator::generateEmptyResponse($soapId);
    }

    public function handleEmptyPost() {
        $currentTask = $this->sessionManager->getCurrentTask();
        $this->logger->logToFile("Handling empty POST, current task: " . ($currentTask ? $currentTask['task_type'] : 'none'));
        
        // If we have a current task, handle it
        if ($currentTask) {
            $this->logger->logToFile("Processing task: " . $currentTask['task_type']);
            
            if ($currentTask['task_type'] === 'get_parameters') {
                // This is our special task to get essential parameters
                $this->logger->logToFile("Handling get_parameters task");
                
                $taskData = json_decode($currentTask['task_data'], true);
                if (isset($taskData['parameters']) && is_array($taskData['parameters'])) {
                    $parameterNames = $taskData['parameters'];
                    $this->logger->logToFile("Requesting parameters: " . implode(', ', $parameterNames));
                    
                    // Generate GetParameterValues request
                    $getParamRequest = XMLGenerator::generateGetParameterValuesXML(
                        uniqid(),
                        $parameterNames
                    );
                    
                    // Mark task as in progress
                    try {
                        $stmt = $this->db->prepare("
                            UPDATE device_tasks 
                            SET status = 'in_progress', updated_at = NOW() 
                            WHERE id = :taskId
                        ");
                        $stmt->execute([':taskId' => $currentTask['id']]);
                        $this->logger->logToFile("Marked get_parameters task as in_progress");
                    } catch (PDOException $e) {
                        $this->logger->logToFile("Error updating task status: " . $e->getMessage());
                    }
                    
                    return $getParamRequest;
                }
            } else {
                // Handle other task types
                $parameterRequest = $this->taskHandler->generateParameterValues(
                    $currentTask['task_type'],
                    $currentTask['task_data']
                );
                
                if ($parameterRequest) {
                    return XMLGenerator::generateSetParameterRequestXML(
                        uniqid(),
                        $parameterRequest['name'],
                        $parameterRequest['value'],
                        $parameterRequest['type']
                    );
                }
            }
        }
        
        // If no current task, check for pending get_parameters tasks
        $serialNumber = $this->sessionManager->getCurrentSessionDeviceSerial();
        if ($serialNumber) {
            $this->logger->logToFile("No current task, checking for pending get_parameters tasks for: " . $serialNumber);
            
            try {
                $stmt = $this->db->prepare("
                    SELECT dt.* FROM device_tasks dt
                    JOIN devices d ON dt.device_id = d.id
                    WHERE d.serial_number = :serial
                    AND dt.task_type = 'get_parameters'
                    AND dt.status = 'pending'
                    ORDER BY dt.created_at ASC
                    LIMIT 1
                ");
                $stmt->execute([':serial' => $serialNumber]);
                $task = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($task) {
                    $this->logger->logToFile("Found pending get_parameters task: " . $task['id']);
                    $this->sessionManager->setCurrentTask($task);
                    
                    $taskData = json_decode($task['task_data'], true);
                    if (isset($taskData['parameters']) && is_array($taskData['parameters'])) {
                        $parameterNames = $taskData['parameters'];
                        $this->logger->logToFile("Requesting parameters: " . implode(', ', $parameterNames));
                        
                        // Generate GetParameterValues request
                        $getParamRequest = XMLGenerator::generateGetParameterValuesXML(
                            uniqid(),
                            $parameterNames
                        );
                        
                        // Mark task as in progress
                        try {
                            $stmt = $this->db->prepare("
                                UPDATE device_tasks 
                                SET status = 'in_progress', updated_at = NOW() 
                                WHERE id = :taskId
                            ");
                            $stmt->execute([':taskId' => $task['id']]);
                            $this->logger->logToFile("Marked get_parameters task as in_progress");
                        } catch (PDOException $e) {
                            $this->logger->logToFile("Error updating task status: " . $e->getMessage());
                        }
                        
                        return $getParamRequest;
                    }
                } else {
                    $this->logger->logToFile("No pending get_parameters tasks found");
                }
            } catch (PDOException $e) {
                $this->logger->logToFile("Error checking for pending tasks: " . $e->getMessage());
            }
        }
        
        // Return empty response if no tasks to process
        return XMLGenerator::generateEmptyResponse(uniqid());
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
            $this->logger->logToFile("Found Software Version in Inform: " . $params['softwareVersion']);
        }
        
        // Extract hardware version
        if (preg_match('/<Name>InternetGatewayDevice\.DeviceInfo\.HardwareVersion<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/s', $raw_post, $matches)) {
            $params['hardwareVersion'] = trim($matches[1]);
            $this->logger->logToFile("Found Hardware Version in Inform: " . $params['hardwareVersion']);
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
