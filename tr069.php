
<?php
// Disable all error logging
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 0);

// Define a custom log file
$logFile = __DIR__ . '/tr069_session.log';

// Helper function to log messages with timestamps
function logMessage($message, $level = 'INFO') {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $logEntry = "$timestamp [$level] IP: $clientIP - $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Start logging this session
logMessage("TR-069 session started");

// Helper function to log router data
function logRouterData($paramName, $paramValue) {
    // Save to the dedicated router_ssids.txt file
    file_put_contents(__DIR__ . '/router_ssids.txt', "{$paramName} = {$paramValue}\n", FILE_APPEND);
    
    // Log the parameter discovery
    logMessage("Discovered parameter: {$paramName} = {$paramValue}");
}

// Set unlimited execution time for long-running sessions
set_time_limit(0);

// Initialize database connection
try {
    require_once __DIR__ . '/backend/config/database.php';
    require_once __DIR__ . '/backend/functions/device_functions.php';
    
    $database = new Database();
    $db = $database->getConnection();
    logMessage("Database connection established");
} catch (Exception $e) {
    logMessage("Database connection error: " . $e->getMessage(), "ERROR");
    $db = null;
}

// Track which parameters have been attempted to avoid loops
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    
    if (!empty($raw_post)) {
        // Check if this is an Inform message
        if (stripos($raw_post, '<cwmp:Inform>') !== false) {
            logMessage("Received Inform message");
            
            // Extract the SOAP ID
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            
            // Extract device information from the Inform message
            $serialNumber = '';
            if (preg_match('/<SerialNumber>(.*?)<\/SerialNumber>/s', $raw_post, $serialMatches)) {
                $serialNumber = trim($serialMatches[1]);
                logMessage("Device serial number: {$serialNumber}");
            }
            
            if (preg_match('/<ProductClass>(.*?)<\/ProductClass>/s', $raw_post, $modelMatches)) {
                $model = trim($modelMatches[1]);
                logMessage("Device model: {$model}");
            }
            
            if (preg_match('/<Manufacturer>(.*?)<\/Manufacturer>/s', $raw_post, $mfrMatches)) {
                $manufacturer = trim($mfrMatches[1]);
                logMessage("Device manufacturer: {$manufacturer}");
            }
            
            // Check for pending tasks if we have a serial number and database connection
            $pendingTasks = [];
            if (!empty($serialNumber) && $db !== null) {
                logMessage("Checking for pending tasks for device: {$serialNumber}");
                $pendingTasks = getPendingTasksBySerial($db, $serialNumber);
            }
            
            // If there are pending tasks, process the first one
            if (!empty($pendingTasks)) {
                $task = $pendingTasks[0]; // Get the first task
                
                logMessage("Processing pending task ID: " . $task['id'] . ", Type: " . $task['task_type']);
                
                // Process different task types
                $taskProcessed = false;
                
                switch ($task['task_type']) {
                    case 'wifi':
                        $taskProcessed = processWifiTask($db, $task, $soapId);
                        break;
                    case 'wan':
                        $taskProcessed = processWanTask($db, $task, $soapId);
                        break;
                    case 'reboot':
                        $taskProcessed = processRebootTask($db, $task, $soapId);
                        break;
                    default:
                        logMessage("Unknown task type: " . $task['task_type'], "WARNING");
                        updateTaskStatus($db, $task['id'], 'failed', 'Unknown task type');
                        break;
                }
                
                // If task was processed, exit early to let the device process it
                if ($taskProcessed) {
                    exit;
                }
            }
            
            // If no tasks or task processing failed, respond with regular InformResponse
            sendInformResponse($soapId);
            exit;
        }
        
        // Check for SetParameterValuesResponse (task completion)
        if (stripos($raw_post, 'SetParameterValuesResponse') !== false) {
            // Extract the status
            preg_match('/<Status>(.*?)<\/Status>/s', $raw_post, $statusMatches);
            $status = isset($statusMatches[1]) ? trim($statusMatches[1]) : '9999';
            
            logMessage("Received SetParameterValuesResponse with status: $status");
            
            // If we were processing a task, update its status
            if (isset($_SESSION['current_task_id']) && isset($_SESSION['current_task_type']) && $db !== null) {
                $taskId = $_SESSION['current_task_id'];
                $taskType = $_SESSION['current_task_type'];
                
                if ($status == '0') {
                    // Success
                    updateTaskStatus($db, $taskId, 'completed', "Successfully applied $taskType configuration");
                    logMessage("Task #$taskId ($taskType) completed successfully");
                } else {
                    // Failure
                    updateTaskStatus($db, $taskId, 'failed', "Failed with status code: $status");
                    logMessage("Task #$taskId ($taskType) failed with status: $status", "ERROR");
                }
                
                // Clear the current task
                unset($_SESSION['current_task_id']);
                unset($_SESSION['current_task_type']);
            }
            
            // Extract the SOAP ID for the next request
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            
            // Send empty response to end this session
            sendEmptyResponse($soapId);
            exit;
        }
        
        // Check for RebootResponse
        if (stripos($raw_post, 'RebootResponse') !== false) {
            logMessage("Received RebootResponse");
            
            // If we were processing a reboot task, update its status
            if (isset($_SESSION['current_task_id']) && $_SESSION['current_task_type'] === 'reboot' && $db !== null) {
                $taskId = $_SESSION['current_task_id'];
                
                // Mark task as completed
                updateTaskStatus($db, $taskId, 'completed', "Reboot command sent successfully");
                logMessage("Reboot task #$taskId completed successfully");
                
                // Clear the current task
                unset($_SESSION['current_task_id']);
                unset($_SESSION['current_task_type']);
            }
            
            // Extract the SOAP ID for the next request
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            
            // Send empty response to end this session
            sendEmptyResponse($soapId);
            exit;
        }
    }
}

