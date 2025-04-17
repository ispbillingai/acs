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
        
        error_log("TR-069 MessageHandler: Initialized with ID: " . spl_object_hash($this));
    }

    public function handleInform($raw_post)
    {
        error_log("TR-069 MessageHandler: handleInform called");
        $this->logger->logToFile("======= START HANDLING INFORM =======");
        error_log("TR-069 MessageHandler: Starting to handle Inform message");
    
        /* -------------------------------------------------
         * 1.  Extract SOAP‑ID and SerialNumber
         * ------------------------------------------------*/
        preg_match('~<cwmp:ID [^>]*>(.*?)</cwmp:ID>~', $raw_post, $m);
        $soapId = $m[1] ?? '1';
    
        preg_match('~<SerialNumber>(.*?)</SerialNumber>~s', $raw_post, $m);
        $serialNumber = isset($m[1]) ? trim($m[1]) : null;
    
        $this->logger->logToFile("SOAP‑ID: $soapId   Serial: " . ($serialNumber ?: 'none'));
        error_log("TR-069 MessageHandler: Extracted SOAP-ID: $soapId and Serial: " . ($serialNumber ?: 'none'));
    
        // Basic debugging to retrieve.log to verify it works
        $retrieveLog = __DIR__ . '/../../../retrieve.log';
        if (!file_exists($retrieveLog)) {
            error_log("TR-069 MessageHandler: Creating retrieve.log at " . $retrieveLog);
            touch($retrieveLog);
            chmod($retrieveLog, 0666);
        }
        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " MessageHandler: handleInform called with SOAP-ID: $soapId, Serial: " . ($serialNumber ?: 'none') . "\n", FILE_APPEND);
        
        /* -------------------------------------------------
         * 2.  If serial present, update DB and build compound
         * ------------------------------------------------*/
        if ($serialNumber) {
            error_log("TR-069 MessageHandler: Processing inform for device with serial: $serialNumber");
            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Processing inform for device: $serialNumber\n", FILE_APPEND);
            
            /* quick params from Inform, DB update, session start */
            $deviceParams = $this->extractDeviceParams($raw_post);
            $this->updateDeviceWithParams($serialNumber, $deviceParams);
            $this->sessionManager->startNewSession($serialNumber);
    
            /* always request these parameters - CRITICAL for dashboard display */
            $want = [
                'InternetGatewayDevice.DeviceInfo.UpTime',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
                'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
                'InternetGatewayDevice.DeviceInfo.HardwareVersion'
            ];
            $this->logger->logToFile("Will request parameters: " . implode(', ', $want));
            error_log("TR-069 MessageHandler: Will request parameters: " . implode(', ', $want));
            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Parameters to request: " . implode(', ', $want) . "\n", FILE_APPEND);
    
            /* IMPORTANT CHANGE: Use the NEW compound method instead of separate calls */
            $compound = XMLGenerator::generateCompoundInformResponseWithGPV($soapId, $want);
            
            $this->logger->logToFile("Generated compound response with BOTH InformResponse AND GetParameterValues");
            error_log("TR-069 MessageHandler: Generated compound response with BOTH InformResponse AND GetParameterValues");
            error_log("TR-069 MessageHandler: Compound response length: " . strlen($compound));
            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Generated compound response length: " . strlen($compound) . "\n", FILE_APPEND);
            
            // Write the full response to a file for debugging
            $logDir = __DIR__ . '/../../logs';
            if (!is_dir($logDir)) {
                error_log("TR-069 MessageHandler: Creating logs directory at " . $logDir);
                mkdir($logDir, 0777, true);
            }
            $responseLogFile = $logDir . '/tr069_response_' . date('Ymd_His') . '.xml';
            file_put_contents($responseLogFile, $compound);
            error_log("TR-069 MessageHandler: Saved full response to file: $responseLogFile");
            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Full response saved to: $responseLogFile\n", FILE_APPEND);
    
            $this->logger->logToFile("======= END HANDLING INFORM (returning compound) =======");
            error_log("TR-069 MessageHandler: END HANDLING INFORM (returning compound)");
            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " END HANDLING INFORM - returning response\n", FILE_APPEND);
            return $compound;               // <-- single return in this branch
        }
    
        /* -------------------------------------------------
         * 3.  Fallback: no serial → plain InformResponse
         * ------------------------------------------------*/
        error_log("TR-069 MessageHandler: No serial number found - generating plain InformResponse");
        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " No serial number found - generating plain InformResponse\n", FILE_APPEND);
        
        $respGen = new InformResponseGenerator();
        $plain = $respGen->createResponse($soapId);
        $this->logger->logToFile("No serial – plain InformResponse sent");
        error_log("TR-069 MessageHandler: No serial number found - sending plain InformResponse");
        $this->logger->logToFile("======= END HANDLING INFORM =======");
        error_log("TR-069 MessageHandler: END HANDLING INFORM (plain response)");
        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Returning plain InformResponse\n", FILE_APPEND);
        return $plain;
    }
    
    public function handleGetParameterValuesResponse($raw_post) {
        error_log("TR-069 MessageHandler: handleGetParameterValuesResponse called");
        $this->logger->logToFile("======= START HANDLING GetParameterValuesResponse =======");
        error_log("TR-069: *** RECEIVED GetParameterValuesResponse ***");
        error_log("TR-069: Raw response first 300 chars: " . substr($raw_post, 0, 300));
        
        // Append to retrieve.log
        $retrieveLog = __DIR__ . '/../../../retrieve.log';
        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " HANDLING GetParameterValuesResponse\n", FILE_APPEND);
        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Raw response first 300 chars: " . substr($raw_post, 0, 300) . "\n", FILE_APPEND);
        
        // Create a backup of the raw response for debugging
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            error_log("TR-069 MessageHandler: Creating logs directory at " . $logDir);
            mkdir($logDir, 0777, true);
        }
        $responseLogFile = $logDir . '/tr069_gpv_response_' . date('Ymd_His') . '.xml';
        file_put_contents($responseLogFile, $raw_post);
        error_log("TR-069 MessageHandler: Saved raw GPV response to file: $responseLogFile");
        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Saved raw GPV response to: $responseLogFile\n", FILE_APPEND);
        
        // Extract the SOAP ID
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        error_log("TR-069 MessageHandler: Extracted SOAP ID: $soapId");
        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Extracted SOAP ID: $soapId\n", FILE_APPEND);
        
        // Extract all parameter values
        preg_match_all('/<ParameterValueStruct>\s*<Name>(.*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>\s*<\/ParameterValueStruct>/s', $raw_post, $params, PREG_SET_ORDER);
        
        if (!empty($params)) {
            error_log("TR-069 MessageHandler: Found " . count($params) . " parameters in response");
            $this->logger->logToFile("Found " . count($params) . " parameters in response");
            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Found " . count($params) . " parameters in response\n", FILE_APPEND);
            
            $serialNumber = $this->sessionManager->getCurrentSessionDeviceSerial();
            if ($serialNumber) {
                error_log("TR-069 MessageHandler: Found session for device: " . $serialNumber);
                $this->logger->logToFile("Found session for device: " . $serialNumber);
                file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Found session for device: " . $serialNumber . "\n", FILE_APPEND);
                
                // Get device ID
                $stmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
                $stmt->execute([':serial' => $serialNumber]);
                $deviceId = $stmt->fetchColumn();
                
                if ($deviceId) {
                    error_log("TR-069 MessageHandler: Found device ID: " . $deviceId);
                    $this->logger->logToFile("Found device ID: " . $deviceId);
                    file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Found device ID: " . $deviceId . "\n", FILE_APPEND);
                    $updateValues = [];
                    
                    foreach ($params as $param) {
                        $paramName = trim($param[1]);
                        $paramValue = trim($param[2]);
                        
                        error_log("TR-069 MessageHandler: Processing parameter: $paramName = $paramValue");
                        $this->logger->logToFile("Processing parameter: $paramName = $paramValue");
                        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Processing parameter: $paramName = $paramValue\n", FILE_APPEND);
                        
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
                            error_log("TR-069 MessageHandler: Stored parameter in parameters table: $paramName");
                            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Stored parameter: $paramName in database\n", FILE_APPEND);
                        } catch (PDOException $e) {
                            $this->logger->logToFile("Error storing parameter: " . $e->getMessage());
                            error_log("TR-069 ERROR: Failed to store parameter: " . $e->getMessage());
                            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " ERROR storing parameter: " . $e->getMessage() . "\n", FILE_APPEND);
                        }
                        
                        // Update specific fields in the devices table
                        if (strpos($paramName, '.UpTime') !== false) {
                            $updateValues['uptime'] = (int)$paramValue;
                            $this->logger->logToFile("Will update uptime: $paramValue");
                            error_log("TR-069 MessageHandler: Will update uptime: $paramValue");
                            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Will update uptime: $paramValue\n", FILE_APPEND);
                        } else if (strpos($paramName, '.SSID') !== false) {
                            $updateValues['ssid'] = $paramValue;
                            $this->logger->logToFile("Will update SSID: $paramValue");
                            error_log("TR-069 MessageHandler: Will update SSID: $paramValue");
                            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Will update SSID: $paramValue\n", FILE_APPEND);
                        } else if (strpos($paramName, '.ExternalIPAddress') !== false) {
                            $updateValues['ip_address'] = $paramValue;
                            $this->logger->logToFile("Will update IP: $paramValue");
                            error_log("TR-069 MessageHandler: Will update IP: $paramValue");
                            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Will update IP: $paramValue\n", FILE_APPEND);
                        } else if (strpos($paramName, '.SoftwareVersion') !== false) {
                            $updateValues['software_version'] = $paramValue;
                            $this->logger->logToFile("Will update Software Version: $paramValue");
                            error_log("TR-069 MessageHandler: Will update Software Version: $paramValue");
                            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Will update Software Version: $paramValue\n", FILE_APPEND);
                        } else if (strpos($paramName, '.HardwareVersion') !== false) {
                            $updateValues['hardware_version'] = $paramValue;
                            $this->logger->logToFile("Will update Hardware Version: $paramValue");
                            error_log("TR-069 MessageHandler: Will update Hardware Version: $paramValue");
                            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Will update Hardware Version: $paramValue\n", FILE_APPEND);
                        }
                    }
                    
                    // Update the device record if we have values to update
                    if (!empty($updateValues)) {
                        error_log("TR-069 MessageHandler: Updating device record with parameters: " . json_encode($updateValues));
                        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Updating device record with: " . json_encode($updateValues) . "\n", FILE_APPEND);
                        
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
                            error_log("TR-069 MessageHandler: Updated device record with parameters: " . json_encode($updateValues));
                            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Successfully updated device record\n", FILE_APPEND);
                        } catch (PDOException $e) {
                            $this->logger->logToFile("Error updating device record: " . $e->getMessage());
                            error_log("TR-069 ERROR: Failed to update device record: " . $e->getMessage());
                            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " ERROR updating device record: " . $e->getMessage() . "\n", FILE_APPEND);
                        }
                    } else {
                        error_log("TR-069 MessageHandler WARNING: No device values to update from parameters");
                        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " WARNING: No device values to update from parameters\n", FILE_APPEND);
                    }
                } else {
                    $this->logger->logToFile("Error: Could not find device ID for serial: $serialNumber");
                    error_log("TR-069 ERROR: Could not find device ID for serial: $serialNumber");
                    file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " ERROR: Could not find device ID for serial: $serialNumber\n", FILE_APPEND);
                }
            } else {
                $this->logger->logToFile("Error: No device serial in current session");
                error_log("TR-069 ERROR: No device serial in current session");
                file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " ERROR: No device serial in current session\n", FILE_APPEND);
            }
        } else {
            $this->logger->logToFile("Warning: No parameters found in GetParameterValuesResponse");
            error_log("TR-069 WARNING: No parameters found in GetParameterValuesResponse");
            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " WARNING: No parameters found in GetParameterValuesResponse\n", FILE_APPEND);
        }
        
        $this->logger->logToFile("======= END HANDLING GetParameterValuesResponse =======");
        error_log("TR-069 MessageHandler: END HANDLING GetParameterValuesResponse");
        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " END HANDLING GetParameterValuesResponse\n", FILE_APPEND);
        
        // Return an empty response to complete this transaction
        $response = XMLGenerator::generateEmptyResponse($soapId);
        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Returning empty response with ID: $soapId\n", FILE_APPEND);
        return $response;
    }

    public function handleEmptyPost() {
        error_log("TR-069 MessageHandler: handleEmptyPost called");
        $currentTask = $this->sessionManager->getCurrentTask();
        $this->logger->logToFile("Handling empty POST, current task: " . ($currentTask ? $currentTask['task_type'] : 'none'));
        
        // Append to retrieve.log
        $retrieveLog = __DIR__ . '/../../../retrieve.log';
        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " HANDLING EMPTY POST - current task: " . ($currentTask ? $currentTask['task_type'] : 'none') . "\n", FILE_APPEND);
        
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
        
        // If no current task, let's create a get_parameters task to fetch the default parameters
        // This ensures we always get the critical parameters when a device connects
        error_log("TR-069 MessageHandler: Creating default get_parameters task for empty POST");
        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Creating default get_parameters task\n", FILE_APPEND);
        
        $serialNumber = $this->sessionManager->getCurrentSessionDeviceSerial();
        if ($serialNumber) {
            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Found device serial: $serialNumber\n", FILE_APPEND);
            
            // Default parameters that we always want to fetch
            $defaultParams = [
                'InternetGatewayDevice.DeviceInfo.UpTime',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
                'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
                'InternetGatewayDevice.DeviceInfo.HardwareVersion'
            ];
            
            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Default parameters: " . implode(', ', $defaultParams) . "\n", FILE_APPEND);
            
            // Generate a GetParameterValues request using the default parameters
            $soapId = uniqid();
            error_log("TR-069 MessageHandler: Generating GetParameterValues with ID: $soapId");
            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Generating GetParameterValues with ID: $soapId\n", FILE_APPEND);
            
            $getParamRequest = XMLGenerator::generateFullGetParameterValuesRequestXML($soapId, $defaultParams);
            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Generated GetParameterValues request of length: " . strlen($getParamRequest) . "\n", FILE_APPEND);
            
            // Save the request for debugging
            $logDir = __DIR__ . '/../../logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0777, true);
            }
            $requestLogFile = $logDir . '/tr069_gpv_request_' . date('Ymd_His') . '.xml';
            file_put_contents($requestLogFile, $getParamRequest);
            error_log("TR-069 MessageHandler: Saved GPV request to file: $requestLogFile");
            file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Saved GPV request to: $requestLogFile\n", FILE_APPEND);
            
            return $getParamRequest;
        }
        
        // If all else fails, return empty response
        error_log("TR-069 MessageHandler: No tasks to process, returning empty response");
        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " No tasks to process, returning empty response\n", FILE_APPEND);
        
        // Return empty response if no tasks to process
        return XMLGenerator::generateEmptyResponse(uniqid());
    }

    private function extractDeviceParams($raw_post) {
        error_log("TR-069 MessageHandler: extractDeviceParams called");
        $params = [];
        
        // Extract manufacturer
        preg_match('/<Manufacturer>(.*?)<\/Manufacturer>/s', $raw_post, $matches);
        if (isset($matches[1])) {
            $params['manufacturer'] = trim($matches[1]);
            error_log("TR-069 MessageHandler: Extracted manufacturer: " . $params['manufacturer']);
        }
        
        // Extract model name
        preg_match('/<ProductClass>(.*?)<\/ProductClass>/s', $raw_post, $matches);
        if (isset($matches[1])) {
            $params['modelName'] = trim($matches[1]);
            error_log("TR-069 MessageHandler: Extracted model name: " . $params['modelName']);
        }
        
        // Extract IP address
        if (preg_match('/<Name>InternetGatewayDevice\.WANDevice\.1\.WANConnectionDevice\.1\.WANIPConnection\.1\.ExternalIPAddress<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/s', $raw_post, $matches)) {
            $params['ipAddress'] = trim($matches[1]);
            $this->logger->logToFile("Found IP Address in Inform: " . $params['ipAddress']);
            error_log("TR-069 MessageHandler: Extracted IP address: " . $params['ipAddress']);
        }
        
        // Extract SSID
        if (preg_match('/<Name>InternetGatewayDevice\.LANDevice\.1\.WLANConfiguration\.1\.SSID<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/s', $raw_post, $matches)) {
            $params['ssid'] = trim($matches[1]);
            $this->logger->logToFile("Found SSID in Inform: " . $params['ssid']);
            error_log("TR-069 MessageHandler: Extracted SSID: " . $params['ssid']);
        }
        
        // Extract uptime
        if (preg_match('/<Name>InternetGatewayDevice\.DeviceInfo\.UpTime<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/s', $raw_post, $matches)) {
            $params['uptime'] = (int)trim($matches[1]);
            $this->logger->logToFile("Found Uptime in Inform: " . $params['uptime']);
            error_log("TR-069 MessageHandler: Extracted uptime: " . $params['uptime']);
        }
        
        // Extract software version
        if (preg_match('/<Name>InternetGatewayDevice\.DeviceInfo\.SoftwareVersion<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/s', $raw_post, $matches)) {
            $params['softwareVersion'] = trim($matches[1]);
            $this->logger->logToFile("Found Software Version in Inform: " . $params['softwareVersion']);
            error_log("TR-069 MessageHandler: Extracted software version: " . $params['softwareVersion']);
        }
        
        // Extract hardware version
        if (preg_match('/<Name>InternetGatewayDevice\.DeviceInfo\.HardwareVersion<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/s', $raw_post, $matches)) {
            $params['hardwareVersion'] = trim($matches[1]);
            $this->logger->logToFile("Found Hardware Version in Inform: " . $params['hardwareVersion']);
            error_log("TR-069 MessageHandler: Extracted hardware version: " . $params['hardwareVersion']);
        }
        
        // Additional logging for debugging all parameters
        preg_match_all('/<ParameterValueStruct>\s*<Name>(.*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>\s*<\/ParameterValueStruct>/s', $raw_post, $allParams, PREG_SET_ORDER);
        
        if (!empty($allParams)) {
            $this->logger->logToFile("All parameters in Inform message:");
            foreach ($allParams as $param) {
                $paramName = trim($param[1]);
                $paramValue = trim($param[2]);
                $this->logger->logToFile("  Parameter: $paramName = $paramValue");
                error_log("TR-069 MessageHandler: Found parameter in Inform: $paramName = $paramValue");
            }
        } else {
            $this->logger->logToFile("No parameters found in ParameterValueStruct blocks");
            error_log("TR-069 MessageHandler: No parameters found in ParameterValueStruct blocks");
        }
        
        return $params;
    }

    private function updateDeviceWithParams($serialNumber, $params) {
        error_log("TR-069 MessageHandler: updateDeviceWithParams called for serial: $serialNumber");
        try {
            // First, check if the device exists
            $stmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
            $stmt->execute([':serial' => $serialNumber]);
            $deviceId = $stmt->fetchColumn();
            
            if (!$deviceId) {
                // Create a new device record if it doesn't exist
                $this->logger->logToFile("Device not found, creating new entry for serial: $serialNumber");
                error_log("TR-069 MessageHandler: Device not found, creating new entry for serial: $serialNumber");
                
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
                error_log("TR-069 MessageHandler: Creating device with SQL: $sql");
                $stmt = $this->db->prepare($sql);
                $stmt->execute($insertValues);
            } else {
                // Update existing device
                $this->logger->logToFile("Updating device with ID: $deviceId and Serial: $serialNumber");
                error_log("TR-069 MessageHandler: Updating device with ID: $deviceId and Serial: $serialNumber");
                
                $updateFields = ['status = :status', 'last_contact = :lastContact'];
                $updateValues = [':status' => 'online', ':lastContact' => date('Y-m-d H:i:s'), ':serial' => $serialNumber];
                
                foreach ($params as $key => $value) {
                    $dbField = $this->mapParamToDbField($key);
                    if ($dbField) {
                        $updateFields[] = "$dbField = :$key";
                        $updateValues[':' . $key] = $value;
                        $this->logger->logToFile("Will update field: $dbField with value: $value");
                        error_log("TR-069 MessageHandler: Will update field: $dbField with value: $value");
                    }
                }
                
                $sql = "UPDATE devices SET " . implode(', ', $updateFields) . " WHERE serial_number = :serial";
                
                $this->logger->logToFile("Updating device with SQL: $sql");
                $this->logger->logToFile("Parameter values: " . json_encode($updateValues));
                error_log("TR-069 MessageHandler: Updating device with SQL: $sql");
                
                $stmt = $this->db->prepare($sql);
                $result = $stmt->execute($updateValues);
                
                if ($result) {
                    $this->logger->logToFile("Database update successful");
                    error_log("TR-069 MessageHandler: Database update successful");
                } else {
                    $errorInfo = $stmt->errorInfo();
                    $this->logger->logToFile("Database update failed: " . $errorInfo[2]);
                    error_log("TR-069 MessageHandler: Database update failed: " . $errorInfo[2]);
                }
            }
            
            $this->logger->logToFile("Device $serialNumber updated successfully with parameters: " . json_encode($params));
            error_log("TR-069 MessageHandler: Device $serialNumber updated successfully with parameters");
        } catch (PDOException $e) {
            $this->logger->logToFile("Database error updating device: " . $e->getMessage());
            error_log("TR-069 MessageHandler: Database error updating device: " . $e->getMessage());
        }
    }
    
    private function mapParamToDbField($paramName) {
        error_log("TR-069 MessageHandler: mapParamToDbField called for: $paramName");
        $mapping = [
            'manufacturer' => 'manufacturer',
            'modelName' => 'model_name',
            'ipAddress' => 'ip_address',
            'ssid' => 'ssid',
            'uptime' => 'uptime',
            'softwareVersion' => 'software_version',
            'hardwareVersion' => 'hardware_version'
        ];
        
        $result = isset($mapping[$paramName]) ? $mapping[$paramName] : null;
        error_log("TR-069 MessageHandler: mapParamToDbField result: " . ($result ?: 'null'));
        return $result;
    }
}
