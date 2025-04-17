
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
if (!isset($_POST['device_id']) || !isset($_POST['action'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

$deviceId = $_POST['device_id'];
$action = $_POST['action'];

writeToDeviceLog("[Configuration Request] Device ID: {$deviceId}, Action: {$action}");

try {
    // Include database connection
    require_once __DIR__ . '/../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
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
    
    writeToDeviceLog("[Device Found] Serial: {$device['serial_number']}, Status: {$device['status']}");
    
    // Process based on action type
    switch ($action) {
        case 'wifi':
            // Validate parameters
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
            // Validate parameters
            if (!isset($_POST['ip_address'])) {
                writeToDeviceLog("[Error] Missing IP address for WAN configuration");
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'IP address is required']);
                exit;
            }
            
            $ipAddress = $_POST['ip_address'];
            $gateway = $_POST['gateway'] ?? '';
            
            // Create task data
            $taskData = json_encode([
                'ip_address' => $ipAddress,
                'gateway' => $gateway
            ]);
            
            writeToDeviceLog("[WAN Config] Creating task for IP: {$ipAddress}, Gateway: {$gateway}");
            
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
            
            // Insert task
            $stmt = $db->prepare("
                INSERT INTO device_tasks (device_id, task_type, task_data, status, created_at)
                VALUES (:device_id, 'wan', :task_data, 'pending', NOW())
            ");
            
            $stmt->execute([
                ':device_id' => $deviceId,
                ':task_data' => $taskData
            ]);
            
            $taskId = $db->lastInsertId();
            writeToDeviceLog("[Task Created] ID: {$taskId}, Type: wan");
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'WAN configuration task created successfully',
                'task_id' => $taskId
            ]);
            break;
            
        case 'reboot':
            // Create empty task data
            $taskData = json_encode([]);
            
            writeToDeviceLog("[Reboot] Creating reboot task for device: {$device['serial_number']}");
            
            // Insert task
            $stmt = $db->prepare("
                INSERT INTO device_tasks (device_id, task_type, task_data, status, created_at)
                VALUES (:device_id, 'reboot', :task_data, 'pending', NOW())
            ");
            
            $stmt->execute([
                ':device_id' => $deviceId,
                ':task_data' => $taskData
            ]);
            
            $taskId = $db->lastInsertId();
            writeToDeviceLog("[Task Created] ID: {$taskId}, Type: reboot");
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Reboot task created successfully',
                'task_id' => $taskId
            ]);
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
