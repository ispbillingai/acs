<?php
// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers for API response
header('Content-Type: application/json');

// Include database configuration
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions/device_functions.php';

// Function to log actions
function logAction($message) {
    $logFile = __DIR__ . '/../../device.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "$timestamp - $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Function to store a task for later execution
function storeDeviceTask($db, $deviceId, $taskType, $taskData) {
    try {
        $sql = "INSERT INTO device_tasks (device_id, task_type, task_data) VALUES (:device_id, :task_type, :task_data)";
        $stmt = $db->prepare($sql);
        $paramData = json_encode($taskData);
        
        // Bind parameters directly without using references for PDO
        $stmt->bindParam(':device_id', $deviceId, PDO::PARAM_INT);
        $stmt->bindParam(':task_type', $taskType, PDO::PARAM_STR);
        $stmt->bindParam(':task_data', $paramData, PDO::PARAM_STR);
        
        $result = $stmt->execute();
        
        if ($result) {
            $taskId = $db->lastInsertId();
            logAction("Task created: ID=$taskId, Type=$taskType, Device=$deviceId");
            return $taskId;
        }
        
        return false;
    } catch (PDOException $e) {
        logAction("DATABASE ERROR: " . $e->getMessage());
        return false;
    }
}

// Function to set parameters on a device via TR-069
function setDeviceParameters($serialNumber, $parameterList) {
    logAction("Attempting to set parameters for device: $serialNumber");
    
    // For devices behind NAT, we should store the request and wait for the next Inform
    // This is just a simulation for now - in real implementation we would:
    // 1. Check if the device is online through a direct connection
    // 2. If not reachable, store the request for when the device next connects
    
    // Simulating success - in real world, we'd verify
    $isSuccess = true;
    
    if ($isSuccess) {
        logAction("Parameters successfully set for device: $serialNumber");
    } else {
        logAction("Failed to set parameters for device: $serialNumber");
    }
    
    return $isSuccess;
}

// Function to reboot a device via TR-069
function rebootDevice($serialNumber) {
    logAction("Attempting to reboot device: $serialNumber");
    
    // For devices behind NAT, we should store the reboot request and wait for next Inform
    // Simulating success - in real world, we'd verify
    $isSuccess = true;
    
    if ($isSuccess) {
        logAction("Reboot command successfully sent to device: $serialNumber");
    } else {
        logAction("Failed to send reboot command to device: $serialNumber");
    }
    
    return $isSuccess;
}

// Initialize database connection
try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Validate required parameters
if (!isset($_POST['device_id']) || !isset($_POST['action'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

$deviceId = $_POST['device_id'];
$action = $_POST['action'];

// Always store for later by default for devices that may be behind NAT
// Can be overridden with explicit parameter
$storeForLater = isset($_POST['store_for_later']) ? ($_POST['store_for_later'] === '1') : true;

// Validate device ID
try {
    $sql = "SELECT * FROM devices WHERE id = :id";
    $stmt = $db->prepare($sql);
    $stmt->execute([':id' => $deviceId]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$device) {
        echo json_encode(['success' => false, 'message' => 'Device not found']);
        exit;
    }
    
    // Log action
    logAction("USER ACTION: " . ucfirst($action) . " Configuration Change - Device: " . $device['serial_number']);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// Process based on action type
try {
    switch ($action) {
        case 'get_settings':
            // Handle retrieving current device settings
            $settings = [
                'ssid' => $device['ssid'] ?? '',
                'ip_address' => $device['ip_address'] ?? '',
                'gateway' => $device['gateway'] ?? '',
                'model' => $device['model_name'] ?? '',
                'serial' => $device['serial_number'] ?? '',
                'manufacturer' => $device['manufacturer'] ?? ''
            ];
            
            // Check if there are any pending tasks for this device
            $tasksSql = "SELECT * FROM device_tasks WHERE device_id = :device_id AND status = 'pending' ORDER BY created_at DESC";
            $tasksStmt = $db->prepare($tasksSql);
            $tasksStmt->execute([':device_id' => $deviceId]);
            $pendingTasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true, 
                'settings' => $settings,
                'pending_tasks' => count($pendingTasks),
                'connection_status' => [
                    'success' => true,
                    'message' => 'Device settings retrieved successfully'
                ]
            ]);
            exit;
            
        case 'check_connection':
            // Simulate checking TR-069 connection for devices behind NAT
            // In a real implementation, you'd check for recent Inform messages
            
            $tenMinutesAgo = date('Y-m-d H:i:s', strtotime('-10 minutes'));
            $isRecentlyActive = strtotime($device['lastContact']) >= strtotime($tenMinutesAgo);
            
            if ($isRecentlyActive) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Device has connected recently through TR-069',
                    'details' => 'Last connection: ' . $device['lastContact']
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Device has not connected recently',
                    'details' => 'Last connection: ' . $device['lastContact'] . '. Device may be offline or behind NAT.'
                ]);
            }
            exit;
            
        case 'wifi':
            // Validate WiFi parameters
            if (!isset($_POST['ssid'])) {
                echo json_encode(['success' => false, 'message' => 'SSID is required']);
                exit;
            }
            
            $ssid = $_POST['ssid'];
            $password = $_POST['password'] ?? '';
            
            // Log parameter changes
            logAction("PARAMETER SET: SSID changed from '" . $device['ssid'] . "' to '" . $ssid . "'");
            if (!empty($password)) {
                logAction("PARAMETER SET: WiFi password changed (length: " . strlen($password) . " chars)");
            }
            
            // Detect device model for special handling
            if (stripos($device['model_name'], 'HG8546M') !== false) {
                logAction("Using Huawei HG8546M specific parameter path for password");
            }
            
            // Always store configuration as a task for devices that might be behind NAT
            $taskData = [
                'ssid' => $ssid,
                'password' => $password,
                'security' => $_POST['security'] ?? 'WPA2-PSK'
            ];
            
            $taskId = storeDeviceTask($db, $deviceId, 'wifi', $taskData);
            
            if ($taskId) {
                // Update the device record in database to maintain consistency
                $updateSql = "UPDATE devices SET ssid = :ssid WHERE id = :id";
                $updateStmt = $db->prepare($updateSql);
                $updateStmt->execute([
                    ':ssid' => $ssid,
                    ':id' => $deviceId
                ]);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'WiFi configuration task created. Changes will be applied when device next connects.',
                    'task_id' => $taskId
                ]);
                exit;
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create configuration task']);
                exit;
            }
            
            break;
            
        case 'wan':
            // Validate WAN parameters
            if (!isset($_POST['ip_address'])) {
                echo json_encode(['success' => false, 'message' => 'IP address is required']);
                exit;
            }
            
            $ipAddress = $_POST['ip_address'];
            $gateway = $_POST['gateway'] ?? '';
            
            // Log parameter changes
            logAction("PARAMETER SET: IP Address changed to '" . $ipAddress . "'");
            if (!empty($gateway)) {
                logAction("PARAMETER SET: Gateway changed to '" . $gateway . "'");
            }
            
            // Always store configuration as a task for devices that might be behind NAT
            $taskData = [
                'ip_address' => $ipAddress,
                'gateway' => $gateway,
                'connection_type' => $_POST['connection_type'] ?? 'DHCP'
            ];
            
            // Add PPPoE or Static IP specific fields if provided
            if (isset($_POST['subnet_mask'])) {
                $taskData['subnet_mask'] = $_POST['subnet_mask'];
            }
            
            if (isset($_POST['dns_servers'])) {
                $taskData['dns_servers'] = $_POST['dns_servers'];
            }
            
            if (isset($_POST['pppoe_username'])) {
                $taskData['pppoe_username'] = $_POST['pppoe_username'];
            }
            
            if (isset($_POST['pppoe_password'])) {
                $taskData['pppoe_password'] = $_POST['pppoe_password'];
            }
            
            $taskId = storeDeviceTask($db, $deviceId, 'wan', $taskData);
            
            if ($taskId) {
                // Update the device record in database
                $updateSql = "UPDATE devices SET ip_address = :ip_address WHERE id = :id";
                $updateStmt = $db->prepare($updateSql);
                $updateStmt->execute([
                    ':ip_address' => $ipAddress,
                    ':id' => $deviceId
                ]);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'WAN configuration task created. Changes will be applied when device next connects.',
                    'task_id' => $taskId
                ]);
                exit;
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create configuration task']);
                exit;
            }
            
            break;
            
        case 'reboot':
            // Log reboot request
            logAction("DEVICE REBOOT: Requested for device " . $device['serial_number']);
            
            // Always store as a task for devices that might be behind NAT
            $taskId = storeDeviceTask($db, $deviceId, 'reboot', []);
            
            if ($taskId) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Device reboot task created. Reboot will be initiated when device next connects.',
                    'task_id' => $taskId
                ]);
                exit;
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create reboot task']);
                exit;
            }
            
            break;
            
        default:
            // Unknown action
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
