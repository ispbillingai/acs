<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Tracking variables
$GLOBALS['session_id'] = 'session-' . substr(md5(time()), 0, 8);
$GLOBALS['current_task'] = null;

// Define log file paths
$GLOBALS['device_log'] = __DIR__ . '/device.log';
$GLOBALS['retrieve_log'] = __DIR__ . '/retrieve.log';

// Ensure the log files exist
if (!file_exists($GLOBALS['device_log'])) {
    touch($GLOBALS['device_log']);
    chmod($GLOBALS['device_log'], 0666);
}

if (!file_exists($GLOBALS['retrieve_log'])) {
    touch($GLOBALS['retrieve_log']);
    chmod($GLOBALS['retrieve_log'], 0666);
}

// Log to both Apache error log and device.log
function tr069_log($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[TR-069][$level][{$GLOBALS['session_id']}] $message";
    
    error_log($logMessage, 0);
    
    if (isset($GLOBALS['device_log']) && is_writable($GLOBALS['device_log'])) {
        file_put_contents($GLOBALS['device_log'], "[$timestamp] $logMessage\n", FILE_APPEND);
    }
    
    if (isset($GLOBALS['retrieve_log']) && is_writable($GLOBALS['retrieve_log'])) {
        file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] $logMessage\n", FILE_APPEND);
    }
}

