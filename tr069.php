<?php
// Disable all error logging
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 0);

// Tracking variables
$GLOBALS['knownDevices'] = []; // Store known device IPs to reduce duplicate logging
$GLOBALS['discoveredParameters'] = []; // Track parameters we've already discovered
$GLOBALS['errorCounts'] = []; // Track error counts by type
$GLOBALS['lastErrorTime'] = []; // Track last time an error was logged for throttling
$GLOBALS['verifiedParameters'] = []; // Track parameters that were successfully retrieved
$GLOBALS['hostCount'] = 0; // Track number of hosts discovered
$GLOBALS['currentHostIndex'] = 1; // Track which host index we're currently fetching

// Set up logging for this session
$logFile = __DIR__ . '/tr069_session.log';
$sessionId = uniqid('tr069_', true);
$clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Helper function to log messages with timestamps
function logMessage($message, $level = 'INFO') {
    global $logFile, $sessionId, $clientIP;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "$timestamp [$level] Session: $sessionId, IP: $clientIP - $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Start logging this session
logMessage("TR-069 session started");

// Helper function to log router data without duplication
function logRouterData($paramName, $paramValue) {
    // Skip if we've already discovered this parameter
    if (in_array("{$paramName}={$paramValue}", $GLOBALS['discoveredParameters'])) {
        return;
    }
    
    // Check if this is the host count parameter
    if (stripos($paramName, 'HostNumberOfEntries') !== false) {
        $GLOBALS['hostCount'] = intval($paramValue);
    }
    
    // Add to discovered parameters
    $GLOBALS['discoveredParameters'][] = "{$paramName}={$paramValue}";
    
    // Add to verified parameters list (successful retrievals)
    $GLOBALS['verifiedParameters'][] = $paramName;
    
    // Save to the dedicated router_ssids.txt file
    file_put_contents(__DIR__ . '/router_ssids.txt', "{$paramName} = {$paramValue}\n", FILE_APPEND);
    
    // Log the parameter discovery
    logMessage("Discovered parameter: {$paramName} = {$paramValue}");
}

// Helper function to check for pending device tasks
function checkPendingTasks($db, $serialNumber) {
    try {
        // First, get the device ID using serial number
        $deviceQuery = "SELECT id FROM devices WHERE serial_number = :serial_number LIMIT 1";
        $deviceStmt = $db->prepare($deviceQuery);
        $deviceStmt->execute([':serial_number' => $serialNumber]);
        $device = $deviceStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$device) {
            logMessage("No device found with serial number: $serialNumber", "WARNING");
            return [];
        }
        
        $deviceId = $device['id'];
        
        // Get pending tasks for this device
        $taskQuery = "SELECT * FROM device_tasks WHERE device_id = :device_id AND status = 'pending' ORDER BY created_at ASC";
        $taskStmt = $db->prepare($taskQuery);
        $taskStmt->execute([':device_id' => $deviceId]);
        $tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($tasks)) {
            logMessage("Found " . count($tasks) . " pending tasks for device: $serialNumber");
        }
        
        return $tasks;
    } catch (PDOException $e) {
        logMessage("Database error when checking pending tasks: " . $e->getMessage(), "ERROR");
        return [];
    }
}

