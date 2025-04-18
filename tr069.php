<?php
// Enable error logging to Apache error log
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Tracking variables
$GLOBALS['session_id'] = 'session-' . substr(md5(time()), 0, 8);
$GLOBALS['current_task'] = null;

// Define device.log file path
$GLOBALS['device_log'] = __DIR__ . '/device.log';

// Ensure the log file exists
if (!file_exists($GLOBALS['device_log'])) {
    touch($GLOBALS['device_log']);
    chmod($GLOBALS['device_log'], 0666); // Make writable
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
    
    // Also append to our custom log file if directory exists
    $logDir = __DIR__ . '/logs';
    if (is_dir($logDir)) {
        $logFile = $logDir . '/tr069_' . date('Y-m-d') . '.log';
        file_put_contents($logFile, date('Y-m-d H:i:s') . " $logMessage\n", FILE_APPEND);
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
    
    // Check if this is a SetParameterValuesResponse (more thorough detection)
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
    if (empty(trim($raw_post)) || $raw_post === "\r\n" || stripos($raw_post, '<cwmp:GetParameterValuesResponse>') !== false) {
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
                } elseif ($parameterRequest['method'] === 'X_HW_DelayReboot') {
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
    
    // Check if this is a RebootResponse
    if (stripos($raw_post, 'RebootResponse') !== false || stripos($raw_post, 'X_HW_DelayRebootResponse') !== false) {
        // Extract the SOAP ID
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        tr069_log("Received RebootResponse", "INFO");
        
        // Get current task from session or global variable
        $current_task = null;
        if ($GLOBALS['current_task']) {
            $current_task = $GLOBALS['current_task'];
        } else {
            // Try to get it from the session
            session_start();
            if (isset($_SESSION['current_task'])) {
                $current_task = $_SESSION['current_task'];
                // Clear the session task as it's now processed
                unset($_SESSION['current_task']);
            }
            session_write_close();
        }
        
        // Update task status
        if ($current_task) {
            $taskHandler->updateTaskStatus($current_task['id'], 'completed', 'Device reboot initiated successfully');
            tr069_log("Reboot task completed: " . $current_task['id'], "INFO");
            
            // Clear the global task
            $GLOBALS['current_task'] = null;
        } else {
            tr069_log("No current task found to update status for reboot", "WARNING");
        }
        
        // Send empty response to complete the session and ensure connection is closed
        header('Content-Type: text/xml');
        header('Connection: close');
        
        $response = '<?xml version="1.0" encoding="UTF-8"?>
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
        
        header('Content-Length: ' . strlen($response));
        echo $response;
        flush();
        
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        
        exit;
    }
    
    // Additional message type handling for other SOAP responses
    if (stripos($raw_post, 'SOAP-ENV:Envelope') !== false || stripos($raw_post, 'soap:Envelope') !== false) {
        // Extract the SOAP ID
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        tr069_log("Received generic SOAP response: " . substr($raw_post, 0, 100) . "...", "DEBUG");
        
        // Check if this appears to be a SetParameterValuesResponse without proper markup
        if (stripos($raw_post, 'SetParameterValuesResponse') !== false || 
            stripos($raw_post, 'Status') !== false || 
            stripos($raw_post, 'Parameter') !== false) {
            
            tr069_log("Generic response appears to be a SetParameterValuesResponse", "INFO");
            
            try {
                session_start();
                $serialNumber = isset($_SESSION['device_serial']) ? $_SESSION['device_serial'] : null;
                $current_task = isset($_SESSION['current_task']) ? $_SESSION['current_task'] : null;
                $in_progress_task = isset($_SESSION['in_progress_task']) ? $_SESSION['in_progress_task'] : null;
                session_write_close();
                
                // Try to complete any task we have in the session
                if ($current_task) {
                    $taskHandler->updateTaskStatus($current_task['id'], 'completed', 'Auto-completed after receiving generic response');
                    tr069_log("Auto-completed pending task: " . $current_task['id'] . " after receiving generic response", "INFO");
                } else if ($in_progress_task) {
                    $taskHandler->updateTaskStatus($in_progress_task['id'], 'completed', 'Auto-completed after receiving generic response');
                    tr069_log("Auto-completed in-progress task: " . $in_progress_task['id'] . " after receiving generic response", "INFO");
                } else if ($serialNumber) {
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
                            $taskHandler->updateTaskStatus($inProgressTask['id'], 'completed', 'Auto-completed after receiving generic response');
                            tr069_log("Auto-completed device task: " . $inProgressTask['id'] . " after receiving generic response", "INFO");
                        }
                    }
                }
            } catch (PDOException $e) {
                tr069_log("Database error auto-completing task: " . $e->getMessage(), "ERROR");
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
    
    // Default response for unhandled message types
    tr069_log("Unhandled message type: " . substr($raw_post, 0, 100) . "...", "WARNING");
    
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
    tr069_log("Exception: " . $e->getMessage(), "ERROR");
    
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
}
