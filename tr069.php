
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
            }
        }
        
        // Respond with InformResponse
        $response = $responseGenerator->createResponse($soapId);
        tr069_log("Sending InformResponse", "DEBUG");
        
        header('Content-Type: text/xml');
        echo $response;
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
        if ($GLOBALS['current_task']) {
            $task = $GLOBALS['current_task'];
            tr069_log("Processing task: {$task['task_type']} - ID: {$task['id']}", "INFO");
            
            // Generate parameters for this task
            $parameterRequest = $taskHandler->generateParameterValues($task['task_type'], $task['task_data']);
            
            if ($parameterRequest) {
                // Log what we're about to do
                tr069_log("Sending {$parameterRequest['method']} request for task {$task['id']}", "INFO");
                
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
      <ParameterKey>Task-' . $task['id'] . '-' . substr(md5(time()), 0, 8) . '</ParameterKey>
    </cwmp:SetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';

                    header('Content-Type: text/xml');
                    echo $setParamRequest;
                    
                    // Mark task as in progress
                    $taskHandler->updateTaskStatus($task['id'], 'in_progress', 'Sent parameters to device');
                    
                    exit;
                } elseif ($parameterRequest['method'] === 'Reboot') {
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

                    header('Content-Type: text/xml');
                    echo $rebootRequest;
                    
                    // Mark task as in progress
                    $taskHandler->updateTaskStatus($task['id'], 'in_progress', 'Sent reboot command to device');
                    
                    exit;
                }
            } else {
                tr069_log("Failed to generate parameters for task {$task['id']}", "ERROR");
                $taskHandler->updateTaskStatus($task['id'], 'failed', 'Failed to generate parameters');
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
    
    // Check if this is a SetParameterValuesResponse
    if (stripos($raw_post, 'SetParameterValuesResponse') !== false) {
        // Extract the SOAP ID
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        // Check the status
        preg_match('/<Status>(.*?)<\/Status>/s', $raw_post, $statusMatches);
        $status = isset($statusMatches[1]) ? trim($statusMatches[1]) : null;
        
        tr069_log("Received SetParameterValuesResponse with status: $status", "INFO");
        
        // Update task status
        if ($GLOBALS['current_task']) {
            if ($status === '0') {
                $taskHandler->updateTaskStatus($GLOBALS['current_task']['id'], 'completed', 'Successfully applied ' . $GLOBALS['current_task']['task_type'] . ' configuration');
                tr069_log("Task completed successfully: " . $GLOBALS['current_task']['id'], "INFO");
            } else {
                $taskHandler->updateTaskStatus($GLOBALS['current_task']['id'], 'failed', 'Device returned error status: ' . $status);
                tr069_log("Task failed: " . $GLOBALS['current_task']['id'] . " - Status: " . $status, "ERROR");
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
    
    // Check if this is a RebootResponse
    if (stripos($raw_post, 'RebootResponse') !== false) {
        // Extract the SOAP ID
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        tr069_log("Received RebootResponse", "INFO");
        
        // Update task status
        if ($GLOBALS['current_task']) {
            $taskHandler->updateTaskStatus($GLOBALS['current_task']['id'], 'completed', 'Device reboot initiated successfully');
            tr069_log("Reboot task completed: " . $GLOBALS['current_task']['id'], "INFO");
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
