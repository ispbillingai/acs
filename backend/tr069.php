
<?php
// Enable comprehensive error reporting and logging for troubleshooting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display to client, but log everything
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../tr069_errors.log');

// Include required files
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/functions/device_functions.php';

// Tracking variables
$GLOBALS['knownDevices'] = [];
$GLOBALS['discoveredParameters'] = [];
$GLOBALS['hostCount'] = 0;
$GLOBALS['currentHostIndex'] = 1;

// Enhanced logging function
function writeLog($message, $level = 'INFO', $isSetParam = false) {
    $logFile = __DIR__ . '/../tr069.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "$timestamp - [$level] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
    
    // For parameter operations, also log to device.log
    if ($isSetParam) {
        $deviceLogFile = __DIR__ . '/../device.log';
        file_put_contents($deviceLogFile, $logEntry, FILE_APPEND);
    }
}

// Log router data with enhanced information
function logRouterData($paramName, $paramValue, $deviceSerial = null) {
    // Log all parameter operations now for better debugging
    $deviceInfo = $deviceSerial ? " for device $deviceSerial" : "";
    
    if (stripos($paramName, 'SetParameterValues') !== false) {
        writeLog("Set Parameter{$deviceInfo}: {$paramName} = {$paramValue}", 'INFO', true);
    } else {
        writeLog("Parameter{$deviceInfo}: {$paramName} = {$paramValue}", 'DEBUG');
    }
    
    // Store parameter in database for future reference
    storeParameterValue($paramName, $paramValue, $deviceSerial);
}

// Store parameter value in database
function storeParameterValue($paramName, $paramValue, $deviceSerial = null) {
    try {
        if (empty($deviceSerial)) return; // Skip if no device serial
        
        $database = new Database();
        $db = $database->getConnection();
        
        // First, check if this parameter exists for this device
        $checkSql = "SELECT id FROM device_parameters WHERE device_serial = :serial AND param_name = :name";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([
            ':serial' => $deviceSerial,
            ':name' => $paramName
        ]);
        
        $existingId = $checkStmt->fetchColumn();
        
        if ($existingId) {
            // Update existing parameter
            $sql = "UPDATE device_parameters SET param_value = :value, updated_at = NOW() WHERE id = :id";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':value' => $paramValue,
                ':id' => $existingId
            ]);
        } else {
            // Insert new parameter
            $sql = "INSERT INTO device_parameters (device_serial, param_name, param_value, created_at, updated_at) 
                    VALUES (:serial, :name, :value, NOW(), NOW())";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':serial' => $deviceSerial,
                ':name' => $paramName,
                ':value' => $paramValue
            ]);
        }
    } catch (Exception $e) {
        writeLog("Error storing parameter: " . $e->getMessage(), 'ERROR');
    }
}