// Helper function to process WiFi configuration task
function processWifiTask($db, $task, $soapId) {
    try {
        $taskData = json_decode($task['task_data'], true);
        
        if (!isset($taskData['ssid']) || !isset($taskData['password'])) {
            logMessage("Invalid WiFi task data", "ERROR");
            updateTaskStatus($db, $task['id'], 'failed', 'Invalid task data');
            return false;
        }
        
        $ssid = $taskData['ssid'];
        $password = $taskData['password'];
        
        logMessage("Processing WiFi configuration task: SSID = $ssid");
        
        // Create parameter list for SOAP request
        $parameterList = [
            [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                'value' => $ssid,
                'type' => 'xsd:string'
            ],
            [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
                'value' => $password,
                'type' => 'xsd:string'
            ],
            [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',
                'value' => 'WPAand11i',
                'type' => 'xsd:string'
            ],
            [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.WPAEncryptionModes',
                'value' => 'AESEncryption',
                'type' => 'xsd:string'
            ]
        ];
        
        // Generate XML and send it
        $setParameterRequest = generateSetParameterValuesXML($soapId, $parameterList);
        
        // Log the request
        logMessage("Sending WiFi configuration request for task #" . $task['id']);
        
        header('Content-Type: text/xml');
        echo $setParameterRequest;
        
        // Mark task as processing - it will be completed when we get the SetParameterValuesResponse
        updateTaskStatus($db, $task['id'], 'processing', 'Sending configuration to device');
        
        // Store the task ID in session for response handling
        $_SESSION['current_task_id'] = $task['id'];
        $_SESSION['current_task_type'] = 'wifi';
        
        return true;
    } catch (Exception $e) {
        logMessage("Error processing WiFi task: " . $e->getMessage(), "ERROR");
        updateTaskStatus($db, $task['id'], 'failed', $e->getMessage());
        return false;
    }
}

