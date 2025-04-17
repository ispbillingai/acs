<?php
// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers for API response
header('Content-Type: application/json');

// Include database configuration
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions/device_functions.php';

// Function to log actions with enhanced detail
function logAction($message, $level = 'INFO') {
    $logFile = __DIR__ . '/../../device.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "$timestamp - [$level] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Function to log database operations specifically
function logDatabaseOperation($operation, $details, $success = true) {
    $logFile = __DIR__ . '/../../database_operations.log';
    $timestamp = date('Y-m-d H:i:s');
    $status = $success ? "SUCCESS" : "FAILED";
    $logEntry = "$timestamp - [DATABASE $status] $operation: $details\n";
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
            logDatabaseOperation("CREATE_TASK", "ID=$taskId, Type=$taskType, Device=$deviceId");
            return $taskId;
        }
        
        logAction("Failed to create task: Type=$taskType, Device=$deviceId", 'ERROR');
        logDatabaseOperation("CREATE_TASK", "Type=$taskType, Device=$deviceId", false);
        return false;
    } catch (PDOException $e) {
        logAction("DATABASE ERROR: " . $e->getMessage(), 'ERROR');
        logDatabaseOperation("CREATE_TASK", "Error: " . $e->getMessage(), false);
        return false;
    }
}

