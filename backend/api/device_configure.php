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
        $result = $stmt->execute([
            ':device_id' => $deviceId,
            ':task_type' => $taskType,
            ':task_data' => json_encode($taskData)
        ]);
        
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
$storeForLater = isset($_POST['store_for_later']) && $_POST['store_for_later'] === '1';

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
            
            // Store configuration as a task if device is offline or explicitly requested
            if ($storeForLater) {
                $taskData = [
                    'ssid' => $ssid,
                    'password' => $password
                ];
                
                $taskId = storeDeviceTask($db, $deviceId, 'wifi', $taskData);
                
                if ($taskId) {
                    // Update the device record in database to maintain consistency
                    $updateSql = "UPDATE devices SET ssid = :ssid, ssid_password = :password WHERE id = :id";
                    $updateStmt = $db->prepare($updateSql);
                    $updateStmt->execute([
                        ':ssid' => $ssid,
                        ':password' => $password,
                        ':id' => $deviceId
                    ]);
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'WiFi configuration task created',
                        'task_id' => $taskId
                    ]);
                    exit;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to create configuration task']);
                    exit;
                }
            }
            
            // Proceed with direct configuration
            $serialNumber = $device['serial_number'];
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
            
            $result = setDeviceParameters($serialNumber, $parameterList);
            
            if ($result) {
                // Update the device record in database
                $updateSql = "UPDATE devices SET ssid = :ssid, ssid_password = :password WHERE id = :id";
                $updateStmt = $db->prepare($updateSql);
                $updateStmt->execute([
                    ':ssid' => $ssid,
                    ':password' => $password,
                    ':id' => $deviceId
                ]);
                
                echo json_encode(['success' => true, 'message' => 'WiFi configuration updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update WiFi configuration']);
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
            
            // Store configuration as a task if device is offline or explicitly requested
            if ($storeForLater) {
                $taskData = [
                    'ip_address' => $ipAddress,
                    'gateway' => $gateway
                ];
                
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
                        'message' => 'WAN configuration task created',
                        'task_id' => $taskId
                    ]);
                    exit;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to create configuration task']);
                    exit;
                }
            }
            
            // Proceed with direct configuration
            $serialNumber = $device['serial_number'];
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
            
            $result = setDeviceParameters($serialNumber, $parameterList);
            
            if ($result) {
                // Update the device record in database
                $updateSql = "UPDATE devices SET ip_address = :ip_address WHERE id = :id";
                $updateStmt = $db->prepare($updateSql);
                $updateStmt->execute([
                    ':ip_address' => $ipAddress,
                    ':id' => $deviceId
                ]);
                
                echo json_encode(['success' => true, 'message' => 'WAN configuration updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update WAN configuration']);
            }
            
            break;
            
        case 'reboot':
            // Log reboot request
            logAction("DEVICE REBOOT: Requested for device " . $device['serial_number']);
            
            // Store as a task if device is offline or explicitly requested
            if ($storeForLater) {
                $taskId = storeDeviceTask($db, $deviceId, 'reboot', []);
                
                if ($taskId) {
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Device reboot task created',
                        'task_id' => $taskId
                    ]);
                    exit;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to create reboot task']);
                    exit;
                }
            }
            
            // Proceed with direct reboot
            $serialNumber = $device['serial_number'];
            $result = rebootDevice($serialNumber);
            
            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Reboot command sent successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to send reboot command']);
            }
            
            break;
            
        default:
            // Unknown action
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit;
    }
    
    // Default success response for direct configuration (should be overridden by action handlers)
    echo json_encode(['success' => false, 'message' => 'Action not implemented or invalid']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