// Helper function to process WAN configuration task
function processWanTask($db, $task, $soapId) {
    try {
        $taskData = json_decode($task['task_data'], true);
        
        if (!isset($taskData['ip_address'])) {
            logMessage("Invalid WAN task data", "ERROR");
            updateTaskStatus($db, $task['id'], 'failed', 'Invalid task data');
            return false;
        }
        
        $ipAddress = $taskData['ip_address'];
        $gateway = $taskData['gateway'] ?? '';
        
        logMessage("Processing WAN configuration task: IP = $ipAddress, Gateway = $gateway");
        
        // Create parameter list for SOAP request
        $parameterList = [
            [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
                'value' => $ipAddress,
                'type' => 'xsd:string'
            ]
        ];
        
        if (!empty($gateway)) {
            $parameterList[] = [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DefaultGateway',
                'value' => $gateway,
                'type' => 'xsd:string'
            ];
        }
        
        // Generate XML and send it
        $setParameterRequest = generateSetParameterValuesXML($soapId, $parameterList);
        
        // Log the request
        logMessage("Sending WAN configuration request for task #" . $task['id']);
        
        header('Content-Type: text/xml');
        echo $setParameterRequest;
        
        // Mark task as processing - it will be completed when we get the SetParameterValuesResponse
        updateTaskStatus($db, $task['id'], 'processing', 'Sending configuration to device');
        
        // Store the task ID in session for response handling
        $_SESSION['current_task_id'] = $task['id'];
        $_SESSION['current_task_type'] = 'wan';
        
        return true;
    } catch (Exception $e) {
        logMessage("Error processing WAN task: " . $e->getMessage(), "ERROR");
        updateTaskStatus($db, $task['id'], 'failed', $e->getMessage());
        return false;
    }
}

// Helper function to process reboot task
function processRebootTask($db, $task, $soapId) {
    try {
        logMessage("Processing reboot task #" . $task['id']);
        
        // Create the reboot request XML
        $rebootRequest = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:Reboot>
      <CommandKey>Reboot-' . time() . '</CommandKey>
    </cwmp:Reboot>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
        
        header('Content-Type: text/xml');
        echo $rebootRequest;
        
        // Mark task as processing - it will be completed when we get the RebootResponse
        updateTaskStatus($db, $task['id'], 'processing', 'Sending reboot command to device');
        
        // Store the task ID in session for response handling
        $_SESSION['current_task_id'] = $task['id'];
        $_SESSION['current_task_type'] = 'reboot';
        
        return true;
    } catch (Exception $e) {
        logMessage("Error processing reboot task: " . $e->getMessage(), "ERROR");
        updateTaskStatus($db, $task['id'], 'failed', $e->getMessage());
        return false;
    }
}

// Helper function to generate SetParameterValues XML
function generateSetParameterValuesXML($soapId, $parameters) {
    $count = count($parameters);
    $paramXml = '';
    
    foreach ($parameters as $param) {
        $paramXml .= "        <ParameterValueStruct>\n";
        $paramXml .= "          <Name>" . htmlspecialchars($param['name']) . "</Name>\n";
        $paramXml .= "          <Value xsi:type=\"" . htmlspecialchars($param['type']) . "\">" . htmlspecialchars($param['value']) . "</Value>\n";
        $paramXml .= "        </ParameterValueStruct>\n";
    }
    
    $xml = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:SetParameterValues>
      <ParameterList SOAP-ENC:arrayType="cwmp:ParameterValueStruct[' . $count . ']">
' . $paramXml . '      </ParameterList>
      <ParameterKey>ChangeParams' . substr(md5(uniqid()), 0, 3) . '</ParameterKey>
    </cwmp:SetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
    
    return $xml;
}

// Helper function to send InformResponse
function sendInformResponse($soapId) {
    $response = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:InformResponse>
      <MaxEnvelopes>1</MaxEnvelopes>
    </cwmp:InformResponse>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
    
    header('Content-Type: text/xml');
    echo $response;
}

// Helper function to send empty response
function sendEmptyResponse($soapId) {
    $response = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
    
    header('Content-Type: text/xml');
    echo $response;
}