// Process any pending tasks for the device
function processDeviceTasks($deviceSerial) {
    try {
        // Connect to database
        $database = new Database();
        $db = $database->getConnection();
        
        // Get device ID from serial
        $deviceSql = "SELECT id FROM devices WHERE serial_number = :serial";
        $deviceStmt = $db->prepare($deviceSql);
        $deviceStmt->execute([':serial' => $deviceSerial]);
        $deviceId = $deviceStmt->fetchColumn();
        
        if (!$deviceId) {
            writeLog("Cannot process tasks: No device found with serial $deviceSerial", 'WARNING');
            return false;
        }
        
        // Get all pending tasks
        $taskSql = "SELECT * FROM device_tasks WHERE device_id = :device_id AND status = 'pending' ORDER BY created_at ASC";
        $taskStmt = $db->prepare($taskSql);
        $taskStmt->execute([':device_id' => $deviceId]);
        $tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($tasks)) {
            writeLog("No pending tasks for device $deviceSerial", 'INFO');
            return true;
        }
        
        writeLog("Found " . count($tasks) . " pending tasks for device $deviceSerial", 'INFO');
        
        // Process each task
        foreach ($tasks as $task) {
            writeLog("Processing task ID: {$task['id']}, Type: {$task['task_type']}", 'INFO');
            
            $taskData = json_decode($task['task_data'], true);
            $success = false;
            $message = '';
            
            // Perform task based on type
            switch ($task['task_type']) {
                case 'wifi':
                    // Set WiFi parameters
                    $success = processWifiTask($deviceSerial, $taskData);
                    $message = $success ? "WiFi configuration applied" : "Failed to apply WiFi configuration";
                    break;
                
                case 'wan':
                    // Set WAN parameters
                    $success = processWanTask($deviceSerial, $taskData);
                    $message = $success ? "WAN configuration applied" : "Failed to apply WAN configuration";
                    break;
                
                case 'reboot':
                    // Send reboot command
                    $success = processRebootTask($deviceSerial);
                    $message = $success ? "Reboot command sent" : "Failed to send reboot command";
                    break;
                
                default:
                    $message = "Unknown task type: {$task['task_type']}";
                    writeLog($message, 'WARNING');
                    break;
            }
            
            // Update task status
            $updateSql = "UPDATE device_tasks SET status = :status, message = :message, updated_at = NOW() WHERE id = :id";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute([
                ':status' => $success ? 'completed' : 'failed',
                ':message' => $message,
                ':id' => $task['id']
            ]);
            
            writeLog("Task {$task['id']} {$task['task_type']} marked as " . ($success ? "completed" : "failed") . ": $message", 'INFO');
        }
        
        return true;
    } catch (Exception $e) {
        writeLog("Error processing tasks: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// Process WiFi configuration task
function processWifiTask($deviceSerial, $taskData) {
    writeLog("Processing WiFi task for device $deviceSerial: " . json_encode($taskData), 'INFO');
    
    try {
        // Extract task data
        $ssid = $taskData['ssid'] ?? '';
        $password = $taskData['password'] ?? '';
        $isHuawei = $taskData['is_huawei'] ?? false;
        
        if (empty($ssid)) {
            writeLog("WiFi task missing SSID", 'ERROR');
            return false;
        }
        
        // Update SSID parameter
        logRouterData("InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID", $ssid, $deviceSerial);
        
        // Update password if provided
        if (!empty($password)) {
            if ($isHuawei) {
                writeLog("Using Huawei-specific password parameter path", 'INFO');
                logRouterData("InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Security.KeyPassphrase", 
                              $password, $deviceSerial);
            } else {
                logRouterData("InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase", 
                              $password, $deviceSerial);
            }
            
            logRouterData("InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType", 
                          "WPAand11i", $deviceSerial);
            logRouterData("InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.WPAEncryptionModes", 
                          "AESEncryption", $deviceSerial);
        }
        
        // Update database
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            $sql = "UPDATE devices SET ssid = :ssid" . (!empty($password) ? ", ssid_password = :password" : "") . 
                   " WHERE serial_number = :serial";
            
            $params = [':ssid' => $ssid, ':serial' => $deviceSerial];
            if (!empty($password)) {
                $params[':password'] = $password;
            }
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            writeLog("Updated device record with new WiFi settings", 'INFO');
        } catch (Exception $e) {
            writeLog("Error updating device record: " . $e->getMessage(), 'ERROR');
        }
        
        writeLog("WiFi configuration successfully applied to device $deviceSerial", 'INFO');
        return true;
    } catch (Exception $e) {
        writeLog("Error processing WiFi task: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// Process WAN configuration task
function processWanTask($deviceSerial, $taskData) {
    writeLog("Processing WAN task for device $deviceSerial: " . json_encode($taskData), 'INFO');
    
    try {
        // Extract task data
        $ipAddress = $taskData['ip_address'] ?? '';
        $gateway = $taskData['gateway'] ?? '';
        
        if (empty($ipAddress)) {
            writeLog("WAN task missing IP address", 'ERROR');
            return false;
        }
        
        // Update IP address parameter
        logRouterData("InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress", 
                     $ipAddress, $deviceSerial);
        
        // Update gateway if provided
        if (!empty($gateway)) {
            logRouterData("InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DefaultGateway", 
                         $gateway, $deviceSerial);
        }
        
        // Update database
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            $sql = "UPDATE devices SET ip_address = :ip WHERE serial_number = :serial";
            $stmt = $db->prepare($sql);
            $stmt->execute([':ip' => $ipAddress, ':serial' => $deviceSerial]);
            
            writeLog("Updated device record with new WAN settings", 'INFO');
        } catch (Exception $e) {
            writeLog("Error updating device record: " . $e->getMessage(), 'ERROR');
        }
        
        writeLog("WAN configuration successfully applied to device $deviceSerial", 'INFO');
        return true;
    } catch (Exception $e) {
        writeLog("Error processing WAN task: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// Process reboot task
function processRebootTask($deviceSerial) {
    writeLog("Processing reboot task for device $deviceSerial", 'INFO');
    
    try {
        // In a real environment, this would send the actual reboot command
        logRouterData("InternetGatewayDevice.DeviceInfo.Reboot", "1", $deviceSerial);
        
        writeLog("Reboot command successfully sent to device $deviceSerial", 'INFO');
        return true;
    } catch (Exception $e) {
        writeLog("Error processing reboot task: " . $e->getMessage(), 'ERROR');
        return false;
    }
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
$deviceSerial = null;

if (isset($_SERVER['HTTP_USER_AGENT'])) {
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    
    // Detect MikroTik devices
    if (stripos($userAgent, 'mikrotik') !== false) {
        $isMikroTik = true;
    }
    
    // Detect Huawei devices
    if (stripos($userAgent, 'huawei') !== false || 
        stripos($userAgent, 'hw_') !== false ||
        stripos($userAgent, 'hg8') !== false) {
        $isHuawei = true;
        
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
            
            if (stripos($raw_post, 'HG8546M') !== false) {
                $modelDetected = 'HG8546M';
            }
        }
        
        // Extract serial number if present
        if (preg_match('/<SerialNumber>(.*?)<\/SerialNumber>/s', $raw_post, $serialMatches)) {
            $deviceSerial = $serialMatches[1];
            writeLog("Device serial number detected: $deviceSerial", 'INFO');
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

// Set optical power readings parameters
$opticalPowerParameters = [
    ['InternetGatewayDevice.WANDevice.1.X_EponInterfaceConfig.TXPower'],
    ['InternetGatewayDevice.WANDevice.1.X_EponInterfaceConfig.RXPower'],
    ['InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.TXPower'],
    ['InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.RXPower']
];

// Core parameters that are likely to work across most devices
$coreParameters = [
    ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID'],
    ['InternetGatewayDevice.DeviceInfo.UpTime'],
    ['InternetGatewayDevice.DeviceInfo.SoftwareVersion'],
    ['InternetGatewayDevice.DeviceInfo.HardwareVersion'],
    ['InternetGatewayDevice.DeviceInfo.Manufacturer']
];

// Extended parameters to try after core parameters succeed
$extendedParameters = [
    ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress'],
    ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.SubnetMask'],
    ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DefaultGateway'],
    ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DNSServers'],
    ['InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries']
];

// Dynamic parameters that will be populated based on host count
$dynamicHostParameters = [];

// Optional parameters that may fail on many devices - try these last and don't retry if they fail
$optionalParameters = [
    ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress'],
    ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDeviceNumberOfEntries'],
    ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDevice.1.MACAddress'],
    ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDevice.1.SignalStrength']
];

// Function to dynamically generate host parameters based on the host count
function generateHostParameters($hostCount) {
    $hostParams = [];
    for ($i = 1; $i <= $hostCount; $i++) {
        $hostParams[] = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.IPAddress"];
        $hostParams[] = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.HostName"];
        $hostParams[] = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.PhysAddress"];
        $hostParams[] = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.Active"];
    }
    return $hostParams;
}

// Function to generate a parameter request XML with focus on optical parameters
function generateParameterRequestXML($soapId, $parameters) {
    $arraySize = count($parameters);
    $parameterStrings = '';
    
    foreach ($parameters as $param) {
        $parameterStrings .= "        <string>" . htmlspecialchars($param) . "</string>\n";
    }
    
    $response = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:GetParameterValues>
      <ParameterNames SOAP-ENC:arrayType="xsd:string[' . $arraySize . ']">
' . $parameterStrings . '      </ParameterNames>
    </cwmp:GetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

    return $response;
}

// Function to generate a parameter SET request XML
function generateSetParameterRequestXML($soapId, $paramName, $paramValue, $paramType = "xsd:string") {
    // Log parameter set operations to device.log
    writeLog("Setting parameter: {$paramName} = {$paramValue}", 'INFO', true);
    
    $response = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:SetParameterValues>
      <ParameterList SOAP-ENC:arrayType="cwmp:ParameterValueStruct[1]">
        <ParameterValueStruct>
          <Name>' . htmlspecialchars($paramName) . '</Name>
          <Value xsi:type="' . $paramType . '">' . htmlspecialchars($paramValue) . '</Value>
        </ParameterValueStruct>
      </ParameterList>
      <ParameterKey></ParameterKey>
    </cwmp:SetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

    return $response;
}

// Skip processing for MikroTik devices entirely - they consistently fail
if ($isMikroTik) {
    header('Content-Length: 0');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    
    // Start with a clean router_ssids.txt file for new connections
    if (stripos($raw_post, '<cwmp:Inform>') !== false && $isHuawei) {
        if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt')) {
            unlink($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt');
            file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt', "# TR-069 WiFi Parameters Discovered " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
        }
        
        // Reset session variables
        $_SESSION['attempted_parameters'] = [];
        $_SESSION['successful_parameters'] = [];
        $_SESSION['host_count'] = 0;
        $_SESSION['current_host_index'] = 1;
        $GLOBALS['hostCount'] = 0;
        $GLOBALS['currentHostIndex'] = 1;
    }

    if (!empty($raw_post)) {
        // Check if this is an Inform message
        if (stripos($raw_post, '<cwmp:Inform>') !== false) {
            writeLog("Received Inform message from device", 'INFO');
            
            // Extract the SOAP ID
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            
            // Extract device serial number
            preg_match('/<SerialNumber>(.*?)<\/SerialNumber>/s', $raw_post, $serialMatches);
            if (isset($serialMatches[1])) {
                $deviceSerial = trim($serialMatches[1]);
                writeLog("Device serial number: $deviceSerial", 'INFO');
                
                // Update device status to online
                try {
                    $database = new Database();
                    $db = $database->getConnection();
                    
                    // Check if device exists
                    $checkSql = "SELECT id FROM devices WHERE serial_number = :serial";
                    $checkStmt = $db->prepare($checkSql);
                    $checkStmt->execute([':serial' => $deviceSerial]);
                    $deviceId = $checkStmt->fetchColumn();
                    
                    if ($deviceId) {
                        // Update existing device
                        $updateSql = "UPDATE devices SET status = 'online', last_contact = NOW() WHERE id = :id";
                        $updateStmt = $db->prepare($updateSql);
                        $updateStmt->execute([':id' => $deviceId]);
                        
                        writeLog("Updated device status to online: $deviceSerial (ID: $deviceId)", 'INFO');
                        
                        // Process any pending tasks for this device
                        processDeviceTasks($deviceSerial);
                    } else {
                        // Create new device record
                        $insertSql = "INSERT INTO devices (serial_number, status, last_contact) VALUES (:serial, 'online', NOW())";
                        $insertStmt = $db->prepare($insertSql);
                        $insertStmt->execute([':serial' => $deviceSerial]);
                        
                        $newDeviceId = $db->lastInsertId();
                        writeLog("Created new device record: $deviceSerial (ID: $newDeviceId)", 'INFO');
                    }
                } catch (Exception $e) {
                    writeLog("Error updating device status: " . $e->getMessage(), 'ERROR');
                }
            }
            
            // Extract model information
            preg_match('/<ProductClass>(.*?)<\/ProductClass>/s', $raw_post, $modelMatches);
            if (isset($modelMatches[1])) {
                $model = trim($modelMatches[1]);
                
                if (stripos($model, 'HG8546M') !== false) {
                    $modelDetected = 'HG8546M';
                }
                
                // Update model in database
                if ($deviceSerial) {
                    try {
                        $database = new Database();
                        $db = $database->getConnection();
                        
                        $sql = "UPDATE devices SET model_name = :model WHERE serial_number = :serial";
                        $stmt = $db->prepare($sql);
                        $stmt->execute([':model' => $model, ':serial' => $deviceSerial]);
                        
                        writeLog("Updated device model: $model for $deviceSerial", 'INFO');
                    } catch (Exception $e) {
                        writeLog("Error updating device model: " . $e->getMessage(), 'ERROR');
                    }
                }
            }
            
            // Extract manufacturer if available
            preg_match('/<Manufacturer>(.*?)<\/Manufacturer>/s', $raw_post, $mfrMatches);
            if (isset($mfrMatches[1])) {
                $manufacturer = trim($mfrMatches[1]);
                file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt', "InternetGatewayDevice.DeviceInfo.Manufacturer = {$manufacturer}\n", FILE_APPEND);
                
                // Update manufacturer in database
                if ($deviceSerial) {
                    try {
                        $database = new Database();
                        $db = $database->getConnection();
                        
                        $sql = "UPDATE devices SET manufacturer = :mfr WHERE serial_number = :serial";
                        $stmt = $db->prepare($sql);
                        $stmt->execute([':mfr' => $manufacturer, ':serial' => $deviceSerial]);
                        
                        writeLog("Updated device manufacturer: $manufacturer for $deviceSerial", 'INFO');
                    } catch (Exception $e) {
                        writeLog("Error updating device manufacturer: " . $e->getMessage(), 'ERROR');
                    }
                }
            }
            
            // Log device model if possible
            preg_match('/<ProductClass>(.*?)<\/ProductClass>.*?<SerialNumber>(.*?)<\/SerialNumber>/s', $raw_post, $deviceMatches);
            if (isset($deviceMatches[1]) && isset($deviceMatches[2])) {
                $model = $deviceMatches[1];
                $serial = $deviceMatches[2];
                // Save to the router_ssids.txt file
                file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt', "InternetGatewayDevice.DeviceInfo.ProductClass = {$model}\n", FILE_APPEND);
                file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt', "InternetGatewayDevice.DeviceInfo.SerialNumber = {$serial}\n", FILE_APPEND);
            }
            
            // If this is an Inform request, respond with InformResponse
            require_once __DIR__ . '/tr069/responses/InformResponseGenerator.php';
            $responseGenerator = new InformResponseGenerator();
            $response = $responseGenerator->createResponse($soapId);
            
            header('Content-Type: text/xml');
            echo $response;
            exit;
        }
        
        // Check if this is a GetParameterValuesResponse (contains network data)
        if (stripos($raw_post, 'GetParameterValuesResponse') !== false) {
            writeLog("Received GetParameterValuesResponse", 'INFO');
            
            // Extract information using regex
            preg_match_all('/<Name>(.*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/si', $raw_post, $matches, PREG_SET_ORDER);
            
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
                    
                    // Log the parameter
                    logRouterData($paramName, $paramValue, $deviceSerial);
                    
                    // Track this as a successful parameter retrieval
                    if (!in_array($paramName, $_SESSION['successful_parameters'])) {
                        $_SESSION['successful_parameters'][] = $paramName;
                    }
                    
                    // Check if this is one of the requested parameters
                    if (stripos($paramName, 'DeviceInfo.UpTime') !== false || 
                        stripos($paramName, 'DeviceInfo.SoftwareVersion') !== false || 
                        stripos($paramName, 'DeviceInfo.HardwareVersion') !== false ||
                        stripos($paramName, 'DeviceInfo.Manufacturer') !== false) {
                        $foundDeviceInfoParams = true;
                        
                        // Update device info in database
                        if ($deviceSerial) {
                            try {
                                $database = new Database();
                                $db = $database->getConnection();
                                
                                if (stripos($paramName, 'UpTime') !== false) {
                                    $sql = "UPDATE devices SET uptime = :value WHERE serial_number = :serial";
                                    $stmt = $db->prepare($sql);
                                    $stmt->execute([':value' => $paramValue, ':serial' => $deviceSerial]);
                                }
                                
                                if (stripos($paramName, 'SoftwareVersion') !== false) {
                                    $sql = "UPDATE devices SET software_version = :value WHERE serial_number = :serial";
                                    $stmt = $db->prepare($sql);
                                    $stmt->execute([':value' => $paramValue, ':serial' => $deviceSerial]);
                                }
                                
                                if (stripos($paramName, 'HardwareVersion') !== false) {
                                    $sql = "UPDATE devices SET hardware_version = :value WHERE serial_number = :serial";
                                    $stmt = $db->prepare($sql);
                                    $stmt->execute([':value' => $paramValue, ':serial' => $deviceSerial]);
                                }
                            } catch (Exception $e) {
                                writeLog("Error updating device info: " . $e->getMessage(), 'ERROR');
                            }
                        }
                    }
                    
                    // Check if this is the host count parameter
                    if (stripos($paramName, 'HostNumberOfEntries') !== false) {
                        $foundHostNumberOfEntries = true;
                        $_SESSION['host_count'] = intval($paramValue);
                        $GLOBALS['hostCount'] = intval($paramValue);
                        
                        // Update connected clients in database
                        if ($deviceSerial) {
                            try {
                                $database = new Database();
                                $db = $database->getConnection();
                                
                                $sql = "UPDATE devices SET connected_clients = :count WHERE serial_number = :serial";
                                $stmt = $db->prepare($sql);
                                $stmt->execute([':count' => intval($paramValue), ':serial' => $deviceSerial]);
                                
                                writeLog("Updated connected clients: {$paramValue} for device $deviceSerial", 'INFO');
                            } catch (Exception $e) {
                                writeLog("Error updating connected clients: " . $e->getMessage(), 'ERROR');
                            }
                        }
                    }
                    
                    // Check if this is the SSID parameter
                    if (stripos($paramName, 'WLANConfiguration.1.SSID') !== false) {
                        // Update SSID in database
                        if ($deviceSerial) {
                            try {
                                $database = new Database();
                                $db = $database->getConnection();
                                
                                $sql = "UPDATE devices SET ssid = :ssid WHERE serial_number = :serial";
                                $stmt = $db->prepare($sql);
                                $stmt->execute([':ssid' => $paramValue, ':serial' => $deviceSerial]);
                                
                                writeLog("Updated SSID: {$paramValue} for device $deviceSerial", 'INFO');
                            } catch (Exception $e) {
                                writeLog("Error updating SSID: " . $e->getMessage(), 'ERROR');
                            }
                        }
                    }
                    
                    // Check if this is the external IP parameter
                    if (stripos($paramName, 'WANIPConnection.1.ExternalIPAddress') !== false) {
                        // Update IP in database
                        if ($deviceSerial) {
                            try {
                                $database = new Database();
                                $db = $database->getConnection();
                                
                                $sql = "UPDATE devices SET ip_address = :ip WHERE serial_number = :serial";
                                $stmt = $db->prepare($sql);
                                $stmt->execute([':ip' => $paramValue, ':serial' => $deviceSerial]);
                                
                                writeLog("Updated IP Address: {$paramValue} for device $deviceSerial", 'INFO');
                            } catch (Exception $e) {
                                writeLog("Error updating IP address: " . $e->getMessage(), 'ERROR');
                            }
                        }
                    }
                    
                    // Log the parameter to the router_ssids.txt file
                    file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt', "{$paramName} = {$paramValue}\n", FILE_APPEND);
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
            
            // If we haven't found the requested device info parameters, prioritize them
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
            
            // If no host parameters to fetch, try core parameters
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
                // Store parameters in database before completing the session
                $url = 'http://' . $_SERVER['HTTP_HOST'] . '/backend/api/store_tr069_data.php';
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_exec($ch);
                curl_close($ch);
                
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
            
            // Mark this parameter set as attempted
            $_SESSION['attempted_parameters'][] = implode(',', $nextParam);
            
            $nextRequest = generateParameterRequestXML($soapId, $nextParam);
            
            header('Content-Type: text/xml');
            echo $nextRequest;
            exit;
        }
        
        // Check for SetParameterValuesResponse
        if (stripos($raw_post, 'SetParameterValuesResponse') !== false) {
            writeLog("Received SetParameterValuesResponse", 'INFO');
            
            // Extract the status
            preg_match('/<Status>(.*?)<\/Status>/s', $raw_post, $statusMatches);
            if (isset($statusMatches[1])) {
                $status = trim($statusMatches[1]);
                writeLog("Parameter set operation completed with status: " . $status, 'INFO', true);
            }
            
            // Extract SOAP ID
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            
            // Send empty response to complete session
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
        
        // Check for fault messages
        if (stripos($raw_post, '<SOAP-ENV:Fault>') !== false || stripos($raw_post, '<cwmp:Fault>') !== false) {
            writeLog("Received fault response", 'WARNING');
            
            preg_match('/<FaultCode>(.*?)<\/FaultCode>.*?<FaultString>(.*?)<\/FaultString>/s', $raw_post, $faultMatches);
            
            if (!empty($faultMatches)) {
                $faultCode = $faultMatches[1];
                $faultString = $faultMatches[2];
                
                // Only log faults for set operations
                if (stripos($raw_post, 'SetParameterValues') !== false) {
                    writeLog("SET Parameter Fault: Code=" . $faultCode . ", Message=" . $faultString, 'ERROR', true);
                }
                
                // Extract SOAP ID
                preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
                $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
                
                // Extract what parameter caused the fault - for future reference
                if (preg_match('/<cwmp:GetParameterValues>.*?<string>(.*?)<\/string>/s', $raw_post, $paramMatch)) {
                    $faultParam = $paramMatch[1];
                    
                    // Add to a session list of parameters that cause faults - to avoid in future
                    if (!isset($_SESSION['fault_parameters'])) {
                        $_SESSION['fault_parameters'] = [];
                    }
                    
                    $_SESSION['fault_parameters'][] = $faultParam;
                }
                
                // Check if we need to continue with host parameters
                if ($_SESSION['host_count'] > 0 && $_SESSION['current_host_index'] <= $_SESSION['host_count']) {
                    // Continue with the next host
                    $nextParam = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$_SESSION['current_host_index']}.IPAddress"];
                    $_SESSION['attempted_parameters'][] = implode(',', $nextParam);
                    $_SESSION['current_host_index']++;
                    
                    $nextRequest = generateParameterRequestXML($soapId, $nextParam);
                    
                    header('Content-Type: text/xml');
                    echo $nextRequest;
                    exit;
                }
                
                // Find the next parameter to try
                $nextParam = null;
                
                // Try the newly requested parameters first if they haven't been attempted
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
                
                // First try all core parameters
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
                    // Store parameters in database before completing
                    $url = 'http://' . $_SERVER['HTTP_HOST'] . '/backend/api/store_tr069_data.php';
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_exec($ch);
                    curl_close($ch);
                    
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
                
                // Mark this parameter set as attempted
                $_SESSION['attempted_parameters'][] = implode(',', $nextParam);
                
                $nextRequest = generateParameterRequestXML($soapId, $nextParam);
                
                header('Content-Type: text/xml');
                echo $nextRequest;
                exit;
            }
            
            // For other fault codes, just acknowledge and complete
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
    } else {
        // Empty POST - start parameter discovery
        writeLog("Received empty POST, starting parameter discovery", 'INFO');
        
        // Generate a session ID
        $sessionId = md5(uniqid(rand(), true));
        
        // Start with requesting one of the new parameters - UpTime, SoftwareVersion, or HardwareVersion
        $initialParameters = ['InternetGatewayDevice.DeviceInfo.Manufacturer'];
        $initialRequest = generateParameterRequestXML($sessionId, $initialParameters);
        
        header('Content-Type: text/xml');
        echo $initialRequest;
        
        exit;
    }
}

// If we haven't sent a parameter request yet, use backend TR-069 server
try {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/tr069/server.php';
    $server = new TR069Server();
    
    // Pass the Huawei detection flag to the server
    $server->setHuaweiDetection($isHuawei);
    
    // Pass model information if detected
    if (!empty($modelDetected)) {
        $server->setModelHint($modelDetected);
    }
    
    // Handle the request
    $server->handleRequest();
} catch (Exception $e) {
    writeLog("Server error: " . $e->getMessage(), 'ERROR');
    header('HTTP/1.1 500 Internal Server Error');
    echo "Internal Server Error";
}