// Create pending tasks for core info and additional parameter groups
function createPendingInfoTask($deviceId, $db) {
    try {
        tr069_log("TASK CREATION: Starting task creation for device ID: $deviceId", "INFO");
        
        // Check for existing in-progress or pending tasks
        $checkStmt = $db->prepare("
            SELECT id, task_type FROM device_tasks 
            WHERE device_id = :device_id AND status IN ('in_progress', 'pending') 
            LIMIT 1
        ");
        $checkStmt->execute([':device_id' => $deviceId]);
        $existingTask = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingTask) {
            tr069_log("TASK CREATION: Found existing task ID: {$existingTask['id']} ({$existingTask['task_type']}), skipping new task creation", "INFO");
            return false;
        }
        
        // Define parameter groups
        $parameterGroups = [
            [
                'task_type' => 'info',
                'group' => 'Core',
                'parameters' => [
                    'InternetGatewayDevice.DeviceInfo.HardwareVersion',
                    'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
                    'InternetGatewayDevice.DeviceInfo.UpTime',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                    'InternetGatewayDevice.',
                    'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries'
                ]
            ],
            [
                'task_type' => 'info_group',
                'group' => 'WANIPConnection',
                'parameters' => [
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DNSServers',
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.SubnetMask',
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DefaultGateway'
                ]
            ],
            [
                'task_type' => 'info_group',
                'group' => 'WANPPPConnection',
                'parameters' => [
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.DNSServers',
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.DefaultGateway'
                ]
            ],
            [
                'task_type' => 'info_group',
                'group' => 'GPON',
                'parameters' => [
                    'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.TXPower',
                    'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.RXPower'
                ]
            ]
        ];
        
        $createdTasks = [];
        
        foreach ($parameterGroups as $group) {
            $taskData = json_encode([
                'group' => $group['group'],
                'parameters' => $group['parameters']
            ]);
            
            $insertStmt = $db->prepare("
                INSERT INTO device_tasks 
                    (device_id, task_type, task_data, status, message, created_at, updated_at) 
                VALUES 
                    (:device_id, :task_type, :task_data, 'pending', 'Auto-created for {$group['group']} parameters', NOW(), NOW())
            ");
            
            $insertResult = $insertStmt->execute([
                ':device_id' => $deviceId,
                ':task_type' => $group['task_type'],
                ':task_data' => $taskData
            ]);
            
            if ($insertResult) {
                $taskId = $db->lastInsertId();
                $createdTasks[] = $taskId;
                tr069_log("TASK CREATION: Successfully created {$group['task_type']} task with ID: $taskId for group: {$group['group']}", "INFO");
                
                $verifyStmt = $db->prepare("SELECT * FROM device_tasks WHERE id = :id");
                $verifyStmt->execute([':id' => $taskId]);
                if ($verifyStmt->fetch(PDO::FETCH_ASSOC)) {
                    tr069_log("TASK CREATION: Verified task exists in database with ID: $taskId", "INFO");
                } else {
                    tr069_log("TASK CREATION ERROR: Task not found in database after creation for ID: $taskId", "ERROR");
                }
            } else {
                tr069_log("TASK CREATION ERROR: Failed to create {$group['task_type']} task for group: {$group['group']}. Database error: " . print_r($insertStmt->errorInfo(), true), "ERROR");
            }
        }
        
        return !empty($createdTasks);
    } catch (PDOException $e) {
        tr069_log("TASK CREATION ERROR: Exception: " . $e->getMessage(), "ERROR");
        return false;
    }
}

// Save parameter values to database
function saveParameterValues($raw, $serialNumber, $db) {
    $deviceMap = [
        'ExternalIPAddress' => 'ip_address',
        'SoftwareVersion' => 'software_version',
        'HardwareVersion' => 'hardware_version',
        'UpTime' => 'uptime',
        'SSID' => 'ssid',
        'HostNumberOfEntries' => 'connected_devices',
        'X_GponInterafceConfig.TXPower' => 'tx_power',
        'X_GponInterafceConfig.RXPower' => 'rx_power',
        'DNSServers' => 'dns_servers',
        'SubnetMask' => 'subnet_mask',
        'DefaultGateway' => 'default_gateway'
    ];
    
    $hostMap = [
        'IPAddress' => 'ip_address',
        'HostName' => 'hostname',
        'MACAddress' => 'mac_address',
        'Active' => 'is_active'
    ];
    
    $devicePairs = [];
    $hosts = [];
    preg_match_all('/<ParameterValueStruct>.*?<Name>(.*?)<\/Name>.*?<Value[^>]*>(.*?)<\/Value>/s', $raw, $matches, PREG_SET_ORDER);
    
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Extracted parameters: " . print_r($matches, true) . "\n", FILE_APPEND);
    
    foreach ($matches as $param) {
        $name = $param[1];
        $value = $param[2];
        
        if (empty($value)) continue;
        
        foreach ($deviceMap as $needle => $column) {
            if (strpos($name, $needle) !== false) {
                $devicePairs[$column] = $value;
                file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Mapped $name to devices.$column = $value\n", FILE_APPEND);
            }
        }
        
        if (preg_match('/Hosts\.Host\.(\d+)\.(\w+)/', $name, $hostMatches)) {
            $hostIndex = $hostMatches[1];
            $hostProperty = $hostMatches[2];
            
            if (!isset($hosts[$hostIndex])) {
                $hosts[$hostIndex] = [
                    'ip_address' => '',
                    'hostname' => '',
                    'mac_address' => '',
                    'is_active' => 0
                ];
            }
            
            foreach ($hostMap as $needle => $column) {
                if ($hostProperty === $needle) {
                    $hosts[$hostIndex][$column] = ($needle === 'Active') ? ($value === '1' || strtolower($value) === 'true' ? 1 : 0) : $value;
                    file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Mapped $name to connected_clients.$column = " . $hosts[$hostIndex][$column] . "\n", FILE_APPEND);
                }
            }
        }
    }
    
    if (!empty($devicePairs)) {
        try {
            $setStatements = [];
            $params = [':serial' => $serialNumber];
            
            foreach ($devicePairs as $column => $value) {
                $setStatements[] = "$column = :$column";
                $params[":$column"] = $value;
            }
            
            $sql = "UPDATE devices SET " . implode(', ', $setStatements) . " WHERE serial_number = :serial";
            file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] SQL (devices): $sql\n", FILE_APPEND);
            file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Params (devices): " . print_r($params, true) . "\n", FILE_APPEND);
            
            $stmt = $db->prepare($sql);
            $result = $stmt->execute($params);
            
            file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Update result (devices): " . ($result ? "success" : "failed") . "\n", FILE_APPEND);
            tr069_log("Device $serialNumber updated with " . implode(', ', array_keys($devicePairs)), "INFO");
        } catch (Exception $e) {
            file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Database error (devices): " . $e->getMessage() . "\n", FILE_APPEND);
            tr069_log("Error updating device data: " . $e->getMessage(), "ERROR");
        }
    }
    
    if (!empty($hosts)) {
        try {
            $stmt = $db->prepare("SELECT id, connected_devices FROM devices WHERE serial_number = :serial");
            $stmt->execute([':serial' => $serialNumber]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$device) {
                file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Device not found for serial: $serialNumber\n", FILE_APPEND);
                tr069_log("Device not found for serial: $serialNumber", "ERROR");
                return;
            }
            
            $deviceId = $device['id'];
            $connectedDevices = (int) ($device['connected_devices'] ?? 0);
            
            if ($connectedDevices < 0) {
                file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Invalid connected_devices count: $connectedDevices for device $serialNumber\n", FILE_APPEND);
                tr069_log("Invalid connected_devices count: $connectedDevices for device $serialNumber", "ERROR");
                return;
            }
            
            $countStmt = $db->prepare("SELECT COUNT(*) as count FROM connected_clients WHERE device_id = :device_id");
            $countStmt->execute([':device_id' => $deviceId]);
            $currentHostCount = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($connectedDevices < $currentHostCount || $connectedDevices === 0) {
                $deleteStmt = $db->prepare("DELETE FROM connected_clients WHERE device_id = :device_id");
                $deleteResult = $deleteStmt->execute([':device_id' => $deviceId]);
                file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Deleted $currentHostCount hosts for device_id $deviceId (new connected_devices: $connectedDevices)\n", FILE_APPEND);
                tr069_log("Deleted $currentHostCount hosts for device $serialNumber (new connected_devices: $connectedDevices)", "INFO");
            }
            
            if ($connectedDevices === 0) {
                file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] No hosts to process (connected_devices: 0)\n", FILE_APPEND);
                tr069_log("No hosts to process for device $serialNumber (connected_devices: 0)", "INFO");
                return;
            }
            
            $hostSuccess = 0;
            $hostCount = min(count($hosts), $connectedDevices);
            
            for ($i = 1; $i <= $hostCount; $i++) {
                if (!isset($hosts[$i])) {
                    file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Skipping host $i: no data available\n", FILE_APPEND);
                    continue;
                }
                
                $host = $hosts[$i];
                if (empty($host['ip_address']) && empty($host['mac_address'])) {
                    file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Skipping host $i: no IP or MAC address\n", FILE_APPEND);
                    continue;
                }
                
                $checkStmt = $db->prepare("SELECT id FROM connected_clients WHERE device_id = :device_id AND (mac_address = :mac_address OR ip_address = :ip_address)");
                $checkStmt->execute([
                    ':device_id' => $deviceId,
                    ':mac_address' => $host['mac_address'] ?: '',
                    ':ip_address' => $host['ip_address'] ?: ''
                ]);
                $existingHost = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingHost) {
                    $updateStmt = $db->prepare("UPDATE connected_clients SET 
                        ip_address = :ip_address,
                        hostname = :hostname,
                        mac_address = :mac_address,
                        is_active = :is_active
                        WHERE id = :id");
                    $updateResult = $updateStmt->execute([
                        ':ip_address' => $host['ip_address'],
                        ':hostname' => $host['hostname'],
                        ':mac_address' => $host['mac_address'],
                        ':is_active' => $host['is_active'],
                        ':id' => $existingHost['id']
                    ]);
                    
                    if ($updateResult) {
                        $hostSuccess++;
                        file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Updated host $i for device_id $deviceId: ip_address={$host['ip_address']}, mac_address={$host['mac_address']}\n", FILE_APPEND);
                    } else {
                        file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Failed to update host $i for device_id $deviceId\n", FILE_APPEND);
                    }
                } else {
                    $insertStmt = $db->prepare("INSERT INTO connected_clients (
                        device_id, ip_address, hostname, mac_address, is_active
                    ) VALUES (
                        :device_id, :ip_address, :hostname, :mac_address, :is_active
                    )");
                    $insertResult = $insertStmt->execute([
                        ':device_id' => $deviceId,
                        ':ip_address' => $host['ip_address'],
                        ':hostname' => $host['hostname'],
                        ':mac_address' => $host['mac_address'],
                        ':is_active' => $host['is_active']
                    ]);
                    
                    if ($insertResult) {
                        $hostSuccess++;
                        file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Inserted host $i for device_id $deviceId: ip_address={$host['ip_address']}, mac_address={$host['mac_address']}\n", FILE_APPEND);
                    } else {
                        file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Failed to insert host $i for device_id $deviceId\n", FILE_APPEND);
                    }
                }
            }
            
            file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Updated $hostSuccess hosts for device $serialNumber\n", FILE_APPEND);
            tr069_log("Updated $hostSuccess hosts for device $serialNumber", "INFO");
        } catch (Exception $e) {
            file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Database error (connected_clients): " . $e->getMessage() . "\n", FILE_APPEND);
            tr069_log("Error updating connected_clients for device $serialNumber: " . $e->getMessage(), "ERROR");
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
    $database = new Database();
    $db = $database->getConnection();
    
    $auth = new AuthenticationHandler();
    if (!$auth->authenticate()) {
        tr069_log("Authentication failed", "ERROR");
        header('HTTP/1.1 401 Unauthorized');
        header('WWW-Authenticate: Basic realm="TR-069 ACS"');
        exit;
    }
    
    $raw_post = file_get_contents('php://input');
    tr069_log("Received request: " . substr($raw_post, 0, 200) . "...", "DEBUG");
    
    $responseGenerator = new InformResponseGenerator();
    $taskHandler = new TaskHandler();
    
    if (stripos($raw_post, '<cwmp:Inform>') !== false) {
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        preg_match('/<SerialNumber>(.*?)<\/SerialNumber>/s', $raw_post, $serialMatches);
        $serialNumber = isset($serialMatches[1]) ? trim($serialMatches[1]) : null;
        
        if ($serialNumber) {
            tr069_log("Device inform received - Serial: $serialNumber", "INFO");
            
            try {
                $stmt = $db->prepare("
                    INSERT INTO devices 
                        (serial_number, status, last_contact) 
                    VALUES 
                        (:serial, 'online', NOW()) 
                    ON DUPLICATE KEY UPDATE 
                        status = 'online', last_contact = NOW()
                ");
                $stmt->execute([':serial' => $serialNumber]);
                tr069_log("Updated device status to online - Serial: $serialNumber", "INFO");
            } catch (PDOException $e) {
                tr069_log("Database error updating device status: " . $e->getMessage(), "ERROR");
            }
            
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
            
            try {
                $idStmt = $db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
                $idStmt->execute([':serial' => $serialNumber]);
                $deviceId = $idStmt->fetchColumn();
                
                if ($deviceId) {
                    tr069_log("Found device ID: $deviceId for serial: $serialNumber", "INFO");
                    createPendingInfoTask($deviceId, $db);
                } else {
                    tr069_log("Device ID not found for serial: $serialNumber", "ERROR");
                }
            } catch (PDOException $e) {
                tr069_log("Database error finding device ID: " . $e->getMessage(), "ERROR");
            }
            
            $pendingTasks = $taskHandler->getPendingTasks($serialNumber);
            
            if (!empty($pendingTasks)) {
                $GLOBALS['current_task'] = $pendingTasks[0];
                tr069_log("Found pending task: " . $pendingTasks[0]['task_type'] . " - ID: " . $pendingTasks[0]['id'], "INFO");
                
                session_start();
                $_SESSION['current_task'] = $pendingTasks[0];
                $_SESSION['device_serial'] = $serialNumber;
                session_write_close();
            } else {
                tr069_log("No pending tasks for device ID: " . ($deviceId ?: 'unknown'), "INFO");
                
                try {
                    $deviceStmt = $db->prepare("SELECT id FROM devices WHERE serial_number = :serial_number");
                    $deviceStmt->execute([':serial_number' => $serialNumber]);
                    $deviceId = $deviceStmt->fetchColumn();
                    
                    if ($deviceId) {
                        $inProgressStmt = $db->prepare("
                            SELECT * FROM device_tasks 
                            WHERE device_id = :device_id AND status = 'in_progress' 
                            ORDER BY updated_at DESC LIMIT 1
                        ");
                        $inProgressStmt->execute([':device_id' => $deviceId]);
                        $inProgressTask = $inProgressStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($inProgressTask) {
                            tr069_log("Found in-progress task: " . $inProgressTask['task_type'] . " - ID: " . $inProgressTask['id'], "INFO");
                            $taskTime = strtotime($inProgressTask['updated_at']);
                            $currentTime = time();
                            
                            if (($currentTime - $taskTime) > 300) {
                                $taskHandler->updateTaskStatus($inProgressTask['id'], 'completed', 'Auto-completed after timeout');
                                tr069_log("Auto-completed task ID: " . $inProgressTask['id'], "INFO");
                            } else {
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
        
        $response = $responseGenerator->createResponse($soapId);
        tr069_log("Sending InformResponse", "DEBUG");
        
        header('Content-Type: text/xml');
        echo $response;
        exit;
    }
    
    if (stripos($raw_post, '<cwmp:GetParameterValuesResponse>') !== false || 
        (stripos($raw_post, 'ParameterList') !== false && 
         stripos($raw_post, 'ParameterValueStruct') !== false)) {
        
        tr069_log("Received GetParameterValuesResponse", "INFO");
        
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        session_start();
        $serialNumber = isset($_SESSION['device_serial']) ? $_SESSION['device_serial'] : null;
        $current_task = isset($_SESSION['current_task']) ? $_SESSION['current_task'] : null;
        session_write_close();
        
        if ($serialNumber && $current_task) {
            saveParameterValues($raw_post, $serialNumber, $db);
            
            $hostCount = 0;
            preg_match('/<Name>InternetGatewayDevice\.LANDevice\.1\.Hosts\.HostNumberOfEntries<\/Name>.*?<Value[^>]*>(.*?)<\/Value>/s', $raw_post, $hostMatches);
            if (isset($hostMatches[1]) && is_numeric($hostMatches[1])) {
                $hostCount = (int)$hostMatches[1];
                tr069_log("Found HostNumberOfEntries: $hostCount", "INFO");
                
                if ($hostCount > 0 && $current_task['task_type'] === 'info') {
                    $taskData = json_decode($current_task['task_data'], true) ?: [];
                    if (!isset($taskData['host_count'])) {
                        $deviceId = $taskHandler->getDeviceIdFromSerialNumber($serialNumber);
                        if ($deviceId) {
                            $followUpTaskId = $taskHandler->createFollowUpInfoTask($deviceId, $hostCount);
                            tr069_log("Created follow-up info task: $followUpTaskId for hosts details", "INFO");
                        }
                    }
                }
            }
            
            $taskHandler->updateTaskStatus($current_task['id'], 'completed', 'Successfully retrieved device information');
            tr069_log("Task completed: " . $current_task['id'] . " (Type: {$current_task['task_type']})", "INFO");
            
            session_start();
            unset($_SESSION['current_task']);
            session_write_close();
        } else {
            tr069_log("No device serial or task found for GetParameterValuesResponse", "WARNING");
        }
        
        header('Content-Type: text/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body></soapenv:Body>
</soapenv:Envelope>';
        exit;
    }
    
    if (stripos($raw_post, 'SetParameterValuesResponse') !== false || 
        stripos($raw_post, '<Status>') !== false) {
        
        tr069_log("Received SetParameterValuesResponse", "INFO");
        
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        preg_match('/<Status>(.*?)<\/Status>/s', $raw_post, $statusMatches);
        $status = isset($statusMatches[1]) ? trim($statusMatches[1]) : '0';
        
        $current_task = null;
        if ($GLOBALS['current_task']) {
            $current_task = $GLOBALS['current_task'];
        } else {
            session_start();
            if (isset($_SESSION['current_task'])) {
                $current_task = $_SESSION['current_task'];
                unset($_SESSION['current_task']);
            } elseif (isset($_SESSION['in_progress_task'])) {
                $current_task = $_SESSION['in_progress_task'];
                unset($_SESSION['in_progress_task']);
            }
            $serialNumber = isset($_SESSION['device_serial']) ? $_SESSION['device_serial'] : null;
            session_write_close();
        }
        
        if ($current_task) {
            $taskStatus = ($status === '0') ? 'completed' : 'failed';
            $taskMessage = ($status === '0') ? 'Successfully applied ' . $current_task['task_type'] . ' configuration' : 'Device returned error status: ' . $status;
            $taskHandler->updateTaskStatus($current_task['id'], $taskStatus, $taskMessage);
            tr069_log("Task $taskStatus: " . $current_task['id'] . " - Status: $status", ($status === '0' ? "INFO" : "ERROR"));
            
            $GLOBALS['current_task'] = null;
        } else {
            if ($serialNumber) {
                try {
                    $deviceStmt = $db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
                    $deviceStmt->execute([':serial' => $serialNumber]);
                    $deviceId = $deviceStmt->fetchColumn();
                    
                    if ($deviceId) {
                        $taskStmt = $db->prepare("
                            SELECT * FROM device_tasks 
                            WHERE device_id = :device_id AND status = 'in_progress' 
                            ORDER BY updated_at DESC LIMIT 1
                        ");
                        $taskStmt->execute([':device_id' => $deviceId]);
                        $inProgressTask = $taskStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($inProgressTask) {
                            $taskStatus = ($status === '0') ? 'completed' : 'failed';
                            $taskMessage = ($status === '0') ? 'Successfully applied ' . $inProgressTask['task_type'] . ' configuration' : 'Device returned error status: ' . $status;
                            $taskHandler->updateTaskStatus($inProgressTask['id'], $taskStatus, $taskMessage);
                            tr069_log("Processed in-progress task: " . $inProgressTask['id'] . " - $taskStatus", ($status === '0' ? "INFO" : "ERROR"));
                        } else {
                            tr069_log("No in-progress tasks found for device ID: $deviceId", "WARNING");
                        }
                    }
                } catch (PDOException $e) {
                    tr069_log("Database error finding in-progress tasks: " . $e->getMessage(), "ERROR");
                }
            } else {
                tr069_log("No current task or serial number found for SetParameterValuesResponse", "WARNING");
            }
        }
        
        header('Content-Type: text/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body></soapenv:Body>
</soapenv:Envelope>';
        exit;
    }
    
    if (empty(trim($raw_post)) || $raw_post === "\r\n") {
        $soapId = '1';
        if (!empty($raw_post)) {
            preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        }
        
        session_start();
        $current_task = null;
        $serialNumber = null;
        
        if ($GLOBALS['current_task']) {
            $current_task = $GLOBALS['current_task'];
        } elseif (isset($_SESSION['current_task'])) {
            $current_task = $_SESSION['current_task'];
            $serialNumber = isset($_SESSION['device_serial']) ? $_SESSION['device_serial'] : null;
        } elseif (isset($_SESSION['in_progress_task'])) {
            $inProgressTask = $_SESSION['in_progress_task'];
            $taskHandler->updateTaskStatus($inProgressTask['id'], 'completed', 'Auto-completed during session');
            tr069_log("Auto-completed in-progress task: " . $inProgressTask['id'], "INFO");
            unset($_SESSION['in_progress_task']);
            session_write_close();
            header('Content-Type: text/xml');
            echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body></soapenv:Body>
</soapenv:Envelope>';
            exit;
        }
        
        if ($current_task && $serialNumber) {
            tr069_log("Processing task from session: {$current_task['task_type']} - ID: {$current_task['id']}", "INFO");
            
            $taskData = json_decode($current_task['task_data'], true) ?: [];
            $group = $taskData['group'] ?? 'Unknown';
            $parameters = $taskData['parameters'] ?? [];
            
            if ($current_task['task_type'] === 'info' || $current_task['task_type'] === 'info_group') {
                if (!empty($parameters)) {
                    $nameXml = '';
                    $paramCount = count($parameters);
                    
                    foreach ($parameters as $param) {
                        $nameXml .= "        <string>" . htmlspecialchars($param) . "</string>\n";
                        tr069_log("Requesting parameter: $param (Group: $group)", "DEBUG");
                    }
                    
                    $getValuesRequest = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0" xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
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
                    
                    $taskHandler->updateTaskStatus($current_task['id'], 'in_progress', "Sent GetParameterValues request (Group: $group)");
                    tr069_log("Task marked as in_progress: {$current_task['id']} (Group: $group)", "INFO");
                    
                    session_write_close();
                    exit;
                } else {
                    tr069_log("No parameters defined for task {$current_task['id']}", "ERROR");
                    $taskHandler->updateTaskStatus($current_task['id'], 'failed', 'No parameters defined');
                    unset($_SESSION['current_task']);
                    session_write_close();
                }
            } elseif ($current_task['task_type'] === 'wifi') {
                $parameterRequests = $taskHandler->generateParameterValues($current_task['task_type'], $current_task['task_data']);
                
                if ($parameterRequests) {
                    $requests = is_array($parameterRequests) && isset($parameterRequests[0]) && is_array($parameterRequests[0]) ? $parameterRequests : [$parameterRequests];
                    
                    if ($requests[0]['method'] === 'SetParameterValues') {
                        $paramXml = '';
                        $paramCount = count($requests[0]['parameters']);
                        
                        foreach ($requests[0]['parameters'] as $param) {
                            $paramXml .= "        <ParameterValueStruct>\n";
                            $paramXml .= "          <Name>" . htmlspecialchars($param['name']) . "</Name>\n";
                            $paramXml .= "          <Value xsi:type=\"" . $param['type'] . "\">" . htmlspecialchars($param['value']) . "</Value>\n";
                            $paramXml .= "        </ParameterValueStruct>\n";
                            tr069_log("Setting parameter: {$param['name']} = {$param['value']}", "DEBUG");
                        }
                        
                        $setParamRequest = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0" xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
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
                        tr069_log("Task marked as in_progress: {$current_task['id']}", "INFO");
                        session_write_close();
                        exit;
                    }
                } else {
                    tr069_log("Failed to generate parameters for task {$current_task['id']}", "ERROR");
                    $taskHandler->updateTaskStatus($current_task['id'], 'failed', 'Failed to generate parameters');
                    unset($_SESSION['current_task']);
                    session_write_close();
                }
            } elseif ($current_task['task_type'] === 'reboot') {
                $parameterRequests = $taskHandler->generateParameterValues($current_task['task_type'], $current_task['task_data']);
                
                if ($parameterRequests && $parameterRequests[0]['method'] === 'Reboot') {
                    $rebootRequest = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:Reboot>
      <CommandKey>' . $parameterRequests[0]['commandKey'] . '</CommandKey>
    </cwmp:Reboot>
  </soapenv:Body>
</soapenv:Envelope>';

                    tr069_log("Sending reboot command with key: " . $parameterRequests[0]['commandKey'], "INFO");
                    
                    header('Content-Type: text/xml');
                    header('Connection: close');
                    header('Content-Length: ' . strlen($rebootRequest));
                    echo $rebootRequest;
                    flush();
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    
                    $taskHandler->updateTaskStatus($current_task['id'], 'in_progress', 'Sent reboot command to device');
                    tr069_log("Task marked as in_progress: {$current_task['id']}", "INFO");
                    session_write_close();
                    exit;
                }
            } elseif ($current_task['task_type'] === 'huawei_reboot') {
                $parameterRequests = $taskHandler->generateParameterValues($current_task['task_type'], $current_task['task_data']);
                
                if ($parameterRequests && $parameterRequests[0]['method'] === 'X_HW_DelayReboot') {
                    $rebootRequest = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:X_HW_DelayReboot>
      <CommandKey>' . $parameterRequests[0]['commandKey'] . '</CommandKey>
      <DelaySeconds>' . $parameterRequests[0]['delay'] . '</DelaySeconds>
    </cwmp:X_HW_DelayReboot>
  </soapenv:Body>
</soapenv:Envelope>';

                    tr069_log("Sending Huawei vendor reboot command with key: " . $parameterRequests[0]['commandKey'], "INFO");
                    
                    header('Content-Type: text/xml');
                    header('Connection: close');
                    header('Content-Length: ' . strlen($rebootRequest));
                    echo $rebootRequest;
                    flush();
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    
                    $taskHandler->updateTaskStatus($current_task['id'], 'in_progress', 'Sent vendor reboot command to device');
                    tr069_log("Task marked as in_progress: {$current_task['id']}", "INFO");
                    session_write_close();
                    exit;
                }
            }
        }
        
        session_write_close();
        header('Content-Type: text/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body></soapenv:Body>
</soapenv:Envelope>';
        exit;
    }
    
    header('Content-Type: text/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">1</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body></soapenv:Body>
</soapenv:Envelope>';
    
} catch (Exception $e) {
    tr069_log("Unhandled exception: " . $e->getMessage(), "ERROR");
    header('HTTP/1.1 500 Internal Server Error');
    echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
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
?>