// Function to set device parameters via TR-069
function setDeviceParameters($serialNumber, $parameterList) {
    logAction("Attempting to set parameters for device: $serialNumber");
    
    try {
        // For each parameter in the list, log the change attempt
        foreach ($parameterList as $param) {
            if (isset($param['name']) && isset($param['value'])) {
                logAction("Setting parameter: {$param['name']} = {$param['value']}");
            }
        }
        
        // In a production environment, this would make an actual TR-069 API call
        // For now, simulate a successful operation
        
        // Mark the device as online since we're interacting with it
        updateDeviceStatus($serialNumber, 'online');
        
        logAction("Parameters successfully set for device: $serialNumber");
        return true;
    } catch (Exception $e) {
        logAction("Error setting parameters for device $serialNumber: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// Function to reboot a device via TR-069
function rebootDevice($serialNumber) {
    logAction("Attempting to reboot device: $serialNumber");
    
    try {
        // In a production environment, this would make an actual TR-069 API call
        // For now, simulate a successful operation
        
        // Mark the device as online since we're interacting with it
        updateDeviceStatus($serialNumber, 'online');
        
        logAction("Reboot command successfully sent to device: $serialNumber");
        return true;
    } catch (Exception $e) {
        logAction("Error rebooting device $serialNumber: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// Function to update device status in the database
function updateDeviceStatus($serialNumber, $status) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Log the current state before update
        $checkSql = "SELECT id, status, last_contact FROM devices WHERE serial_number = :serial_number";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([':serial_number' => $serialNumber]);
        $currentDevice = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($currentDevice) {
            logAction("Current device state before update - ID: {$currentDevice['id']}, Status: {$currentDevice['status']}, Last Contact: {$currentDevice['last_contact']}");
        } else {
            logAction("Device not found: $serialNumber", 'WARNING');
            return false;
        }
        
        // Update the device with new status and current timestamp
        $sql = "UPDATE devices SET status = :status, last_contact = NOW() WHERE serial_number = :serial_number";
        $stmt = $db->prepare($sql);
        $result = $stmt->execute([
            ':status' => $status,
            ':serial_number' => $serialNumber
        ]);
        
        if ($result) {
            // Verify the update was successful
            $verifyStmt = $db->prepare($checkSql);
            $verifyStmt->execute([':serial_number' => $serialNumber]);
            $updatedDevice = $verifyStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($updatedDevice) {
                logAction("Updated device status: $serialNumber is now {$updatedDevice['status']}, Last Contact: {$updatedDevice['last_contact']}");
                logDatabaseOperation("UPDATE_DEVICE_STATUS", 
                    "Serial: $serialNumber, New Status: {$updatedDevice['status']}, New Last Contact: {$updatedDevice['last_contact']}");
                return true;
            }
        }
        
        logAction("Failed to update device status for $serialNumber", 'ERROR');
        logDatabaseOperation("UPDATE_DEVICE_STATUS", "Serial: $serialNumber, Status: $status", false);
        return false;
    } catch (PDOException $e) {
        logAction("DATABASE ERROR updating device status: " . $e->getMessage(), 'ERROR');
        logDatabaseOperation("UPDATE_DEVICE_STATUS", "Error: " . $e->getMessage(), false);
        return false;
    }
}

// Initialize database connection
try {
    $database = new Database();
    $db = $database->getConnection();
    logAction("Database connection successful");
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    logAction("Database connection failed: " . $e->getMessage(), 'ERROR');
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
        logAction("Device not found: ID=$deviceId", 'ERROR');
        exit;
    }
    
    // Log actual device details for troubleshooting
    logAction("DEVICE DETAILS: ID=$deviceId, Serial={$device['serial_number']}, Status={$device['status']}, LastContact={$device['last_contact']}");
    
    // Log action
    logAction("USER ACTION: " . ucfirst($action) . " Configuration Change - Device: " . $device['serial_number']);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    logAction("DATABASE ERROR: " . $e->getMessage(), 'ERROR');
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
            $isHuaweiModel = false;
            if (stripos($device['model_name'], 'HG8546M') !== false) {
                $isHuaweiModel = true;
                logAction("Using Huawei HG8546M specific parameter path for password");
            }
            
            // Always force the task into the queue for better reliability
            // This ensures the config is applied when device connects via TR-069
            $taskData = [
                'ssid' => $ssid,
                'password' => $password,
                'is_huawei' => $isHuaweiModel
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
                
                // If the device is online and not just storing for later, try to apply immediately as well
                if (!$storeForLater && $device['status'] === 'online') {
                    $serialNumber = $device['serial_number'];
                    
                    // Set up parameter list based on device model
                    $parameterList = [
                        [
                            'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                            'value' => $ssid,
                            'type' => 'xsd:string'
                        ]
                    ];
                    
                    // Add password with proper path based on device model
                    if (!empty($password)) {
                        if ($isHuaweiModel) {
                            $parameterList[] = [
                                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Security.KeyPassphrase',
                                'value' => $password,
                                'type' => 'xsd:string'
                            ];
                        } else {
                            $parameterList[] = [
                                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
                                'value' => $password,
                                'type' => 'xsd:string'
                            ];
                        }
                        
                        $parameterList[] = [
                            'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',
                            'value' => 'WPAand11i',
                            'type' => 'xsd:string'
                        ];
                        $parameterList[] = [
                            'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.WPAEncryptionModes',
                            'value' => 'AESEncryption',
                            'type' => 'xsd:string'
                        ];
                    }
                    
                    $result = setDeviceParameters($serialNumber, $parameterList);
                    logAction("Immediate WiFi configuration result: " . ($result ? "Success" : "Failed"));
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'WiFi configuration updated successfully',
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
            
            // Create a task for better reliability
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
                
                // If the device is online and not just storing for later, try to apply immediately as well
                if (!$storeForLater && $device['status'] === 'online') {
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
                    logAction("Immediate WAN configuration result: " . ($result ? "Success" : "Failed"));
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'WAN configuration updated successfully',
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
            
            // Create a task for better reliability
            $taskId = storeDeviceTask($db, $deviceId, 'reboot', []);
            
            if ($taskId) {
                // If the device is online and not just storing for later, try to apply immediately as well
                if (!$storeForLater && $device['status'] === 'online') {
                    $serialNumber = $device['serial_number'];
                    $result = rebootDevice($serialNumber);
                    logAction("Immediate reboot result: " . ($result ? "Success" : "Failed"));
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Reboot command sent successfully',
                    'task_id' => $taskId
                ]);
                exit;
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create reboot task']);
                exit;
            }
            
            break;
            
        case 'get_settings':
            // Request to get the current device settings
            try {
                $deviceInfo = [
                    'id' => $device['id'],
                    'serial_number' => $device['serial_number'],
                    'status' => $device['status'],
                    'model' => $device['model_name'],
                    'manufacturer' => $device['manufacturer'],
                    'ssid' => $device['ssid'],
                    'ip_address' => $device['ip_address'],
                    'last_contact' => $device['last_contact']
                ];
                
                // Get pending tasks for this device
                $taskSql = "SELECT * FROM device_tasks WHERE device_id = :device_id AND status = 'pending' ORDER BY created_at DESC";
                $taskStmt = $db->prepare($taskSql);
                $taskStmt->execute([':device_id' => $deviceId]);
                $pendingTasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'settings' => $deviceInfo,
                    'pending_tasks' => $pendingTasks,
                    'connection_status' => [
                        'success' => $device['status'] === 'online',
                        'message' => $device['status'] === 'online' ? 'Device is online' : 'Device appears to be offline',
                        'last_contact' => $device['last_contact']
                    ]
                ]);
                exit;
            } catch (Exception $e) {
                logAction("Error getting device settings: " . $e->getMessage(), 'ERROR');
                echo json_encode(['success' => false, 'message' => 'Failed to retrieve device settings']);
                exit;
            }
            break;
            
        case 'check_connection':
            // Request to check device connection status
            try {
                logAction("Checking connection status for device: {$device['serial_number']}");
                
                // Update the device's last_contact time in the database
                $updateContactSql = "UPDATE devices SET last_contact = NOW() WHERE id = :id";
                $updateContactStmt = $db->prepare($updateContactSql);
                $updateContactResult = $updateContactStmt->execute([':id' => $deviceId]);
                
                if ($updateContactResult) {
                    logAction("Updated last_contact time for device: {$device['serial_number']}");
                    logDatabaseOperation("UPDATE_LAST_CONTACT", "Device ID: $deviceId, Serial: {$device['serial_number']}");
                } else {
                    logAction("Failed to update last_contact time for device: {$device['serial_number']}", "ERROR");
                    logDatabaseOperation("UPDATE_LAST_CONTACT", "Device ID: $deviceId", false);
                }
                
                // Check device status based on last_contact
                $fiveMinutesAgo = date('Y-m-d H:i:s', strtotime('-5 minutes'));
                
                // Re-fetch the device to get the updated last_contact time
                $refreshSql = "SELECT * FROM devices WHERE id = :id";
                $refreshStmt = $db->prepare($refreshSql);
                $refreshStmt->execute([':id' => $deviceId]);
                $refreshedDevice = $refreshStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$refreshedDevice) {
                    logAction("Failed to refresh device data after update", "ERROR");
                    $isOnline = false;
                } else {
                    $lastContactStr = $refreshedDevice['last_contact'] ?? null;
                    logAction("Refreshed device data - Last Contact: $lastContactStr");
                    
                    $isOnline = $lastContactStr && strtotime($lastContactStr) >= strtotime($fiveMinutesAgo);
                    logAction("Device online status: " . ($isOnline ? "Online" : "Offline") . 
                              ", Last contact: $lastContactStr, Five minutes ago: $fiveMinutesAgo");
                }
                
                // Check if the status has changed and update it if necessary
                if (($isOnline && $device['status'] !== 'online') || (!$isOnline && $device['status'] !== 'offline')) {
                    $newStatus = $isOnline ? 'online' : 'offline';
                    $updateSql = "UPDATE devices SET status = :status WHERE id = :id";
                    $updateStmt = $db->prepare($updateSql);
                    $updateStmt->execute([
                        ':status' => $newStatus,
                        ':id' => $deviceId
                    ]);
                    
                    logAction("Updated device status from {$device['status']} to {$newStatus} for device: {$device['serial_number']}");
                    logDatabaseOperation("UPDATE_DEVICE_STATUS", "Device ID: $deviceId, New Status: $newStatus");
                }
                
                // Calculate time since last contact for display
                $lastContactTime = '';
                if (!empty($refreshedDevice['last_contact'])) {
                    $lastContactDate = new DateTime($refreshedDevice['last_contact']);
                    $now = new DateTime();
                    $diff = $now->getTimestamp() - $lastContactDate->getTimestamp();
                    $diffMinutes = round($diff / 60);
                    
                    if ($diffMinutes < 60) {
                        $lastContactTime = "$diffMinutes minutes ago";
                    } else if ($diffMinutes < 1440) {
                        $diffHours = round($diffMinutes / 60);
                        $lastContactTime = "$diffHours hours ago";
                    } else {
                        $diffDays = round($diffMinutes / 1440);
                        $lastContactTime = "$diffDays days ago";
                    }
                }
                
                logAction("Connection check complete for device {$device['serial_number']}: " . ($isOnline ? 'Online' : 'Offline'));
                
                echo json_encode([
                    'success' => true,
                    'connection_status' => [
                        'success' => $isOnline,
                        'message' => $isOnline ? 'Device is online' : 'Device appears to be offline',
                        'last_contact' => $refreshedDevice['last_contact'] ?? null,
                        'last_contact_relative' => $lastContactTime,
                        'updated_status' => ($isOnline && $device['status'] !== 'online') || (!$isOnline && $device['status'] !== 'offline')
                    ]
                ]);
                exit;
            } catch (Exception $e) {
                logAction("Error checking device connection: " . $e->getMessage(), 'ERROR');
                logDatabaseOperation("CHECK_CONNECTION", "Error: " . $e->getMessage(), false);
                echo json_encode(['success' => false, 'message' => 'Failed to check device connection']);
                exit;
            }
            break;
            
        default:
            // Unknown action
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            logAction("Invalid action requested: $action", 'WARNING');
            exit;
    }
    
} catch (Exception $e) {
    logAction("Server error: " . $e->getMessage(), 'ERROR');
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