// Helper function to update task status
function updateTaskStatus($db, $taskId, $status, $message = '') {
    try {
        $query = "UPDATE device_tasks SET status = :status, message = :message, updated_at = NOW() WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':status' => $status,
            ':message' => $message,
            ':id' => $taskId
        ]);
        
        logMessage("Updated task #$taskId status to: $status");
        return true;
    } catch (PDOException $e) {
        logMessage("Failed to update task status: " . $e->getMessage(), "ERROR");
        return false;
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
                [
                    'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DefaultGateway',
                    'value' => $gateway,
                    'type' => 'xsd:string'
                ]
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

// Set unlimited execution time for long-running sessions
set_time_limit(0);

// Basic connection information
$clientIP = $_SERVER['REMOTE_ADDR'];
$isNewSession = !isset($GLOBALS['knownDevices'][$clientIP]);

// Enhanced Huawei device detection based on User-Agent
$isHuawei = false;
$isMikroTik = false;
$modelDetected = '';

if (isset($_SERVER['HTTP_USER_AGENT'])) {
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    
    // Detect MikroTik devices
    if (stripos($userAgent, 'mikrotik') !== false) {
        $isMikroTik = true;
    }
    
    // Detect Huawei devices (these are the ones we're interested in)
    if (stripos($userAgent, 'huawei') !== false || 
        stripos($userAgent, 'hw_') !== false ||
        stripos($userAgent, 'hg8') !== false) {
        $isHuawei = true;
        
        // Try to detect specific model
        if (stripos($userAgent, 'hg8546') !== false) {
            $modelDetected = 'HG8546M';
        }
    }
}

// Additional check in raw POST data for Huawei identifiers
if (!$isHuawei && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    if (!empty($raw_post)) {
        if (stripos($raw_post, 'huawei') !== false || 
            stripos($raw_post, 'hg8') !== false) {
            $isHuawei = true;
            
            // Check for HG8546M model in XML
            if (stripos($raw_post, 'HG8546M') !== false) {
                $modelDetected = 'HG8546M';
            }
        }
    }
}

// Track which parameters have been attempted to avoid loops
session_start();
if (!isset($_SESSION['attempted_parameters'])) {
    $_SESSION['attempted_parameters'] = [];
}

if (!isset($_SESSION['successful_parameters'])) {
    $_SESSION['successful_parameters'] = [];
}

if (!isset($_SESSION['host_count'])) {
    $_SESSION['host_count'] = 0;
}

if (!isset($_SESSION['current_host_index'])) {
    $_SESSION['current_host_index'] = 1;
}

// Initialize database connection for task management
try {
    require_once __DIR__ . '/backend/config/database.php';
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    logMessage("Database connection error: " . $e->getMessage(), "ERROR");
    $db = null;
}

// Skip processing for MikroTik devices entirely - they consistently fail
if ($isMikroTik) {
    // Silent exit - don't even attempt TR-069 requests for MikroTik routers
    header('Content-Length: 0');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    
    // Start with a clean router_ssids.txt file for new connections
    if (stripos($raw_post, '<cwmp:Inform>') !== false && $isHuawei) {
        // Only delete the file if it's a new session
        if (file_exists(__DIR__ . '/router_ssids.txt')) {
            unlink(__DIR__ . '/router_ssids.txt');
            file_put_contents(__DIR__ . '/router_ssids.txt', "# TR-069 WiFi Parameters Discovered " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
        }
        
        // Reset attempted parameters for new sessions
        if (isset($_SESSION['attempted_parameters'])) {
            $_SESSION['attempted_parameters'] = [];
        }
        
        if (isset($_SESSION['successful_parameters'])) {
            $_SESSION['successful_parameters'] = [];
        }
        
        // Reset host tracking variables
        $_SESSION['host_count'] = 0;
        $_SESSION['current_host_index'] = 1;
        $GLOBALS['hostCount'] = 0;
        $GLOBALS['currentHostIndex'] = 1;
    }
    
    if (!empty($raw_post)) {
        // Check if this is an Inform message
        if (stripos($raw_post, '<cwmp:Inform>') !== false) {
            // Extract the SOAP ID
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            
            // Extract model information
            preg_match('/<ProductClass>(.*?)<\/ProductClass>/s', $raw_post, $modelMatches);
            if (isset($modelMatches[1])) {
                $model = trim($modelMatches[1]);
                
                // Check for HG8546M model
                if (stripos($model, 'HG8546M') !== false) {
                    $modelDetected = 'HG8546M';
                }
            }
            
            // Log device model if possible
            preg_match('/<ProductClass>(.*?)<\/ProductClass>.*?<SerialNumber>(.*?)<\/SerialNumber>/s', $raw_post, $deviceMatches);
            if (isset($deviceMatches[1]) && isset($deviceMatches[2])) {
                $model = $deviceMatches[1];
                $serial = $deviceMatches[2];
                // Save to the router_ssids.txt file
                file_put_contents(__DIR__ . '/router_ssids.txt', "InternetGatewayDevice.DeviceInfo.ProductClass = {$model}\n", FILE_APPEND);
                file_put_contents(__DIR__ . '/router_ssids.txt', "InternetGatewayDevice.DeviceInfo.SerialNumber = {$serial}\n", FILE_APPEND);
            }
            
            // Log manufacturer if present in the Inform message
            if (preg_match('/<Manufacturer>(.*?)<\/Manufacturer>/s', $raw_post, $mfrMatches)) {
                $manufacturer = trim($mfrMatches[1]);
                file_put_contents(__DIR__ . '/router_ssids.txt', "InternetGatewayDevice.DeviceInfo.Manufacturer = {$manufacturer}\n", FILE_APPEND);
            }
            
            // Check for pending tasks if we have a serial number and database connection
            $pendingTasks = [];
            if (!empty($serialNumber) && $db !== null) {
                $pendingTasks = checkPendingTasks($db, $serialNumber);
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
            require_once __DIR__ . '/backend/tr069/responses/InformResponseGenerator.php';
            $responseGenerator = new InformResponseGenerator();
            $response = $responseGenerator->createResponse($soapId);
            
            header('Content-Type: text/xml');
            echo $response;
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
                
                // Check if there are more tasks
                $pendingTasks = checkPendingTasks($db, $serialNumber);
                if (!empty($pendingTasks)) {
                    $nextTask = $pendingTasks[0];
                    logMessage("Found another pending task #" . $nextTask['id'] . ". Will process on next Inform.");
                }
            }
            
            // Extract the SOAP ID for the next request
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            
            // Send empty response to end this session
            header('Content-Type: text/xml');
            echo '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
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
            header('Content-Type: text/xml');
            echo '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
            exit;
        }
        
        // Check if this is a GetParameterValuesResponse (contains network data)
        if (stripos($raw_post, 'GetParameterValuesResponse') !== false) {
            // Extract information using regex
            preg_match_all('/<Name>(.*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/si', $raw_post, $matches, PREG_SET_ORDER);
            
            $foundSSIDs = false;
            $foundWANSettings = false;
            $foundConnectedDevices = false;
            $foundHostNumberOfEntries = false;
            $foundDeviceInfoParams = false;
            
            if (!empty($matches)) {
                foreach ($matches as $match) {
                    $paramName = $match[1];
                    $paramValue = $match[2];
                    
                    // Skip empty values
                    if (empty($paramValue) || $paramValue === '(null)') {
                        continue;
                    }
                    
                    // Track this as a successful parameter retrieval
                    if (!in_array($paramName, $_SESSION['successful_parameters'])) {
                        $_SESSION['successful_parameters'][] = $paramName;
                    }
                    
                    // Check if this is the host count parameter
                    if (stripos($paramName, 'HostNumberOfEntries') !== false) {
                        $foundHostNumberOfEntries = true;
                        $_SESSION['host_count'] = intval($paramValue);
                        $GLOBALS['hostCount'] = intval($paramValue);
                    }
                    
                    // Check for device info parameters
                    if (stripos($paramName, 'DeviceInfo.UpTime') !== false ||
                        stripos($paramName, 'DeviceInfo.SoftwareVersion') !== false ||
                        stripos($paramName, 'DeviceInfo.HardwareVersion') !== false ||
                        stripos($paramName, 'DeviceInfo.Manufacturer') !== false) {
                        $foundDeviceInfoParams = true;
                    }
                    
                    // Categorize the parameter by its name
                    if (stripos($paramName, 'SSID') !== false && stripos($paramName, 'WLANConfiguration.1') !== false) {
                        $foundSSIDs = true;
                    } else if (
                        stripos($paramName, 'ExternalIPAddress') !== false || 
                        stripos($paramName, 'SubnetMask') !== false || 
                        stripos($paramName, 'DefaultGateway') !== false || 
                        stripos($paramName, 'DNSServer') !== false
                    ) {
                        $foundWANSettings = true;
                    } else if (
                        stripos($paramName, 'Host') !== false
                    ) {
                        $foundConnectedDevices = true;
                    }
                    
                    // Log the parameter
                    logRouterData($paramName, $paramValue);
                }
            }
            
            // Extract the SOAP ID for the next request
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            
            // Decide which parameter set to try next based on discovery progress
            $nextParam = null;
            
            // If we just got the host count, prioritize getting all host details
            if ($foundHostNumberOfEntries && $_SESSION['host_count'] > 0) {
                // Generate all host parameters
                $dynamicHostParameters = generateHostParameters($_SESSION['host_count']);
                
                // Check which host index we're currently on
                if ($_SESSION['current_host_index'] <= $_SESSION['host_count']) {
                    $nextParam = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$_SESSION['current_host_index']}.IPAddress"];
                    $_SESSION['current_host_index']++;
                }
            }
            
            // If we haven't found device info parameters yet, prioritize them
            if ($nextParam === null && !$foundDeviceInfoParams) {
                foreach ($coreParameters as $param) {
                    if (
                        (stripos($param[0], 'DeviceInfo.UpTime') !== false ||
                        stripos($param[0], 'DeviceInfo.SoftwareVersion') !== false ||
                        stripos($param[0], 'DeviceInfo.HardwareVersion') !== false ||
                        stripos($param[0], 'DeviceInfo.Manufacturer') !== false) &&
                        !in_array(implode(',', $param), $_SESSION['attempted_parameters'])
                    ) {
                        $nextParam = $param;
                        break;
                    }
                }
            }
            
            // If no special parameters to fetch, try core parameters
            if ($nextParam === null) {
                foreach ($coreParameters as $param) {
                    $paramKey = implode(',', $param);
                    if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                        $nextParam = $param;
                        break;
                    }
                }
            }
            
            // If all core parameters have been tried, move to extended parameters
            if ($nextParam === null) {
                foreach ($extendedParameters as $param) {
                    $paramKey = implode(',', $param);
                    if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                        $nextParam = $param;
                        break;
                    }
                }
            }
            
            // Check if we need to try host parameters after finding host count
            if ($nextParam === null && $_SESSION['host_count'] > 0) {
                // Generate host parameters if not already done
                if (empty($dynamicHostParameters)) {
                    $dynamicHostParameters = generateHostParameters($_SESSION['host_count']);
                }
                
                // Find next untried host parameter
                foreach ($dynamicHostParameters as $param) {
                    $paramKey = implode(',', $param);
                    if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                        $nextParam = $param;
                        break;
                    }
                }
            }
            
            // If all extended parameters have been tried, try optional ones
            if ($nextParam === null) {
                foreach ($optionalParameters as $param) {
                    $paramKey = implode(',', $param);
                    if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                        $nextParam = $param;
                        break;
                    }
                }
            }
            
            // If we've tried everything, just complete the session
            if ($nextParam === null) {
                // Store the data in the database before completing
                storeDataInDatabase();
                
                header('Content-Type: text/xml');
                echo '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:SetParameterValuesResponse>
      <Status>0</Status>
    </cwmp:SetParameterValuesResponse>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
                
                exit;
            }
            
            // Mark this parameter set as attempted
            $_SESSION['attempted_parameters'][] = implode(',', $nextParam);
            
            $nextRequest = generateParameterRequestXML($soapId, $nextParam);
            
            header('Content-Type
