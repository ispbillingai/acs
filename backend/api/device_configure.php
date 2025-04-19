<?php
// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set up logging to device.log
function writeToDeviceLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logFile = $_SERVER['DOCUMENT_ROOT'] . '/device.log';
    
    // Make sure the log is writable
    if (!file_exists($logFile)) {
        touch($logFile);
        chmod($logFile, 0666);
    }
    
    // Log to file
    file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    
    // Also log to Apache error log as backup
    error_log($message, 0);
}

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate required parameters
if (!isset($_POST['action'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing required action parameter']);
    exit;
}

$action = $_POST['action'];

writeToDeviceLog("[API Request] Action: {$action}");

try {
    // Include database connection
    require_once __DIR__ . '/../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    // Process based on action type
    switch ($action) {
        case 'check_task_status':
            // Check status of a specific task
            if (!isset($_POST['task_id'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Task ID is required']);
                exit;
            }
            
            $taskId = $_POST['task_id'];
            writeToDeviceLog("[Task Status Check] Task ID: {$taskId}");
            
            $stmt = $db->prepare("SELECT * FROM device_tasks WHERE id = :id");
            $stmt->execute([':id' => $taskId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$task) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Task not found']);
                exit;
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'task_id' => $task['id'],
                'status' => $task['status'],
                'message' => $task['message'],
                'created_at' => $task['created_at'],
                'updated_at' => $task['updated_at']
            ]);
            break;

            case 'pppoe':
                // Validate parameters
                if (!isset($_POST['device_id']) || !isset($_POST['pppoe_username']) || !isset($_POST['pppoe_password'])) {
                    writeToDeviceLog("[Error] Missing required parameters for PPPoE configuration");
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Device ID, PPPoE username, and password are required']);
                    exit;
                }
            
                $deviceId = $_POST['device_id'];
                $pppoe_username = trim($_POST['pppoe_username']);
                $pppoe_password = trim($_POST['pppoe_password']);
            
                if (empty($pppoe_username)) {
                    writeToDeviceLog("[Error] PPPoE username is empty");
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'PPPoE username is required']);
                    exit;
                }
            
                // Get device information
                $stmt = $db->prepare("SELECT * FROM devices WHERE id = :id");
                $stmt->execute([':id' => $deviceId]);
                $device = $stmt->fetch(PDO::FETCH_ASSOC);
            
                if (!$device) {
                    writeToDeviceLog("[Error] Device not found: {$deviceId}");
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Device not found']);
                    exit;
                }
            
                // Create task data
                $taskData = json_encode([
                    'pppoe_username' => $pppoe_username,
                    'pppoe_password' => $pppoe_password
                ]);
            
                writeToDeviceLog("[PPPoE Config] Creating task for Username: {$pppoe_username}, Password length: " . strlen($pppoe_password));
            
                // Cancel any existing pending PPPoE tasks
                $cancelStmt = $db->prepare("
                    UPDATE device_tasks 
                    SET status = 'canceled', message = 'Superseded by newer task', updated_at = NOW()
                    WHERE device_id = :device_id AND task_type = 'pppoe' AND status = 'pending'
                ");
                $cancelStmt->execute([':device_id' => $deviceId]);
                $canceledCount = $cancelStmt->rowCount();
            
                if ($canceledCount > 0) {
                    writeToDeviceLog("[PPPoE Config] Canceled {$canceledCount} older pending PPPoE tasks");
                }
            
                // Mark any stuck in-progress PPPoE tasks as failed
                $failStmt = $db->prepare("
                    UPDATE device_tasks 
                    SET status = 'failed', message = 'Task timed out', updated_at = NOW()
                    WHERE device_id = :device_id AND task_type = 'pppoe' AND status = 'in_progress' 
                    AND updated_at < NOW() - INTERVAL 5 MINUTE
                ");
                $failStmt->execute([':device_id' => $deviceId]);
                $failedCount = $failStmt->rowCount();
            
                if ($failedCount > 0) {
                    writeToDeviceLog("[PPPoE Config] Marked {$failedCount} stalled in-progress PPPoE tasks as failed");
                }
            
                // Insert task
                $stmt = $db->prepare("
                    INSERT INTO device_tasks (device_id, task_type, task_data, status, created_at)
                    VALUES (:device_id, 'pppoe', :task_data, 'pending', NOW())
                ");
            
                $stmt->execute([
                    ':device_id' => $deviceId,
                    ':task_data' => $taskData
                ]);
            
                $taskId = $db->lastInsertId();
                writeToDeviceLog("[Task Created] ID: {$taskId}, Type: pppoe");
            
                // Prepare connection request data for frontend display
                $ipAddress = $device['ip_address'] ?? '';
                $serialNumber = $device['serial_number'] ?? '';
            
                // Build connection request URLs
                if (!empty($ipAddress)) {
                    $ports = ['30005', '37215', '7547', '4567'];
                    $username = 'admin';
                    $password = 'admin';
            
                    $connectionUrl = "http://{$ipAddress}:30005/acs";
                    $command = "curl -v -u \"{$username}:{$password}\" -d \"<cwmp:ID>API_".$taskId."</cwmp:ID>\" \"{$connectionUrl}\"";
            
                    $altCommands = [];
                    foreach ($ports as $port) {
                        if ($port === '30005') continue; // Skip default
                        $altUrl = "http://{$ipAddress}:{$port}/acs";
                        $altCommands[] = "curl -v -u \"{$username}:{$password}\" -d \"<cwmp:ID>API_".$taskId."</cwmp:ID>\" \"{$altUrl}\"";
                    }
            
                    $connectionRequest = [
                        'url' => $connectionUrl,
                        'username' => $username,
                        'password' => $password,
                        'command' => $command,
                        'alternative_commands' => $altCommands
                    ];
            
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => 'PPPoE configuration task created successfully',
                        'task_id' => $taskId,
                        'connection_request' => $connectionRequest,
                        'tr069_session_id' => $GLOBALS['session_id'] ?? null
                    ]);
                } else {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => 'PPPoE configuration task created successfully',
                        'task_id' => $taskId
                    ]);
                }
                break;
        
        case 'wifi':
            // Validate parameters
            if (!isset($_POST['device_id']) || !isset($_POST['action'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
                exit;
            }
            
            $deviceId = $_POST['device_id'];
            $action = $_POST['action'];
            
            writeToDeviceLog("[Configuration Request] Device ID: {$deviceId}, Action: {$action}");
            
            if (!isset($_POST['ssid'])) {
                writeToDeviceLog("[Error] Missing SSID for WiFi configuration");
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'SSID is required']);
                exit;
            }
            
            $ssid = $_POST['ssid'];
            $password = $_POST['password'] ?? '';
            
            // Create task data
            $taskData = json_encode([
                'ssid' => $ssid,
                'password' => $password
            ]);
            
            writeToDeviceLog("[WiFi Config] Creating task for SSID: {$ssid}, Password length: " . strlen($password));
            
            // First, check for any existing pending WiFi tasks and mark them as canceled
            $cancelStmt = $db->prepare("
                UPDATE device_tasks 
                SET status = 'canceled', message = 'Superseded by newer task', updated_at = NOW()
                WHERE device_id = :device_id AND task_type = 'wifi' AND status = 'pending'
            ");
            $cancelStmt->execute([':device_id' => $deviceId]);
            $canceledCount = $cancelStmt->rowCount();
            
            if ($canceledCount > 0) {
                writeToDeviceLog("[WiFi Config] Canceled {$canceledCount} older pending WiFi tasks");
            }
            
            // Insert task
            $stmt = $db->prepare("
                INSERT INTO device_tasks (device_id, task_type, task_data, status, created_at)
                VALUES (:device_id, 'wifi', :task_data, 'pending', NOW())
            ");
            
            $stmt->execute([
                ':device_id' => $deviceId,
                ':task_data' => $taskData
            ]);
            
            $taskId = $db->lastInsertId();
            writeToDeviceLog("[Task Created] ID: {$taskId}, Type: wifi");
            
            // Prepare connection request data for frontend display
            $ipAddress = $device['ip_address'] ?? '';
            $serialNumber = $device['serial_number'] ?? '';
            
            // Build connection request URLs
            if (!empty($ipAddress)) {
                $ports = ['30005', '37215', '7547', '4567'];
                $username = 'admin';
                $password = 'admin';
                
                $connectionUrl = "http://{$ipAddress}:30005/acs";
                $command = "curl -v -u \"{$username}:{$password}\" -d \"<cwmp:ID>API_".$taskId."</cwmp:ID>\" \"{$connectionUrl}\"";
                
                $altCommands = [];
                foreach ($ports as $port) {
                    if ($port === '30005') continue; // Skip default
                    $altUrl = "http://{$ipAddress}:{$port}/acs";
                    $altCommands[] = "curl -v -u \"{$username}:{$password}\" -d \"<cwmp:ID>API_".$taskId."</cwmp:ID>\" \"{$altUrl}\"";
                }
                
                $connectionRequest = [
                    'url' => $connectionUrl,
                    'username' => $username,
                    'password' => $password,
                    'command' => $command,
                    'alternative_commands' => $altCommands
                ];
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'WiFi configuration task created successfully',
                    'task_id' => $taskId,
                    'connection_request' => $connectionRequest,
                    'tr069_session_id' => $GLOBALS['session_id'] ?? null
                ]);
            } else {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'WiFi configuration task created successfully',
                    'task_id' => $taskId
                ]);
            }
            break;
            
        case 'wan':
            // Validate device ID
            if (!isset($_POST['device_id'])) {
                writeToDeviceLog("[Error] Missing device ID for WAN configuration");
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Device ID is required']);
                exit;
            }
            
            $deviceId = $_POST['device_id'];
            
            // Get device information
            $stmt = $db->prepare("SELECT * FROM devices WHERE id = :id");
            $stmt->execute([':id' => $deviceId]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$device) {
                writeToDeviceLog("[Error] Device not found: {$deviceId}");
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Device not found']);
                exit;
            }
            
            // Get connection type (DHCP, PPPoE, Static)
            $connectionType = $_POST['connection_type'] ?? 'DHCP';
            writeToDeviceLog("[WAN Config] Connection type: {$connectionType}");
            
            // Prepare task data based on connection type
            $taskData = [
                'connection_type' => $connectionType
            ];
            
            // Add connection-type specific parameters
            if ($connectionType === 'Static') {
                if (!isset($_POST['ip_address']) || !isset($_POST['subnet_mask'])) {
                    writeToDeviceLog("[Error] Missing IP address or subnet mask for Static IP configuration");
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'IP address and subnet mask are required for Static IP configuration']);
                    exit;
                }
                
                $taskData['ip_address'] = $_POST['ip_address'];
                $taskData['subnet_mask'] = $_POST['subnet_mask'];
                $taskData['gateway'] = $_POST['gateway'] ?? '';
                $taskData['dns_server1'] = $_POST['dns_server1'] ?? '';
                $taskData['dns_server2'] = $_POST['dns_server2'] ?? '';
                
                writeToDeviceLog("[WAN Config] Creating Static IP task with IP: {$taskData['ip_address']}, Subnet: {$taskData['subnet_mask']}");
            } 
            elseif ($connectionType === 'PPPoE') {
                if (!isset($_POST['pppoe_username'])) {
                    writeToDeviceLog("[Error] Missing username for PPPoE configuration");
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Username is required for PPPoE configuration']);
                    exit;
                }
                
                $taskData['pppoe_username'] = $_POST['pppoe_username'];
                $taskData['pppoe_password'] = $_POST['pppoe_password'] ?? '';
                
                writeToDeviceLog("[WAN Config] Creating PPPoE task with Username: {$taskData['pppoe_username']}");
            }
            else {
                // DHCP - no additional parameters needed
                writeToDeviceLog("[WAN Config] Creating DHCP task");
            }
            
            // First, check for any existing pending WAN tasks and mark them as canceled
            $cancelStmt = $db->prepare("
                UPDATE device_tasks 
                SET status = 'canceled', message = 'Superseded by newer task', updated_at = NOW()
                WHERE device_id = :device_id AND task_type = 'wan' AND status = 'pending'
            ");
            $cancelStmt->execute([':device_id' => $deviceId]);
            $canceledCount = $cancelStmt->rowCount();
            
            if ($canceledCount > 0) {
                writeToDeviceLog("[WAN Config] Canceled {$canceledCount} older pending WAN tasks");
            }
            
            // Also mark any 'in_progress' WAN tasks that have been stuck for more than 5 minutes as failed
            $failStmt = $db->prepare("
                UPDATE device_tasks 
                SET status = 'failed', message = 'Task timed out', updated_at = NOW()
                WHERE device_id = :device_id AND task_type = 'wan' AND status = 'in_progress' 
                AND updated_at < NOW() - INTERVAL 5 MINUTE
            ");
            $failStmt->execute([':device_id' => $deviceId]);
            $failedCount = $failStmt->rowCount();
            
            if ($failedCount > 0) {
                writeToDeviceLog("[WAN Config] Marked {$failedCount} stalled in-progress WAN tasks as failed");
            }
            
            // Insert task
            $stmt = $db->prepare("
                INSERT INTO device_tasks (device_id, task_type, task_data, status, created_at)
                VALUES (:device_id, 'wan', :task_data, 'pending', NOW())
            ");
            
            $stmt->execute([
                ':device_id' => $deviceId,
                ':task_data' => json_encode($taskData)
            ]);
            
            $taskId = $db->lastInsertId();
            writeToDeviceLog("[Task Created] ID: {$taskId}, Type: wan");
            
            // Prepare connection request data for frontend display
            $ipAddress = $device['ip_address'] ?? '';
            $serialNumber = $device['serial_number'] ?? '';
            
            // Build connection request URLs
            if (!empty($ipAddress)) {
                $ports = ['30005', '37215', '7547', '4567'];
                $username = 'admin';
                $password = 'admin';
                
                $connectionUrl = "http://{$ipAddress}:30005/acs";
                $command = "curl -v -u \"{$username}:{$password}\" -d \"<cwmp:ID>API_".$taskId."</cwmp:ID>\" \"{$connectionUrl}\"";
                
                $altCommands = [];
                foreach ($ports as $port) {
                    if ($port === '30005') continue; // Skip default
                    $altUrl = "http://{$ipAddress}:{$port}/acs";
                    $altCommands[] = "curl -v -u \"{$username}:{$password}\" -d \"<cwmp:ID>API_".$taskId."</cwmp:ID>\" \"{$altUrl}\"";
                }
                
                $connectionRequest = [
                    'url' => $connectionUrl,
                    'username' => $username,
                    'password' => $password,
                    'command' => $command,
                    'alternative_commands' => $altCommands
                ];
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'WAN configuration task created successfully',
                    'task_id' => $taskId,
                    'connection_request' => $connectionRequest,
                    'tr069_session_id' => $GLOBALS['session_id'] ?? null
                ]);
            } else {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'WAN configuration task created successfully',
                    'task_id' => $taskId
                ]);
            }
            break;
            
        case 'reboot':
            // Create task data
            $taskData = [
                'reboot_reason' => isset($_POST['reason']) ? $_POST['reason'] : 'User initiated reboot'
            ];
            
            // Check if we should use vendor-specific RPC for Huawei devices
            if (isset($_POST['use_vendor_rpc']) && $_POST['use_vendor_rpc'] === 'true') {
                $taskData['use_vendor_rpc'] = true;
                writeToDeviceLog("[Reboot] Creating Huawei vendor-specific reboot task for device: {$device['serial_number']}");
            } else {
                writeToDeviceLog("[Reboot] Creating standard reboot task for device: {$device['serial_number']}");
            }
            
            // Insert task
            $stmt = $db->prepare("
                INSERT INTO device_tasks (device_id, task_type, task_data, status, created_at)
                VALUES (:device_id, 'reboot', :task_data, 'pending', NOW())
            ");
            
            $stmt->execute([
                ':device_id' => $deviceId,
                ':task_data' => json_encode($taskData)
            ]);
            
            $taskId = $db->lastInsertId();
            writeToDeviceLog("[Task Created] ID: {$taskId}, Type: reboot");
            
            // Prepare connection request data for frontend display
            $ipAddress = $device['ip_address'] ?? '';
            $serialNumber = $device['serial_number'] ?? '';
            
            // Build connection request URLs
            if (!empty($ipAddress)) {
                $ports = ['30005', '37215', '7547', '4567'];
                $username = 'admin';
                $password = 'admin';
                
                $connectionUrl = "http://{$ipAddress}:30005/acs";
                $command = "curl -v -u \"{$username}:{$password}\" -d \"<cwmp:ID>API_".$taskId."</cwmp:ID>\" \"{$connectionUrl}\"";
                
                $altCommands = [];
                foreach ($ports as $port) {
                    if ($port === '30005') continue; // Skip default
                    $altUrl = "http://{$ipAddress}:{$port}/acs";
                    $altCommands[] = "curl -v -u \"{$username}:{$password}\" -d \"<cwmp:ID>API_".$taskId."</cwmp:ID>\" \"{$altUrl}\"";
                }
                
                $connectionRequest = [
                    'url' => $connectionUrl,
                    'username' => $username,
                    'password' => $password,
                    'command' => $command,
                    'alternative_commands' => $altCommands
                ];
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Reboot task created successfully',
                    'task_id' => $taskId,
                    'connection_request' => $connectionRequest,
                    'tr069_session_id' => $GLOBALS['session_id'] ?? null
                ]);
            } else {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Reboot task created successfully',
                    'task_id' => $taskId
                ]);
            }
            break;
            
        case 'get_parameter':
            if (!isset($_POST['parameter'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Parameter name is required']);
                exit;
            }
            
            $parameterName = $_POST['parameter'];
            writeToDeviceLog("[Parameter Request] Getting parameter: {$parameterName}");
            
            // For now, we'll simulate getting the parameter from a mock database
            // In a real implementation, you would query the device or a cached parameter database
            $mockParameters = [
                'InternetGatewayDevice.DeviceInfo.UpTime' => rand(10, 86400), // Random uptime between 10s and 24h
            ];
            
            // If it's a real device with cached parameters, try to get the actual value
            if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt')) {
                $parameterData = file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt');
                $matches = [];
                if (preg_match('/'. preg_quote($parameterName, '/') . '\s*=\s*([^\n]+)/', $parameterData, $matches)) {
                    $paramValue = trim($matches[1]);
                    writeToDeviceLog("[Parameter Found] {$parameterName} = {$paramValue}");
                    
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'parameter' => $parameterName,
                        'value' => $paramValue
                    ]);
                    exit;
                }
            }
            
            // Fall back to mock data if we didn't find it in the cached parameters
            if (isset($mockParameters[$parameterName])) {
                $paramValue = $mockParameters[$parameterName];
                writeToDeviceLog("[Parameter Mock] {$parameterName} = {$paramValue}");
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'parameter' => $parameterName,
                    'value' => (string)$paramValue
                ]);
            } else {
                writeToDeviceLog("[Parameter Not Found] {$parameterName}");
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Parameter not found: ' . $parameterName
                ]);
            }
            break;
            
        default:
            writeToDeviceLog("[Error] Unsupported action: {$action}");
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Unsupported action']);
            break;
    }
    
} catch (Exception $e) {
    writeToDeviceLog("[Exception] " . $e->getMessage());
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}
