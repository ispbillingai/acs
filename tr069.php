<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Tracking variables
$GLOBALS['session_id'] = 'session-' . substr(md5(time()), 0, 8);
$GLOBALS['current_task'] = null;

// Define device.log file path
$GLOBALS['device_log'] = __DIR__ . '/device.log';
$GLOBALS['retrieve_log'] = __DIR__ . '/retrieve.log';

// Ensure the log files exist
if (!file_exists($GLOBALS['device_log'])) {
    touch($GLOBALS['device_log']);
    chmod($GLOBALS['device_log'], 0666); // Make writable
}

if (!file_exists($GLOBALS['retrieve_log'])) {
    touch($GLOBALS['retrieve_log']);
    chmod($GLOBALS['retrieve_log'], 0666); // Make writable
}

// Log detailed information to both Apache error log and device.log
function tr069_log($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[TR-069][$level][{$GLOBALS['session_id']}] $message";
    
    // Log to Apache error log
    error_log($logMessage, 0);
    
    // Log to device.log file
    if (isset($GLOBALS['device_log']) && is_writable($GLOBALS['device_log'])) {
        file_put_contents($GLOBALS['device_log'], "[$timestamp] $logMessage\n", FILE_APPEND);
    }
    
    // Also append to retrieve log for debugging
    if (isset($GLOBALS['retrieve_log']) && is_writable($GLOBALS['retrieve_log'])) {
        file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] $logMessage\n", FILE_APPEND);
    }
}

// Create a pending info task for a device
function createPendingInfoTask($deviceId, $db) {
    try {
        tr069_log("TASK CREATION: Starting task creation for device ID: " . $deviceId, "INFO");
        
        // Default task data for info task
        $taskData = json_encode(['names' => []]);
        
        // Insert the task
        $insertStmt = $db->prepare("
            INSERT INTO device_tasks 
                (device_id, task_type, task_data, status, message, created_at, updated_at) 
            VALUES 
                (:device_id, 'info', :task_data, 'pending', 'Auto-created on device connection', NOW(), NOW())
        ");
        
        $insertResult = $insertStmt->execute([
            ':device_id' => $deviceId,
            ':task_data' => $taskData
        ]);
        
        if ($insertResult) {
            $taskId = $db->lastInsertId();
            tr069_log("TASK CREATION: Successfully created pending info task with ID: {$taskId}", "INFO");
            
            // Debug - Double check if task was actually created
            $verifyStmt = $db->prepare("SELECT * FROM device_tasks WHERE id = :id");
            $verifyStmt->execute([':id' => $taskId]);
            $taskExists = $verifyStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($taskExists) {
                tr069_log("TASK CREATION: Verified task exists in database with ID: {$taskId}", "INFO");
            } else {
                tr069_log("TASK CREATION ERROR: Task not found in database after creation!", "ERROR");
            }
            
            return true;
        } else {
            tr069_log("TASK CREATION ERROR: Failed to create pending info task. Database error.", "ERROR");
            
            // More detailed error logging
            $errorInfo = $insertStmt->errorInfo();
            tr069_log("TASK CREATION ERROR: " . print_r($errorInfo, true), "ERROR");
            
            return false;
        }
    } catch (PDOException $e) {
        tr069_log("TASK CREATION ERROR: Exception while creating pending task: " . $e->getMessage(), "ERROR");
        tr069_log("TASK CREATION ERROR: Stack trace: " . $e->getTraceAsString(), "ERROR");
        
        return false;
    }
}

// Helper function to save parameter values to the database
function saveParameterValues($raw, $serialNumber, $db) {
    // Map TR-069 parameter names to database columns
    $map = [
        'ExternalIPAddress' => 'ip_address',
        'SoftwareVersion' => 'software_version',
        'HardwareVersion' => 'hardware_version',
        'UpTime' => 'uptime',
        'SSID' => 'ssid',
        'HostNumberOfEntries' => 'connected_clients'
    ];
    
    // Extract parameters from the response
    $pairs = [];
    preg_match_all('/<ParameterValueStruct>.*?<Name>(.*?)<\/Name>.*?<Value[^>]*>(.*?)<\/Value>/s', $raw, $matches, PREG_SET_ORDER);
    
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Extracted parameters: " . print_r($matches, true) . "\n", FILE_APPEND);
    
    foreach ($matches as $param) {
        $name = $param[1];
        $value = $param[2];
        
        // Ignore empty values
        if (empty($value)) continue;
        
        // Try to match parameter name to database column
        foreach ($map as $needle => $column) {
            if (strpos($name, $needle) !== false) {
                $pairs[$column] = $value;
                file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Mapped $name to $column = $value\n", FILE_APPEND);
            }
        }
    }
    
    // Update database if we have values
    if (!empty($pairs)) {
        try {
            $setStatements = [];
            $params = [':serial' => $serialNumber];
            
            foreach ($pairs as $column => $value) {
                $setStatements[] = "$column = :$column";
                $params[":$column"] = $value;
            }
            
            $sql = "UPDATE devices SET " . implode(', ', $setStatements) . " WHERE serial_number = :serial";
            file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] SQL: $sql\n", FILE_APPEND);
            file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Params: " . print_r($params, true) . "\n", FILE_APPEND);
            
            $stmt = $db->prepare($sql);
            $result = $stmt->execute($params);
            
            file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Update result: " . ($result ? "success" : "failed") . "\n", FILE_APPEND);
            tr069_log("Device $serialNumber updated with " . implode(', ', array_keys($pairs)), "INFO");
        } catch (Exception $e) {
            file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Database error: " . $e->getMessage() . "\n", FILE_APPEND);
            tr069_log("Error updating device data: " . $e->getMessage(), "ERROR");
        }
    }
}

