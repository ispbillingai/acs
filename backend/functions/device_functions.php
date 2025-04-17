<?php
// Function to log detailed actions
function logDetail($message, $level = 'INFO') {
    $logFile = __DIR__ . '/../../device_details.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "$timestamp - [$level] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Function to retrieve all devices from the database
function getDevices($db) {
    try {
        $sql = "SELECT d.*, 
                (SELECT COUNT(*) FROM device_tasks WHERE device_id = d.id AND status = 'pending') as pending_tasks 
                FROM devices d ORDER BY d.last_contact DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Map database field names to the expected field names in the application
        $mappedDevices = [];
        foreach ($devices as $device) {
            // Log raw device data
            logDetail("Device from DB: ID={$device['id']}, Serial={$device['serial_number']}, Status={$device['status']}, LastContact={$device['last_contact']}");
            
            $mappedDevice = [
                'id' => $device['id'],
                'manufacturer' => $device['manufacturer'] ?? 'Unknown',
                'model' => $device['model_name'] ?? 'Unknown Model',
                'serialNumber' => $device['serial_number'] ?? 'Unknown',
                'ipAddress' => $device['ip_address'] ?? 'N/A',
                'lastContact' => $device['last_contact'] ?? date('Y-m-d H:i:s'),
                'status' => $device['status'] ?? 'unknown',
                'ssid' => $device['ssid'] ?? '',
                'connectedClients' => $device['connected_clients'] ?? 0,
                'uptime' => $device['uptime'] ?? '0',
                'pending_tasks' => $device['pending_tasks'] ?? 0
            ];
            
            // Update status based on last contact time
            $fiveMinutesAgo = date('Y-m-d H:i:s', strtotime('-5 minutes'));
            if ($device['last_contact'] && strtotime($device['last_contact']) >= strtotime($fiveMinutesAgo)) {
                // Device has checked in within the last 5 minutes
                if ($mappedDevice['status'] !== 'online') {
                    $mappedDevice['status'] = 'online';
                    // Update database to reflect this
                    updateDeviceStatusInDb($db, $device['id'], 'online');
                    logDetail("Updated device status to online: ID={$device['id']}, Serial={$device['serial_number']}");
                }
            } else if ($mappedDevice['status'] !== 'offline' && $device['last_contact']) {
                // Device hasn't checked in within 5 minutes
                $mappedDevice['status'] = 'offline';
                // Update database to reflect this
                updateDeviceStatusInDb($db, $device['id'], 'offline');
                logDetail("Updated device status to offline: ID={$device['id']}, Serial={$device['serial_number']}");
            }
            
            $mappedDevices[] = $mappedDevice;
        }
        
        logAction("Retrieved " . count($mappedDevices) . " devices from database");
        return $mappedDevices;
    } catch (PDOException $e) {
        logError("Database error in getDevices(): " . $e->getMessage());
        return [];
    }
}

// Helper function to update device status in the database
function updateDeviceStatusInDb($db, $deviceId, $status) {
    try {
        $sql = "UPDATE devices SET status = :status WHERE id = :id";
        $stmt = $db->prepare($sql);
        $result = $stmt->execute([
            ':status' => $status,
            ':id' => $deviceId
        ]);
        
        if ($result) {
            logDetail("Successfully updated device status in DB: ID=$deviceId, Status=$status");
            return true;
        } else {
            logDetail("Failed to update device status in DB: ID=$deviceId, Status=$status", "ERROR");
            return false;
        }
    } catch (PDOException $e) {
        logDetail("Database error updating device status: " . $e->getMessage(), "ERROR");
        return false;
    }
}

// Function to get a single device by ID
function getDevice($db, $id) {
    try {
        $sql = "SELECT * FROM devices WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($device) {
            // Log raw device data
            logDetail("Device details from DB: ID={$device['id']}, Serial={$device['serial_number']}, Status={$device['status']}, LastContact={$device['last_contact']}");
            
            // Map database field names to the expected field names
            $mappedDevice = [
                'id' => $device['id'],
                'manufacturer' => $device['manufacturer'] ?? 'Unknown',
                'model' => $device['model_name'] ?? 'Unknown Model',
                'serialNumber' => $device['serial_number'] ?? 'Unknown',
                'ipAddress' => $device['ip_address'] ?? 'N/A',
                'lastContact' => $device['last_contact'] ?? date('Y-m-d H:i:s'),
                'status' => $device['status'] ?? 'unknown',
                'ssid' => $device['ssid'] ?? '',
                'connectedClients' => $device['connected_clients'] ?? 0,
                'uptime' => $device['uptime'] ?? '0',
                'softwareVersion' => $device['software_version'] ?? 'N/A',
                'hardwareVersion' => $device['hardware_version'] ?? 'N/A',
                'txPower' => $device['tx_power'] ?? 'N/A',
                'rxPower' => $device['rx_power'] ?? 'N/A'
            ];
            
            // Update status based on last contact time
            $fiveMinutesAgo = date('Y-m-d H:i:s', strtotime('-5 minutes'));
            if ($device['last_contact'] && strtotime($device['last_contact']) >= strtotime($fiveMinutesAgo)) {
                // Device has checked in within the last 5 minutes
                if ($mappedDevice['status'] !== 'online') {
                    $mappedDevice['status'] = 'online';
                    // Update database to reflect this
                    updateDeviceStatusInDb($db, $device['id'], 'online');
                    logDetail("Updated device status to online: ID={$device['id']}, Serial={$device['serial_number']}");
                }
            } else if ($mappedDevice['status'] !== 'offline' && $device['last_contact']) {
                // Device hasn't checked in within 5 minutes
                $mappedDevice['status'] = 'offline';
                // Update database to reflect this
                updateDeviceStatusInDb($db, $device['id'], 'offline');
                logDetail("Updated device status to offline: ID={$device['id']}, Serial={$device['serial_number']}");
            }
            
            logAction("Retrieved device details for ID: $id");
            return $mappedDevice;
        }
        
        logAction("Device not found with ID: $id", "WARNING");
        return null;
    } catch (PDOException $e) {
        logError("Database error in getDevice(): " . $e->getMessage());
        return null;
    }
}

