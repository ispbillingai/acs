
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
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'WiFi configuration task created successfully',
                'task_id' => $taskId
            ]);
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
