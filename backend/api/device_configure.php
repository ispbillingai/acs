
<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions/device_functions.php';

$response = ['success' => false, 'message' => 'Invalid request'];

try {
    $database = new Database();
    $db = $database->getConnection();

    $deviceId = $_POST['device_id'] ?? null;
    $action = $_POST['action'] ?? null;

    if (!$deviceId) {
        throw new Exception("Device ID is required");
    }

    $logFile = __DIR__ . '/../../logs/configure.log';
    
    // Create logs directory if it doesn't exist
    if (!file_exists(dirname($logFile))) {
        mkdir(dirname($logFile), 0755, true);
    }
    
    // Log the incoming request with detailed information
    $timestamp = date('Y-m-d H:i:s');
    $requestInfo = "$timestamp - API REQUEST: $action for device $deviceId\n";
    $requestInfo .= "  POST parameters: " . json_encode($_POST) . "\n";
    file_put_contents($logFile, $requestInfo, FILE_APPEND);
    error_log("[TR-069] $requestInfo");
    
    // Get device information from database
    $stmt = $db->prepare("SELECT * FROM devices WHERE id = :id");
    $stmt->bindParam(':id', $deviceId);
    $stmt->execute();
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$device) {
        $errorMsg = "$timestamp - ERROR: Device not found with ID $deviceId\n";
        file_put_contents($logFile, $errorMsg, FILE_APPEND);
        error_log("[TR-069] $errorMsg");
        throw new Exception("Device not found");
    }

    // Log device information
    $deviceInfo = "$timestamp - DEVICE INFO:\n";
    $deviceInfo .= "  Serial: {$device['serial_number']}\n";
    $deviceInfo .= "  Model: {$device['model_name']}\n";
    $deviceInfo .= "  IP: {$device['ip_address']}\n";
    $deviceInfo .= "  Status: {$device['status']}\n";
    $deviceInfo .= "  Last Contact: {$device['last_contact']}\n";
    file_put_contents($logFile, $deviceInfo, FILE_APPEND);
    error_log("[TR-069] $deviceInfo");
    
    switch ($action) {
        case 'get_settings':
            // Return current device settings
            $response = [
                'success' => true, 
                'settings' => [
                    'ssid' => $device['ssid'] ?? '',
                    'password' => $device['ssid_password'] ?? '',
                    'ip_address' => $device['ip_address'] ?? '',
                    'gateway' => '',  // Get from database if available
                    'connection_request_username' => $device['connection_request_username'] ?? 'admin',
                    'connection_request_password' => $device['connection_request_password'] ?? 'admin'
                ]
            ];
            
            // Log the settings retrieval
            $settingsLog = "$timestamp - Retrieved current settings for device $deviceId\n";
            $settingsLog .= "  SSID: {$device['ssid']}\n";
            $settingsLog .= "  Password length: " . strlen($device['ssid_password'] ?? '') . " chars\n";
            $settingsLog .= "  IP: {$device['ip_address']}\n";
            file_put_contents($logFile, $settingsLog, FILE_APPEND);
            error_log("[TR-069] $settingsLog");
            
            break;
            
        case 'wifi':
            $ssid = $_POST['ssid'] ?? '';
            $password = $_POST['password'] ?? '';
            
            if (empty($ssid)) {
                $errorMsg = "$timestamp - ERROR: Empty SSID provided\n";
                file_put_contents($logFile, $errorMsg, FILE_APPEND);
                error_log("[TR-069] $errorMsg");
                throw new Exception("SSID cannot be empty");
            }
            
            // Log detailed WiFi configuration change attempt
            $logEntry = "$timestamp - WiFi CONFIGURATION CHANGE ATTEMPT:\n";
            $logEntry .= "  Device ID: $deviceId\n";
            $logEntry .= "  New SSID: $ssid\n";
            $logEntry .= "  Password Length: " . strlen($password) . " characters\n";
            $logEntry .= "  IP Address: {$device['ip_address']}\n";
            
            file_put_contents($logFile, $logEntry, FILE_APPEND);
            error_log("[TR-069] $logEntry");
            
            // Create a task in the database
            try {
                // Prepare task data
                $taskData = json_encode([
                    'ssid' => $ssid,
                    'password' => $password
                ]);
                
                // Insert task
                $taskStmt = $db->prepare("
                    INSERT INTO device_tasks 
                        (device_id, task_type, task_data, status, created_at, updated_at) 
                    VALUES 
                        (:device_id, 'wifi', :task_data, 'pending', NOW(), NOW())
                ");
                $taskStmt->execute([
                    ':device_id' => $deviceId,
                    ':task_data' => $taskData
                ]);
                
                $taskId = $db->lastInsertId();
                
                $taskLog = "$timestamp - Created WiFi configuration task ID: $taskId\n";
                file_put_contents($logFile, $taskLog, FILE_APPEND);
                error_log("[TR-069] $taskLog");
                
                // Update device in database with new WiFi settings (pending)
                $updateStmt = $db->prepare("UPDATE devices SET 
                    ssid = :ssid, 
                    ssid_password = :password
                    WHERE id = :id");
                    
                $updateStmt->bindParam(':ssid', $ssid);
                $updateStmt->bindParam(':password', $password);
                $updateStmt->bindParam(':id', $deviceId);
                $updateStmt->execute();
                
                $dbUpdateLog = "$timestamp - Updated device database record with new WiFi settings (pending)\n";
                file_put_contents($logFile, $dbUpdateLog, FILE_APPEND);
                error_log("[TR-069] $dbUpdateLog");
                
                // Success response
                $response = [
                    'success' => true,
                    'message' => 'WiFi configuration task has been created. It will be applied during the next TR-069 session.',
                    'task_id' => $taskId
                ];
            } catch (PDOException $e) {
                $errorMsg = "$timestamp - DATABASE ERROR: Failed to create task: " . $e->getMessage() . "\n";
                file_put_contents($logFile, $errorMsg, FILE_APPEND);
                error_log("[TR-069] $errorMsg");
                throw new Exception("Database error: " . $e->getMessage());
            }
            break;
            
        case 'wan':
            $ipAddress = $_POST['ip_address'] ?? '';
            $gateway = $_POST['gateway'] ?? '';
            
            if (empty($ipAddress)) {
                throw new Exception("IP address cannot be empty");
            }

            // Log WAN configuration change
            $logEntry = date('Y-m-d H:i:s') . " - Device $deviceId: WAN configuration change requested. IP: $ipAddress, Gateway: $gateway\n";
            file_put_contents($logFile, $logEntry, FILE_APPEND);
            error_log("[TR-069] $logEntry");
            
            // Create a task in the database
            try {
                // Prepare task data
                $taskData = json_encode([
                    'ip_address' => $ipAddress,
                    'gateway' => $gateway
                ]);
                
                // Insert task
                $taskStmt = $db->prepare("
                    INSERT INTO device_tasks 
                        (device_id, task_type, task_data, status, created_at, updated_at) 
                    VALUES 
                        (:device_id, 'wan', :task_data, 'pending', NOW(), NOW())
                ");
                $taskStmt->execute([
                    ':device_id' => $deviceId,
                    ':task_data' => $taskData
                ]);
                
                $taskId = $db->lastInsertId();
                
                $taskLog = "$timestamp - Created WAN configuration task ID: $taskId\n";
                file_put_contents($logFile, $taskLog, FILE_APPEND);
                error_log("[TR-069] $taskLog");
                
                // Update device IP in database
                $updateStmt = $db->prepare("UPDATE devices SET ip_address = :ip WHERE id = :id");
                $updateStmt->bindParam(':ip', $ipAddress);
                $updateStmt->bindParam(':id', $deviceId);
                $updateStmt->execute();
                
                $dbUpdateLog = "$timestamp - Updated device database record with new IP address\n";
                file_put_contents($logFile, $dbUpdateLog, FILE_APPEND);
                error_log("[TR-069] $dbUpdateLog");

                $response = [
                    'success' => true, 
                    'message' => 'WAN configuration task has been created. It will be applied during the next TR-069 session.',
                    'task_id' => $taskId
                ];
            } catch (PDOException $e) {
                $errorMsg = "$timestamp - DATABASE ERROR: Failed to create task: " . $e->getMessage() . "\n";
                file_put_contents($logFile, $errorMsg, FILE_APPEND);
                error_log("[TR-069] $errorMsg");
                throw new Exception("Database error: " . $e->getMessage());
            }
            break;

        case 'reboot':
            // Log device reboot attempt
            $logEntry = date('Y-m-d H:i:s') . " - Device $deviceId: Reboot requested\n";
            file_put_contents($logFile, $logEntry, FILE_APPEND);
            error_log("[TR-069] $logEntry");
            
            // Create a task in the database
            try {
                // Prepare task data (empty for reboot)
                $taskData = json_encode([
                    'timestamp' => time()
                ]);
                
                // Insert task
                $taskStmt = $db->prepare("
                    INSERT INTO device_tasks 
                        (device_id, task_type, task_data, status, created_at, updated_at) 
                    VALUES 
                        (:device_id, 'reboot', :task_data, 'pending', NOW(), NOW())
                ");
                $taskStmt->execute([
                    ':device_id' => $deviceId,
                    ':task_data' => $taskData
                ]);
                
                $taskId = $db->lastInsertId();
                
                $taskLog = "$timestamp - Created reboot task ID: $taskId\n";
                file_put_contents($logFile, $taskLog, FILE_APPEND);
                error_log("[TR-069] $taskLog");
                
                $response = [
                    'success' => true, 
                    'message' => 'Reboot task has been created. It will be applied during the next TR-069 session.',
                    'task_id' => $taskId
                ];
            } catch (PDOException $e) {
                $errorMsg = "$timestamp - DATABASE ERROR: Failed to create task: " . $e->getMessage() . "\n";
                file_put_contents($logFile, $errorMsg, FILE_APPEND);
                error_log("[TR-069] $errorMsg");
                throw new Exception("Database error: " . $e->getMessage());
            }
            break;
            
        default:
            throw new Exception("Invalid action: $action");
    }
} catch (Exception $e) {
    // Log the exception
    $timestamp = date('Y-m-d H:i:s');
    $errorLog = "$timestamp - EXCEPTION: " . $e->getMessage() . "\n";
    $errorLog .= "  Trace: " . $e->getTraceAsString() . "\n\n";
    
    error_log("[TR-069] $errorLog");
    
    if (isset($logFile)) {
        file_put_contents($logFile, $errorLog, FILE_APPEND);
    }
    
    $response = [
        'success' => false,
        'message' => $e->getMessage()
    ];
}

echo json_encode($response);
exit;