// Include required files
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/tr069/auth/AuthenticationHandler.php';
require_once __DIR__ . '/backend/tr069/responses/InformResponseGenerator.php';
require_once __DIR__ . '/backend/tr069/tasks/TaskHandler.php';

// Process the TR-069 request
try {
    // Create database connection
    $database = new Database();
    $db = $database->getConnection();
    
    // Authenticate the device
    $auth = new AuthenticationHandler();
    if (!$auth->authenticate()) {
        tr069_log("Authentication failed", "ERROR");
        header('HTTP/1.1 401 Unauthorized');
        header('WWW-Authenticate: Basic realm="TR-069 ACS"');
        exit;
    }
    
    // Get the raw POST data
    $raw_post = file_get_contents('php://input');
    tr069_log("Received request: " . substr($raw_post, 0, 200) . "...", "DEBUG");
    
    // Initialize response generator
    $responseGenerator = new InformResponseGenerator();
    
    // Initialize task handler
    $taskHandler = new TaskHandler();
    
    // Check if this is an Inform message
    if (stripos($raw_post, '<cwmp:Inform>') !== false) {
        // Extract the SOAP ID
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        // Extract device serial number
        preg_match('/<SerialNumber>(.*?)<\/SerialNumber>/s', $raw_post, $serialMatches);
        $serialNumber = isset($serialMatches[1]) ? trim($serialMatches[1]) : null;
        
        if ($serialNumber) {
            tr069_log("Device inform received - Serial: $serialNumber", "INFO");
            
            // Update device status in database
            try {
                $stmt = $db->prepare("
                    INSERT INTO devices 
                        (serial_number, status, last_contact) 
                    VALUES 
                        (:serial, 'online', NOW()) 
                    ON DUPLICATE KEY UPDATE 
                        status = 'online', 
                        last_contact = NOW()
                ");
                $stmt->execute([':serial' => $serialNumber]);
                tr069_log("Updated device status to online - Serial: $serialNumber", "INFO");
            } catch (PDOException $e) {
                tr069_log("Database error updating device status: " . $e->getMessage(), "ERROR");
            }
            
            // Extract more device information if available
            preg_match('/<ProductClass>(.*?)<\/ProductClass>/s', $raw_post, $modelMatches);
            $model = isset($modelMatches[1]) ? trim($modelMatches[1]) : null;
            
            preg_match('/<Manufacturer>(.*?)<\/Manufacturer>/s', $raw_post, $mfrMatches);
            $manufacturer = isset($mfrMatches[1]) ? trim($mfrMatches[1]) : null;
            
            if ($model || $manufacturer) {
                try {
                    $updateFields = [];
                    $params = [':serial' => $serialNumber];
                    
                    if ($model) {
                        $updateFields[] = "model_name = :model";
                        $params[':model'] = $model;
                    }
                    
                    if ($manufacturer) {
                        $updateFields[] = "manufacturer = :manufacturer";
                        $params[':manufacturer'] = $manufacturer;
                    }
                    
                    if (!empty($updateFields)) {
                        $sql = "UPDATE devices SET " . implode(", ", $updateFields) . " WHERE serial_number = :serial";
                        $stmt = $db->prepare($sql);
                        $stmt->execute($params);
                        tr069_log("Updated device details - Model: $model, Manufacturer: $manufacturer", "INFO");
                    }
                } catch (PDOException $e) {
                    tr069_log("Database error updating device details: " . $e->getMessage(), "ERROR");
                }
            }
            
            // Get device ID
            try {
                $idStmt = $db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
                $idStmt->execute([':serial' => $serialNumber]);
                $deviceId = $idStmt->fetchColumn();
                
                if ($deviceId) {
                    tr069_log("Found device ID: $deviceId for serial: $serialNumber", "INFO");
                    
                    // Always create a pending info task for the device
                    $taskCreated = createPendingInfoTask($deviceId, $db);
                    
                    if ($taskCreated) {
                        tr069_log("Successfully created pending info task for device ID: $deviceId", "INFO");
                    } else {
                        tr069_log("Failed to create pending info task for device ID: $deviceId", "ERROR");
                    }
                } else {
                    tr069_log("Device ID not found for serial: $serialNumber", "ERROR");
                }
            } catch (PDOException $e) {
                tr069_log("Database error finding device ID: " . $e->getMessage(), "ERROR");
            }
            
            // Look for pending tasks for this device
            $pendingTasks = $taskHandler->getPendingTasks($serialNumber);
            
            if (!empty($pendingTasks)) {
                // Store the first task to process after Inform
                $GLOBALS['current_task'] = $pendingTasks[0];
                tr069_log("Found pending task: " . $pendingTasks[0]['task_type'] . " - ID: " . $pendingTasks[0]['id'], "INFO");
                
                // Store the task in the session for later use
                session_start();
                $_SESSION['current_task'] = $pendingTasks[0];
                $_SESSION['device_serial'] = $serialNumber;
                session_write_close();
            } else {
                tr069_log("No pending tasks for device ID: " . ($deviceId ?: 'unknown'), "INFO");
                
                // Check for in-progress tasks that might need to be completed
                try {
                    $deviceStmt = $db->prepare("SELECT id FROM devices WHERE serial_number = :serial_number");
                    $deviceStmt->execute([':serial_number' => $serialNumber]);
                    $deviceId = $deviceStmt->fetchColumn();
                    
                    if ($deviceId) {
                        $inProgressStmt = $db->prepare("
                            SELECT * FROM device_tasks 
                            WHERE device_id = :device_id 
                            AND status = 'in_progress' 
                            ORDER BY updated_at DESC LIMIT 1"
                        );
                        $inProgressStmt->execute([':device_id' => $deviceId]);
                        $inProgressTask = $inProgressStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($inProgressTask) {
                            tr069_log("Found in-progress task that might need completion: " . $inProgressTask['task_type'] . " - ID: " . $inProgressTask['id'], "INFO");
                            
                            // Auto-complete tasks that have been in progress for more than 5 minutes
                            $taskTime = strtotime($inProgressTask['updated_at']);
                            $currentTime = time();
                            
                            if (($currentTime - $taskTime) > 300) { // 5 minutes
                                $taskHandler->updateTaskStatus($inProgressTask['id'], 'completed', 'Auto-completed after device reconnection');
                                tr069_log("Auto-completed task ID: " . $inProgressTask['id'] . " after timeout", "INFO");
                            } else {
                                // Add this to session to complete it in this session
                                session_start();
                                $_SESSION['in_progress_task'] = $inProgressTask;
                                $_SESSION['device_serial'] = $serialNumber;
                                session_write_close();
                            }
                        }
                    }
                } catch (PDOException $e) {
                    tr069_log("Database error checking in-progress tasks: " . $e->getMessage(), "ERROR");
                }
            }
        }
        
        // Respond with InformResponse
        $response = $responseGenerator->createResponse($soapId);
        tr069_log("Sending InformResponse", "DEBUG");
        
        header('Content-Type: text/xml');
        echo $response;
        exit;
    }
    
    // Check if this is a GetParameterValuesResponse
    if (stripos($raw_post, '<cwmp:GetParameterValuesResponse>') !== false || 
        (stripos($raw_post, 'ParameterList') !== false && 
         stripos($raw_post, 'ParameterValueStruct') !== false)) {
        
        tr069_log("Received GetParameterValuesResponse", "INFO");
        
        // Extract the SOAP ID
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        // Get current task and device serial from session
        session_start();
        $serialNumber = isset($_SESSION['device_serial']) ? $_SESSION['device_serial'] : null;
        $current_task = isset($_SESSION['current_task']) ? $_SESSION['current_task'] : null;
        session_write_close();
        
        if ($serialNumber) {
            // Process the parameter values
            saveParameterValues($raw_post, $serialNumber, $db);
            
            // Check if HostNumberOfEntries is in the response
            $hostCount = 0;
            preg_match('/<Name>InternetGatewayDevice\.LANDevice\.1\.Hosts\.HostNumberOfEntries<\/Name>.*?<Value[^>]*>(.*?)<\/Value>/s', $raw_post, $hostMatches);
            if (isset($hostMatches[1]) && is_numeric($hostMatches[1])) {
                $hostCount = (int)$hostMatches[1];
                tr069_log("Found HostNumberOfEntries: $hostCount", "INFO");
                
                // If we have hosts and this was an initial info task, create a follow-up task
                if ($hostCount > 0 && $current_task && $current_task['task_type'] === 'info') {
                    $taskData = json_decode($current_task['task_data'], true) ?: [];
                    
                    // Only create follow-up if this wasn't already a follow-up task
                    if (!isset($taskData['host_count'])) {
                        $deviceId = $taskHandler->getDeviceIdFromSerialNumber($serialNumber);
                        if ($deviceId) {
                            $followUpTaskId = $taskHandler->createFollowUpInfoTask($deviceId, $hostCount);
                            tr069_log("Created follow-up info task: $followUpTaskId for hosts details", "INFO");
                        }
                    }
                }
            }
            
            // Update task status if available
            if ($current_task) {
                $taskHandler->updateTaskStatus($current_task['id'], 'completed', 'Successfully retrieved device information');
                tr069_log("Info task completed: " . $current_task['id'], "INFO");
                
                // Clear the session task
                session_start();
                unset($_SESSION['current_task']);
                session_write_close();
            }
        } else {
            tr069_log("No device serial number found for GetParameterValuesResponse", "WARNING");
        }
        
        // Send empty response to complete the session
        header('Content-Type: text/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
  </soapenv:Body>
</soapenv:Envelope>';
        exit;
    }
    
    // Check if this is a SetParameterValuesResponse
    if (stripos($raw_post, 'SetParameterValuesResponse') !== false || 
        stripos($raw_post, '<Status>') !== false || 
        (stripos($raw_post, '</cwmp:Body>') !== false && 
         (stripos($raw_post, 'ParameterValue') !== false || stripos($raw_post, 'Parameter') !== false))) {
        
        tr069_log("Detected potential SetParameterValuesResponse: " . substr($raw_post, 0, 100), "INFO");
        
        // Extract the SOAP ID
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        // Try to extract status if available
        preg_match('/<Status>(.*?)<\/Status>/s', $raw_post, $statusMatches);
        $status = isset($statusMatches[1]) ? trim($statusMatches[1]) : '0'; // Default to success if not found
        
        tr069_log("Extracted status from response: $status", "INFO");
        
        // Get current task from session or global variable
        $current_task = null;
        if ($GLOBALS['current_task']) {
            $current_task = $GLOBALS['current_task'];
            tr069_log("Using task from global variable: " . $current_task['id'], "DEBUG");
        } else {
            // Try to get it from the session
            session_start();
            if (isset($_SESSION['current_task'])) {
                $current_task = $_SESSION['current_task'];
                tr069_log("Using task from session: " . $current_task['id'], "DEBUG");
                
                // Clear the session task as it's now processed
                unset($_SESSION['current_task']);
            } elseif (isset($_SESSION['in_progress_task'])) {
                $current_task = $_SESSION['in_progress_task'];
                tr069_log("Using in-progress task from session: " . $current_task['id'], "DEBUG");

                
                // Clear the session task as it's now processed
                unset($_SESSION['in_progress_task']);
            }
            session_write_close();
        }
        
        // Update task status
        if ($current_task) {
            if ($status === '0') {
                $taskHandler->updateTaskStatus($current_task['id'], 'completed', 'Successfully applied ' . $current_task['task_type'] . ' configuration');
                tr069_log("Task completed successfully: " . $current_task['id'], "INFO");
            } else {
                $taskHandler->updateTaskStatus($current_task['id'], 'failed', 'Device returned error status: ' . $status);
                tr069_log("Task failed: " . $current_task['id'] . " - Status: " . $status, "ERROR");
            }
            
            // Clear the global task as well
            $GLOBALS['current_task'] = null;
        } else {
            tr069_log("No current task found to update status", "WARNING");

            
            // Try to find the most recent in-progress task for this device
            try {
                session_start();
                $serialNumber = isset($_SESSION['device_serial']) ? $_SESSION['device_serial'] : null;
                session_write_close();
                
                if ($serialNumber) {
                    $deviceStmt = $db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
                    $deviceStmt->execute([':serial' => $serialNumber]);
                    $deviceId = $deviceStmt->fetchColumn();
                    
                    if ($deviceId) {
                        $taskStmt = $db->prepare("
                            SELECT * FROM device_tasks 
                            WHERE device_id = :device_id 
                            AND status = 'in_progress' 
                            ORDER BY updated_at DESC LIMIT 1"
                        );
                        $taskStmt->execute([':device_id' => $deviceId]);
                        $inProgressTask = $taskStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($inProgressTask) {
                            if ($status === '0') {
                                $taskHandler->updateTaskStatus($inProgressTask['id'], 'completed', 'Successfully applied ' . $inProgressTask['task_type'] . ' configuration');
                                tr069_log("Found and completed in-progress task: " . $inProgressTask['id'], "INFO");
                            } else {
                                $taskHandler->updateTaskStatus($inProgressTask['id'], 'failed', 'Device returned error status: ' . $status);
                                tr069_log("Found and marked as failed in-progress task: " . $inProgressTask['id'] . " - Status: " . $status, "ERROR");
                            }
                        } else {
                            tr069_log("No in-progress tasks found for device ID: $deviceId", "WARNING");
                        }
                    }
                }
            } catch (PDOException $e) {
                tr069_log("Database error finding in-progress tasks: " . $e->getMessage(), "ERROR");
            }
        }
        
        // Send empty response to complete the session
        header('Content-Type: text/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
  </soapenv:Body>
</soapenv:Envelope>';
        exit;
    }
    
    // Check if this is an empty post (device asking for more commands)
    if (empty(trim($raw_post)) || $raw_post === "\r\n") {
        // Extract the SOAP ID if available
        $soapId = '1';
        if (!empty($raw_post)) {
            preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        }
        
        // Process pending task if we have one
        $current_task = null;
        
        // First check if we have a task in the global variable
        if ($GLOBALS['current_task']) {
            $current_task = $GLOBALS['current_task'];
        } else {
            // If not, try to get it from the session
            session_start();
            if (isset($_SESSION['current_task'])) {
                $current_task = $_SESSION['current_task'];
                // Also restore the device serial if available
                if (isset($_SESSION['device_serial'])) {
                    $serialNumber = $_SESSION['device_serial'];
                }
            } elseif (isset($_SESSION['in_progress_task'])) {
                // If we have an in-progress task, let's complete it
                $inProgressTask = $_SESSION['in_progress_task'];
                $taskHandler->updateTaskStatus($inProgressTask['id'], 'completed', 'Auto-completed during session');
                tr069_log("Auto-completed in-progress task: " . $inProgressTask['id'], "INFO");
                unset($_SESSION['in_progress_task']);
            }
            session_write_close();
        }
        
        if ($current_task) {
            tr069_log("Processing task from session: {$current_task['task_type']} - ID: {$current_task['id']}", "INFO");
            
            // Generate parameters for this task
            $parameterRequest = $taskHandler->generateParameterValues($current_task['task_type'], $current_task['task_data']);
            
            if ($parameterRequest) {
                // Log what we're about to do
                tr069_log("Sending {$parameterRequest['method']} request for task {$current_task['id']}", "INFO");
                
                // Build the appropriate request
                if ($parameterRequest['method'] === 'SetParameterValues') {
                    $paramXml = '';
                    $paramCount = count($parameterRequest['parameters']);
                    
                    foreach ($parameterRequest['parameters'] as $param) {
                        $paramXml .= "        <ParameterValueStruct>\n";
                        $paramXml .= "          <Name>" . htmlspecialchars($param['name']) . "</Name>\n";
                        $paramXml .= "          <Value xsi:type=\"" . $param['type'] . "\">" . htmlspecialchars($param['value']) . "</Value>\n";
                        $paramXml .= "        </ParameterValueStruct>\n";
                        
                        tr069_log("Setting parameter: {$param['name']} = {$param['value']}", "DEBUG");
                    }
                    
                    $setParamRequest = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
    xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:SetParameterValues>
      <ParameterList soap-enc:arrayType="cwmp:ParameterValueStruct[' . $paramCount . ']">
' . $paramXml . '      </ParameterList>
      <ParameterKey>Task-' . $current_task['id'] . '-' . substr(md5(time()), 0, 8) . '</ParameterKey>
    </cwmp:SetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';

                    header('Content-Type: text/xml');
                    echo $setParamRequest;
                    
                    // Mark task as in progress
                    $taskHandler->updateTaskStatus($current_task['id'], 'in_progress', 'Sent parameters to device');
                    tr069_log("Task marked as in_progress: {$current_task['id']}", "INFO");
                    
                    exit;
                } 
                elseif ($parameterRequest['method'] === 'GetParameterValues') {
                    // Build GetParameterValues request
                    $nameXml = '';
                    $paramCount = count($parameterRequest['parameterNames']);
                    
                    foreach ($parameterRequest['parameterNames'] as $param) {
                        $nameXml .= "        <string>" . htmlspecialchars($param) . "</string>\n";
                        tr069_log("Requesting parameter: $param", "DEBUG");
                    }
                    
                    $getValuesRequest = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
    xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:GetParameterValues>
      <ParameterNames soap-enc:arrayType="xsd:string[' . $paramCount . ']">
' . $nameXml . '      </ParameterNames>
    </cwmp:GetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';

                    header('Content-Type: text/xml');
                    echo $getValuesRequest;
                    
                    // Mark task as in progress
                    $taskHandler->updateTaskStatus($current_task['id'], 'in_progress', 'Sent GetParameterValues request to device');
                    tr069_log("Task marked as in_progress: {$current_task['id']}", "INFO");
                    exit;
                }
                elseif ($parameterRequest['method'] === 'Reboot') {
                    // Create a custom reboot request using the SOAP format
                    $rebootRequest = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:Reboot>
      <CommandKey>' . $parameterRequest['commandKey'] . '</CommandKey>
    </cwmp:Reboot>
  </soapenv:Body>
</soapenv:Envelope>';

                    tr069_log("Sending reboot command with key: " . $parameterRequest['commandKey'], "INFO");
                    
                    // Close the connection properly to allow the device to reboot
                    header('Content-Type: text/xml');
                    header('Connection: close');
                    header('Content-Length: ' . strlen($rebootRequest));
                    echo $rebootRequest;
                    flush();
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    
                    // Mark task as in progress
                    $taskHandler->updateTaskStatus($current_task['id'], 'in_progress', 'Sent reboot command to device');
                    tr069_log("Task marked as in_progress: {$current_task['id']}", "INFO");

                    exit;
                } 
                elseif ($parameterRequest['method'] === 'X_HW_DelayReboot') {
                    // Handle vendor-specific reboot command for Huawei devices
                    $rebootRequest = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:X_HW_DelayReboot>
      <CommandKey>' . $parameterRequest['commandKey'] . '</CommandKey>
      <DelaySeconds>' . $parameterRequest['delay'] . '</DelaySeconds>
    </cwmp:X_HW_DelayReboot>
  </soapenv:Body>
</soapenv:Envelope>';

                    tr069_log("Sending Huawei vendor reboot command with key: " . $parameterRequest['commandKey'], "INFO");
                    
                    // Close the connection properly to allow the device to reboot
                    header('Content-Type: text/xml');
                    header('Connection: close');
                    header('Content-Length: ' . strlen($rebootRequest));
                    echo $rebootRequest;
                    flush();
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    
                    // Mark task as in progress
                    $taskHandler->updateTaskStatus($current_task['id'], 'in_progress', 'Sent vendor reboot command to device');
                    tr069_log("Task marked as in_progress: {$current_task['id']}", "INFO");

                    exit;
                }
            } else {
                tr069_log("Failed to generate parameters for task {$current_task['id']}", "ERROR");
                $taskHandler->updateTaskStatus($current_task['id'], 'failed', 'Failed to generate parameters');
            }
        } else {
            tr069_log("No pending tasks to process", "DEBUG");
        }
        
        // If we get here, we're done with this session
        header('Content-Type: text/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
  </soapenv:Body>
</soapenv:Envelope>';
        exit;
    }
    
    // Fall-through - no match for request type, echo a generic empty response
    header('Content-Type: text/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">1</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
  </soapenv:Body>
</soapenv:Envelope>';
    
} catch (Exception $e) {
    // Log any uncaught exceptions
    tr069_log("Unhandled exception: " . $e->getMessage(), "ERROR");
    tr069_log("Stack trace: " . $e->getTraceAsString(), "ERROR");
    
    header('HTTP/1.1 500 Internal Server Error');
    echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">1</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <soapenv:Fault>
      <faultcode>Server</faultcode>
      <faultstring>Internal Server Error</faultstring>
    </soapenv:Fault>
  </soapenv:Body>
</soapenv:Envelope>';
}
