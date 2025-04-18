```php
<?php
// Enable error logging to Apache error log
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Tracking variables
$GLOBALS['session_id'] = 'session-' . substr(md5(time()), 0, 8);
$GLOBALS['current_task'] = null;
$GLOBALS['last_inform_session'] = null;

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
require_once __DIR__ . '/backend/tr069/utils/ParameterSaver.php';

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
            
            // Check for in-progress tasks and timeout
            try {
                $deviceStmt = $db->prepare("SELECT id, current_task_id FROM devices WHERE serial_number = :serial");
                $deviceStmt->execute([':serial' => $serialNumber]);
                $deviceRow = $deviceStmt->fetch(PDO::FETCH_ASSOC);
                $deviceId = $deviceRow['id'];
                
                if ($deviceRow['current_task_id']) {
                    $taskStmt = $db->prepare("
                        SELECT * FROM device_tasks 
                        WHERE id = :task_id 
                        AND status = 'in_progress'
                    ");
                    $taskStmt->execute([':task_id' => $deviceRow['current_task_id']]);
                    $inProgressTask = $taskStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($inProgressTask && strtotime($inProgressTask['updated_at']) < (time() - 60)) {
                        $taskHandler->updateTaskStatus($inProgressTask['id'], 'failed', 'Timed out waiting for response');
                        tr069_log("Task {$inProgressTask['id']} failed due to 60-second timeout", "ERROR");
                        
                        // Clear current task
                        $stmt = $db->prepare("
                            UPDATE devices 
                            SET current_task_id = NULL 
                            WHERE serial_number = :serial
                        ");
                        $stmt->execute([':serial' => $serialNumber]);
                        tr069_log("Cleared current task for device: $serialNumber", "INFO");
                        
                        // Clear session
                        session_start();
                        unset($_SESSION['current_task'], $_SESSION['task_retries'], $_SESSION['device_serial']);
                        session_write_close();
                        $GLOBALS['current_task'] = null;
                    }
                }
            } catch (PDOException $e) {
                tr069_log("Database error checking in-progress tasks: " . $e->getMessage(), "ERROR");
            }
            
            // Check if this is a new Inform in the same session
            session_start();
            $lastSessionId = $_SESSION['last_inform_session'] ?? null;
            if ($lastSessionId && $lastSessionId === $GLOBALS['session_id'] && isset($_SESSION['current_task'])) {
                tr069_log("Received new Inform in same session for device: $serialNumber", "WARNING");
                $current_task = $_SESSION['current_task'];
                $_SESSION['task_retries'] = ($_SESSION['task_retries'] ?? 0) + 1;
                
                if ($_SESSION['task_retries'] > 3) {
                    $taskHandler->updateTaskStatus($current_task['id'], 'failed', 'Failed after 3 retries due to new Inform in same session');
                    tr069_log("Task {$current_task['id']} failed after 3 retries", "ERROR");
                    
                    // Clear current task
                    $stmt = $db->prepare("
                        UPDATE devices 
                        SET current_task_id = NULL 
                        WHERE serial_number = :serial
                    ");
                    $stmt->execute([':serial' => $serialNumber]);
                    
                    unset($_SESSION['current_task'], $_SESSION['task_retries'], $_SESSION['device_serial']);
                    session_write_close();
                    $GLOBALS['current_task'] = null;
                    
                    // Send InformResponse
                    $response = $responseGenerator->createResponse($soapId);
                    header('Content-Type: text/xml');
                    echo $response;
                    exit;
                }
            }
            $_SESSION['last_inform_session'] = $GLOBALS['session_id'];
            session_write_close();
            
            // Auto-queue info task only if no pending or in-progress info tasks exist
            try {
                $deviceStmt = $db->prepare("SELECT id, ssid FROM devices WHERE serial_number = :s");
                $deviceStmt->execute([':s' => $serialNumber]);
                $deviceRow = $deviceStmt->fetch(PDO::FETCH_ASSOC);
                $deviceId = $deviceRow['id'];

                // Check for existing info tasks
                $taskStmt = $db->prepare("
                    SELECT COUNT(*) FROM device_tasks 
                    WHERE device_id = :device_id 
                    AND task_type = 'info' 
                    AND status IN ('pending', 'in_progress')
                ");
                $taskStmt->execute([':device_id' => $deviceId]);
                $infoTaskCount = $taskStmt->fetchColumn();

                $needsInfo = empty($deviceRow['ssid']);

                if ($needsInfo && $infoTaskCount == 0) {
                    $taskJson = json_encode([
                        'names' => [
                            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID'
                            // Fallback to 'Device.WiFi.SSID.1.SSID' can be added later if needed
                        ]
                    ]);
                    $ins = $db->prepare("
                        INSERT INTO device_tasks (device_id, task_type, task_data, status, created_at, updated_at)
                        VALUES (:d, 'info', :data, 'pending', NOW(), NOW())
                    ");
                    $ins->execute([
                        ':d' => $deviceRow['id'],
                        ':data' => $taskJson
                    ]);
                    tr069_log("Queued info task for WLAN SSID: $serialNumber", "INFO");
                } elseif ($infoTaskCount > 0) {
                    tr069_log("Skipped queuing info task for device: $serialNumber (existing info task pending or in_progress)", "INFO");
                }
            } catch (PDOException $e) {
                tr069_log("Error checking or queuing info task: " . $e->getMessage(), "ERROR");
            }

            // Look for pending tasks for this device
            $pendingTasks = $taskHandler->getPendingTasks($serialNumber);

            if (!empty($pendingTasks)) {
                // Store the first task to process
                $GLOBALS['current_task'] = $pendingTasks[0];
                tr069_log("Found pending task: " . $pendingTasks[0]['task_type'] . " - ID: " . $pendingTasks[0]['id'], "INFO");
                
                // Store task in database as current task
                try {
                    $stmt = $db->prepare("
                        UPDATE devices 
                        SET current_task_id = :task_id 
                        WHERE serial_number = :serial
                    ");
                    $stmt->execute([
                        ':task_id' => $pendingTasks[0]['id'],
                        ':serial' => $serialNumber
                    ]);
                    tr069_log("Stored task {$pendingTasks[0]['id']} as current task for device: $serialNumber", "INFO");
                } catch (PDOException $e) {
                    tr069_log("Database error storing current task: " . $e->getMessage(), "ERROR");
                }

                // Initialize session
                session_start();
                $_SESSION['current_task'] = $pendingTasks[0];
                $_SESSION['device_serial'] = $serialNumber;
                $_SESSION['task_retries'] = 0;
                session_write_close();

                // Send immediate request for the task
                $current_task = $GLOBALS['current_task'];
                $parameterRequest = $taskHandler->generateParameterValues($current_task['task_type'], $current_task['task_data']);
                
                if ($parameterRequest) {
                    if ($parameterRequest['method'] === 'GetParameterValues') {
                        $names = $parameterRequest['parameterNames'];
                        $n = count($names);
                        
                        $nameXml = '';
                        foreach ($names as $nm) {
                            $nameXml .= "        <string>$nm</string>\n";
                        }
                        
                        $gpv = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
                  xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/"
                  xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:GetParameterValues>
      <ParameterNames soap-enc:arrayType="xsd:string[' . $n . ']">
' . $nameXml . '      </ParameterNames>
    </cwmp:GetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';
                        
                        header('Content-Type: text/xml');
                        echo $gpv;
                        
                        $taskHandler->updateTaskStatus($current_task['id'], 'in_progress', 'Sent GetParameterValues');
                        try {
                            $stmt = $db->prepare("
                                UPDATE device_tasks 
                                SET updated_at = NOW() 
                                WHERE id = :task_id
                            ");
                            $stmt->execute([':task_id' => $current_task['id']]);
                            tr069_log("Updated timestamp for task {$current_task['id']}", "DEBUG");
                        } catch (PDOException $e) {
                            tr069_log("Database error updating task timestamp: " . $e->getMessage(), "ERROR");
                        }
                        tr069_log("Sent GetParameterValues (task {$current_task['id']}): " . implode(", ", $names), "INFO");
                        exit;
                    } elseif ($parameterRequest['method'] === 'SetParameterValues') {
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
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
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
                        
                        $taskHandler->updateTaskStatus($current_task['id'], 'in_progress', 'Sent parameters to device');
                        try {
                            $stmt = $db->prepare("
                                UPDATE device_tasks 
                                SET updated_at = NOW() 
                                WHERE id = :task_id
                            ");
                            $stmt->execute([':task_id' => $current_task['id']]);
                            tr069_log("Updated timestamp for task {$current_task['id']}", "DEBUG");
                        } catch (PDOException $e) {
                            tr069_log("Database error updating task timestamp: " . $e->getMessage(), "ERROR");
                        }
                        tr069_log("Task marked as in_progress: {$current_task['id']}", "INFO");
                        exit;
                    }
                }
            }
            
            // No tasks, send InformResponse
            $response = $responseGenerator->createResponse($soapId);
            tr069_log("Sending InformResponse", "DEBUG");
            
            header('Content-Type: text/xml');
            echo $response;
            exit;
        }
        
        // Invalid Inform
        tr069_log("Invalid Inform message: No serial number found", "ERROR");
        header('Content-Type: text/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body/>
</soapenv:Envelope>';
        exit;
    }
    
    // Check if this is a SetParameterValuesResponse
    if (stripos($raw_post, 'SetParameterValuesResponse') !== false || 
        stripos($raw_post, '<Status>') !== false) {
        
        tr069_log("Detected SetParameterValuesResponse: " . substr($raw_post, 0, 100), "INFO");
        
        // Extract the SOAP ID
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        // Extract status
        preg_match('/<Status>(.*?)<\/Status>/s', $raw_post, $statusMatches);
        $status = isset($statusMatches[1]) ? trim($statusMatches[1]) : '0';
        
        tr069_log("Extracted status from response: $status", "INFO");
        
        // Get current task
        $current_task = null;
        session_start();
        $serialNumber = $_SESSION['device_serial'] ?? null;
        if (isset($_SESSION['current_task'])) {
            $current_task = $_SESSION['current_task'];
            tr069_log("Using task from session: " . $current_task['id'], "DEBUG");
        }
        session_write_close();
        
        if (!$current_task && $serialNumber) {
            try {
                $deviceStmt = $db->prepare("
                    SELECT current_task_id FROM devices 
                    WHERE serial_number = :serial
                ");
                $deviceStmt->execute([':serial' => $serialNumber]);
                $taskId = $deviceStmt->fetchColumn();
                
                if ($taskId) {
                    $taskStmt = $db->prepare("
                        SELECT * FROM device_tasks 
                        WHERE id = :task_id 
                        AND status = 'in_progress'
                    ");
                    $taskStmt->execute([':task_id' => $taskId]);
                    $current_task = $taskStmt->fetch(PDO::FETCH_ASSOC);
                    tr069_log("Retrieved task {$taskId} from database", "DEBUG");
                }
            } catch (PDOException $e) {
                tr069_log("Database error retrieving current task: " . $e->getMessage(), "ERROR");
            }
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
            
            // Clear current task
            try {
                $stmt = $db->prepare("
                    UPDATE devices 
                    SET current_task_id = NULL 
                    WHERE serial_number = :serial
                ");
                $stmt->execute([':serial' => $serialNumber]);
                tr069_log("Cleared current task for device: $serialNumber", "INFO");
            } catch (PDOException $e) {
                tr069_log("Database error clearing current task: " . $e->getMessage(), "ERROR");
            }
            
            session_start();
            unset($_SESSION['current_task'], $_SESSION['task_retries'], $_SESSION['last_inform_session']);
            session_write_close();
            $GLOBALS['current_task'] = null;
        }
        
        // Send empty response
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
    
    // Check if this is an empty post
    if (empty(trim($raw_post)) || $raw_post === "\r\n") {
        tr069_log("Received empty POST", "DEBUG");

        // Extract SOAP ID
        $soapId = '1';
        if (!empty($raw_post)) {
            preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        }

        // Get current task
        $current_task = null;
        session_start();
        $serialNumber = $_SESSION['device_serial'] ?? null;
        if (isset($_SESSION['current_task'])) {
            $current_task = $_SESSION['current_task'];
            $_SESSION['task_retries'] = isset($_SESSION['task_retries']) ? $_SESSION['task_retries'] + 1 : 1;
            tr069_log("Task retry count for task {$current_task['id']}: {$_SESSION['task_retries']}", "DEBUG");
        }
        session_write_close();
        
        if (!$current_task && $serialNumber) {
            try {
                $deviceStmt = $db->prepare("
                    SELECT current_task_id FROM devices 
                    WHERE serial_number = :serial
                ");
                $deviceStmt->execute([':serial' => $serialNumber]);
                $taskId = $deviceStmt->fetchColumn();
                
                if ($taskId) {
                    $taskStmt = $db->prepare("
                        SELECT * FROM device_tasks 
                        WHERE id = :task_id 
                        AND status IN ('pending', 'in_progress')
                    ");
                    $taskStmt->execute([':task_id' => $taskId]);
                    $current_task = $taskStmt->fetch(PDO::FETCH_ASSOC);
                    tr069_log("Retrieved task {$taskId} from database for empty POST", "DEBUG");
                }
            } catch (PDOException $e) {
                tr069_log("Database error retrieving current task for empty POST: " . $e->getMessage(), "ERROR");
            }
        }

        if ($current_task) {
            // Check retry limit
            $maxRetries = 3;
            session_start();
            $retries = $_SESSION['task_retries'] ?? 1;
            session_write_close();

            if ($retries > $maxRetries) {
                $taskHandler->updateTaskStatus($current_task['id'], 'failed', "Task failed after $maxRetries retries");
                tr069_log("Task {$current_task['id']} failed after $maxRetries retries", "ERROR");
                
                // Clear current task
                try {
                    $stmt = $db->prepare("
                        UPDATE devices 
                        SET current_task_id = NULL 
                        WHERE serial_number = :serial
                    ");
                    $stmt->execute([':serial' => $serialNumber]);
                    tr069_log("Cleared current task for device: $serialNumber", "INFO");
                } catch (PDOException $e) {
                    tr069_log("Database error clearing current task: " . $e->getMessage(), "ERROR");
                }
                
                session_start();
                unset($_SESSION['current_task'], $_SESSION['task_retries'], $_SESSION['last_inform_session']);
                session_write_close();
                $GLOBALS['current_task'] = null;

                // Send empty response
                header('Content-Type: text/xml');
                echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body/>
</soapenv:Envelope>';
                exit;
            }

            // Check timeout
            try {
                $taskStmt = $db->prepare("SELECT updated_at FROM device_tasks WHERE id = :task_id");
                $taskStmt->execute([':task_id' => $current_task['id']]);
                $taskRow = $taskStmt->fetch(PDO::FETCH_ASSOC);
                if ($taskRow && strtotime($taskRow['updated_at']) < (time() - 60)) {
                    $taskHandler->updateTaskStatus($current_task['id'], 'failed', 'Task timed out after 60 seconds');
                    tr069_log("Task {$current_task['id']} failed due to 60-second timeout", "ERROR");
                    
                    // Clear current task
                    $stmt = $db->prepare("
                        UPDATE devices 
                        SET current_task_id = NULL 
                        WHERE serial_number = :serial
                    ");
                    $stmt->execute([':serial' => $serialNumber]);
                    
                    session_start();
                    unset($_SESSION['current_task'], $_SESSION['task_retries'], $_SESSION['last_inform_session']);
                    session_write_close();
                    $GLOBALS['current_task'] = null;

                    // Send empty response
                    header('Content-Type: text/xml');
                    echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body/>
</soapenv:Envelope>';
                    exit;
                }
            } catch (PDOException $e) {
                tr069_log("Database error checking task timeout: " . $e->getMessage(), "ERROR");
            }

            tr069_log("Processing task: {$current_task['task_type']} - ID: {$current_task['id']}", "INFO");

            $parameterRequest = $taskHandler->generateParameterValues($current_task['task_type'], $current_task['task_data']);

            if ($parameterRequest) {
                tr069_log("Sending {$parameterRequest['method']} request for task {$current_task['id']}", "INFO");

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
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
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
                    
                    $taskHandler->updateTaskStatus($current_task['id'], 'in_progress', 'Sent parameters to device');
                    try {
                        $stmt = $db->prepare("
                            UPDATE device_tasks 
                            SET updated_at = NOW() 
                            WHERE id = :task_id
                        ");
                        $stmt->execute([':task_id' => $current_task['id']]);
                        tr069_log("Updated timestamp for task {$current_task['id']}", "DEBUG");
                    } catch (PDOException $e) {
                        tr069_log("Database error updating task timestamp: " . $e->getMessage(), "ERROR");
                    }
                    tr069_log("Task marked as in_progress: {$current_task['id']}", "INFO");
                    exit;
                } elseif ($parameterRequest['method'] === 'GetParameterValues') {
                    $names = $parameterRequest['parameterNames'];
                    $n = count($names);
                    
                    $nameXml = '';
                    foreach ($names as $nm) {
                        $nameXml .= "        <string>$nm</string>\n";
                    }
                    
                    $gpv = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
                  xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/"
                  xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:GetParameterValues>
      <ParameterNames soap-enc:arrayType="xsd:string[' . $n . ']">
' . $nameXml . '      </ParameterNames>
    </cwmp:GetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';
                    
                    header('Content-Type: text/xml');
                    echo $gpv;
                    
                    $taskHandler->updateTaskStatus($current_task['id'], 'in_progress', 'Sent GetParameterValues');
                    try {
                        $stmt = $db->prepare("
                            UPDATE device_tasks 
                            SET updated_at = NOW() 
                            WHERE id = :task_id
                        ");
                        $stmt->execute([':task_id' => $current_task['id']]);
                        tr069_log("Updated timestamp for task {$current_task['id']}", "DEBUG");
                    } catch (PDOException $e) {
                        tr069_log("Database error updating task timestamp: " . $e->getMessage(), "ERROR");
                    }
                    tr069_log("Sent GetParameterValues (task {$current_task['id']}): " . implode(", ", $names), "INFO");
                    exit;
                }
            }
        }
        
        // No tasks
        tr069_log("No pending tasks for empty POST", "DEBUG");
        header('Content-Type: text/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body/>
</soapenv:Envelope>';
        exit;
    }
    
    // Check if this is a GetParameterValuesResponse
    if (stripos($raw_post, 'GetParameterValuesResponse') !== false || 
        (stripos($raw_post, 'ParameterList') !== false && stripos($raw_post, 'ParameterValueStruct') !== false)) {
        
        tr069_log("Detected GetParameterValuesResponse: " . substr($raw_post, 0, 500) . "...", "INFO");
        
        // Extract the SOAP ID
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        // Get serial number and current task
        session_start();
        $serialNumber = $_SESSION['device_serial'] ?? null;
        $current_task = $_SESSION['current_task'] ?? null;
        session_write_close();
        
        if (!$current_task && $serialNumber) {
            try {
                $deviceStmt = $db->prepare("
                    SELECT current_task_id FROM devices 
                    WHERE serial_number = :serial
                ");
                $deviceStmt->execute([':serial' => $serialNumber]);
                $taskId = $deviceStmt->fetchColumn();
                
                if ($taskId) {
                    $taskStmt = $db->prepare("
                        SELECT * FROM device_tasks 
                        WHERE id = :task_id 
                        AND status = 'in_progress'
                    ");
                    $taskStmt->execute([':task_id' => $taskId]);
                    $current_task = $taskStmt->fetch(PDO::FETCH_ASSOC);
                    tr069_log("Retrieved task {$taskId} from database for GPVR", "DEBUG");
                }
            } catch (PDOException $e) {
                tr069_log("Database error retrieving current task for GPVR: " . $e->getMessage(), "ERROR");
            }
        }
        
        if ($serialNumber) {
            try {
                $saver = new ParameterSaver($db, new class {
                    public function logToFile($m) { tr069_log($m, 'INFO'); }
                });
                $saver->save($serialNumber, $raw_post);
                tr069_log("Processed GetParameterValuesResponse for serial: $serialNumber", "INFO");
                
                if ($current_task) {
                    $taskHandler->updateTaskStatus($current_task['id'], 'completed', 'Completed after receiving GetParameterValuesResponse');
                    tr069_log("Completed task: " . $current_task['id'], "INFO");
                    
                    // Clear current task
                    try {
                        $stmt = $db->prepare("
                            UPDATE devices 
                            SET current_task_id = NULL 
                            WHERE serial_number = :serial
                        ");
                        $stmt->execute([':serial' => $serialNumber]);
                        tr069_log("Cleared current task for device: $serialNumber", "INFO");
                    } catch (PDOException $e) {
                        tr069_log("Database error clearing current task: " . $e->getMessage(), "ERROR");
                    }
                    
                    session_start();
                    unset($_SESSION['current_task'], $_SESSION['task_retries'], $_SESSION['last_inform_session']);
                    session_write_close();
                    $GLOBALS['current_task'] = null;
                }
            } catch (Exception $e) {
                tr069_log("Error processing GetParameterValuesResponse: " . $e->getMessage(), "ERROR");
            }
        }
        
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
    
    // Handle generic SOAP responses (including faults)
    if (stripos($raw_post, 'SOAP-ENV:Envelope') !== false || stripos($raw_post, 'soap:Envelope') !== false) {
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        tr069_log("Received generic SOAP response: " . substr($raw_post, 0, 1000) . "...", "DEBUG");
        
        // Check for SOAP Fault
        if (stripos($raw_post, 'SOAP-ENV:Fault') !== false || stripos($raw_post, 'soap:Fault') !== false) {
            preg_match('/<faultcode>(.*?)<\/faultcode>/s', $raw_post, $faultCodeMatches);
            preg_match('/<faultstring>(.*?)<\/faultstring>/s', $raw_post, $faultStringMatches);
            $faultCode = isset($faultCodeMatches[1]) ? trim($faultCodeMatches[1]) : 'Unknown';
            $faultString = isset($faultStringMatches[1]) ? trim($faultStringMatches[1]) : 'No fault string provided';
            
            tr069_log("SOAP Fault received - Code: $faultCode, String: $faultString", "ERROR");
            
            // Fail the current task
            session_start();
            $serialNumber = $_SESSION['device_serial'] ?? null;
            $current_task = $_SESSION['current_task'] ?? null;
            session_write_close();
            
            if (!$current_task && $serialNumber) {
                try {
                    $deviceStmt = $db->prepare("
                        SELECT current_task_id FROM devices 
                        WHERE serial_number = :serial
                    ");
                    $deviceStmt->execute([':serial' => $serialNumber]);
                    $taskId = $deviceStmt->fetchColumn();
                    
                    if ($taskId) {
                        $taskStmt = $db->prepare("
                            SELECT * FROM device_tasks 
                            WHERE id = :task_id 
                            AND status = 'in_progress'
                        ");
                        $taskStmt->execute([':task_id' => $taskId]);
                        $current_task = $taskStmt->fetch(PDO::FETCH_ASSOC);
                        tr069_log("Retrieved task {$taskId} from database for SOAP Fault", "DEBUG");
                    }
                } catch (PDOException $e) {
                    tr069_log("Database error retrieving current task for SOAP Fault: " . $e->getMessage(), "ERROR");
                }
            }
            
            if ($current_task) {
                $taskHandler->updateTaskStatus($current_task['id'], 'failed', "SOAP Fault: $faultCode - $faultString");
                tr069_log("Task {$current_task['id']} failed due to SOAP Fault", "ERROR");
                
                // Clear current task
                try {
                    $stmt = $db->prepare("
                        UPDATE devices 
                        SET current_task_id = NULL 
                        WHERE serial_number = :serial
                    ");
                    $stmt->execute([':serial' => $serialNumber]);
                    tr069_log("Cleared current task for device: $serialNumber", "INFO");
                } catch (PDOException $e) {
                    tr069_log("Database error clearing current task: " . $e->getMessage(), "ERROR");
                }
                
                session_start();
                unset($_SESSION['current_task'], $_SESSION['task_retries'], $_SESSION['last_inform_session']);
                session_write_close();
                $GLOBALS['current_task'] = null;
            }
            
            // Send empty response
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
        
        // Unhandled SOAP message
        tr069_log("Unhandled SOAP message received: " . substr($raw_post, 0, 1000) . "...", "WARNING");
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
    
    // Default response for unhandled messages
    tr069_log("Unhandled message type: " . substr($raw_post, 0, 1000) . "...", "WARNING");
    
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
?>
```