// Function to get pending tasks for a device
function getPendingTasks($db, $deviceId) {
    try {
        $sql = "SELECT * FROM device_tasks WHERE device_id = :device_id AND status = 'pending' ORDER BY created_at ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':device_id' => $deviceId]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        logAction("Retrieved " . count($tasks) . " pending tasks for device ID: $deviceId");
        return $tasks;
    } catch (PDOException $e) {
        logError("Database error in getPendingTasks(): " . $e->getMessage());
        return [];
    }
}

// Function to process pending tasks for a device
function processDeviceTasks($db, $deviceId, $deviceSerial) {
    try {
        // Get pending tasks
        $tasks = getPendingTasks($db, $deviceId);
        
        if (empty($tasks)) {
            logAction("No pending tasks to process for device: $deviceSerial");
            return true; // No tasks to process
        }
        
        logAction("Processing " . count($tasks) . " pending tasks for device: $deviceSerial");
        
        foreach ($tasks as $task) {
            $taskData = json_decode($task['task_data'], true);
            $success = false;
            $message = '';
            
            // Process based on task type
            switch ($task['task_type']) {
                case 'wifi':
                    // Handle WiFi configuration
                    logAction("Processing WiFi task: " . json_encode($taskData));
                    
                    $ssid = $taskData['ssid'] ?? '';
                    $password = $taskData['password'] ?? '';
                    $isHuawei = $taskData['is_huawei'] ?? false;
                    
                    // Log detailed WiFi parameters for debugging
                    if (!empty($ssid)) {
                        logAction("Setting SSID: $ssid");
                    }
                    
                    if (!empty($password)) {
                        $passwordLength = strlen($password);
                        logAction("Setting WiFi password (length: $passwordLength)");
                        if ($isHuawei) {
                            logAction("Using Huawei-specific parameter path for password");
                        }
                    }
                    
                    $success = true;
                    $message = "WiFi configuration applied";
                    break;
                
                case 'wan':
                    // Handle WAN configuration
                    logAction("Processing WAN task: " . json_encode($taskData));
                    
                    $ipAddress = $taskData['ip_address'] ?? '';
                    $gateway = $taskData['gateway'] ?? '';
                    
                    if (!empty($ipAddress)) {
                        logAction("Setting IP Address: $ipAddress");
                    }
                    
                    if (!empty($gateway)) {
                        logAction("Setting Gateway: $gateway");
                    }
                    
                    $success = true;
                    $message = "WAN configuration applied";
                    break;
                
                case 'reboot':
                    // Handle device reboot
                    logAction("Processing reboot task for device: $deviceSerial");
                    $success = true;
                    $message = "Reboot command sent";
                    break;
                
                default:
                    $message = "Unknown task type: " . $task['task_type'];
                    logAction($message, "WARNING");
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
            
            logAction("Task {$task['id']} marked as " . ($success ? "completed" : "failed") . ": $message");
            
            // Update device last_contact time
            $updateDeviceSql = "UPDATE devices SET last_contact = NOW(), status = 'online' WHERE id = :id";
            $updateDeviceStmt = $db->prepare($updateDeviceSql);
            $updateDeviceStmt->execute([':id' => $deviceId]);
            logAction("Updated device last_contact time for device: $deviceSerial");
        }
        
        return true;
    } catch (Exception $e) {
        logError("Error processing tasks: " . $e->getMessage());
        return false;
    }
}

// Function to check device status and update it
function updateDeviceStatus($db, $deviceId) {
    try {
        // Get device info
        $sql = "SELECT * FROM devices WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => $deviceId]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$device) {
            logAction("Device not found with ID: $deviceId", "WARNING");
            return false;
        }
        
        // Check last contact time (10 minutes threshold)
        $tenMinutesAgo = date('Y-m-d H:i:s', strtotime('-10 minutes'));
        $isOnline = $device['last_contact'] && strtotime($device['last_contact']) >= strtotime($tenMinutesAgo);
        $newStatus = $isOnline ? 'online' : 'offline';
        
        // Update status if changed
        if ($device['status'] !== $newStatus) {
            $updateSql = "UPDATE devices SET status = :status WHERE id = :id";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute([
                ':status' => $newStatus,
                ':id' => $deviceId
            ]);
            
            logAction("Updated device status from {$device['status']} to {$newStatus} for device: {$device['serial_number']}");
        }
        
        return true;
    } catch (PDOException $e) {
        logError("Database error in updateDeviceStatus(): " . $e->getMessage());
        return false;
    }
}

// Function to log errors
function logError($message) {
    $logFile = __DIR__ . '/../../error.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "$timestamp - [ERROR] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Function to log actions
function logAction($message, $level = 'INFO') {
    $logFile = __DIR__ . '/../../device.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "$timestamp - [$level] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}
?>
