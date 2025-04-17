
<?php
// Device-related functions

/**
 * Get all devices from the database
 * 
 * @param PDO $db Database connection
 * @return array Array of devices
 */
function getDevices($db) {
    try {
        $sql = "SELECT 
                d.id,
                d.serial_number as serialNumber,
                d.manufacturer,
                d.model_name as model,
                d.status,
                d.last_contact as lastContact,
                d.ip_address as ipAddress,
                d.software_version as softwareVersion,
                d.hardware_version as hardwareVersion,
                d.ssid,
                d.ssid_password as ssidPassword
                FROM devices d
                ORDER BY d.last_contact DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute();
        
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Log success
        error_log("Retrieved " . count($devices) . " devices from database");
        
        return $devices;
    } catch (PDOException $e) {
        error_log("Error fetching devices: " . $e->getMessage());
        return [];
    }
}

/**
 * Get a specific device by ID
 * 
 * @param PDO $db Database connection
 * @param int $deviceId Device ID
 * @return array|null Device data or null if not found
 */
function getDeviceById($db, $deviceId) {
    try {
        $sql = "SELECT 
                d.id,
                d.serial_number as serialNumber,
                d.manufacturer,
                d.model_name as model,
                d.status,
                d.last_contact as lastContact,
                d.ip_address as ipAddress,
                d.software_version as softwareVersion,
                d.hardware_version as hardwareVersion,
                d.ssid,
                d.ssid_password as ssidPassword
                FROM devices d
                WHERE d.id = :id";
        
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':id', $deviceId, PDO::PARAM_INT);
        $stmt->execute();
        
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($device) {
            error_log("Retrieved device ID: {$deviceId} successfully");
            return $device;
        } else {
            error_log("Device ID: {$deviceId} not found");
            return null;
        }
    } catch (PDOException $e) {
        error_log("Error fetching device ID {$deviceId}: " . $e->getMessage());
        return null;
    }
}

/**
 * Set device parameters through TR-069
 * 
 * @param PDO $db Database connection
 * @param int $deviceId Device ID
 * @param array $parameters Array of parameters to set
 * @return array Status and message
 */
function setDeviceParameters($db, $deviceId, $parameters) {
    try {
        // Log the operation
        error_log("Attempting to set parameters for device: {$deviceId}");
        
        // Get device serial number
        $stmt = $db->prepare("SELECT serial_number FROM devices WHERE id = :id");
        $stmt->bindParam(':id', $deviceId, PDO::PARAM_INT);
        $stmt->execute();
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$device) {
            error_log("Device ID {$deviceId} not found when setting parameters");
            return [
                'success' => false,
                'message' => 'Device not found'
            ];
        }
        
        $serialNumber = $device['serial_number'];
        
        // For now, simulate successful parameter setting
        // In a real implementation, this would communicate with the TR-069 server
        error_log("Parameters successfully set for device: {$serialNumber}");
        
        // Update the device record if there are changes to be made
        if (isset($parameters['ssid']) || isset($parameters['password'])) {
            $updates = [];
            $params = [];
            
            if (isset($parameters['ssid'])) {
                $updates[] = "ssid = :ssid";
                $params[':ssid'] = $parameters['ssid'];
            }
            
            if (isset($parameters['password'])) {
                $updates[] = "ssid_password = :password";
                $params[':password'] = $parameters['password'];
            }
            
            if (!empty($updates)) {
                $params[':id'] = $deviceId;
                $updateSql = "UPDATE devices SET " . implode(", ", $updates) . " WHERE id = :id";
                $updateStmt = $db->prepare($updateSql);
                $updateStmt->execute($params);
                
                error_log("Updated device record in database for ID: {$deviceId}");
            }
        }
        
        return [
            'success' => true,
            'message' => 'Parameters set successfully'
        ];
    } catch (PDOException $e) {
        error_log("Database error when setting parameters: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    } catch (Exception $e) {
        error_log("Error setting parameters: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
}

/**
 * Get pending tasks for a device by serial number
 * 
 * @param PDO $db Database connection
 * @param string $serialNumber Device serial number
 * @return array Array of pending tasks
 */
function getPendingTasksBySerial($db, $serialNumber) {
    try {
        // First get the device ID
        $deviceStmt = $db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
        $deviceStmt->bindParam(':serial', $serialNumber, PDO::PARAM_STR);
        $deviceStmt->execute();
        $device = $deviceStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$device) {
            error_log("No device found with serial number: {$serialNumber}");
            return [];
        }
        
        $deviceId = $device['id'];
        
        // Get pending tasks
        $sql = "SELECT * FROM device_tasks 
                WHERE device_id = :device_id 
                AND status = 'pending' 
                ORDER BY created_at ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':device_id', $deviceId, PDO::PARAM_INT);
        $stmt->execute();
        
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($tasks) > 0) {
            error_log("Found " . count($tasks) . " pending tasks for device with serial: {$serialNumber}");
        } else {
            error_log("No pending tasks found for device with serial: {$serialNumber}");
        }
        
        return $tasks;
    } catch (PDOException $e) {
        error_log("Error fetching pending tasks: " . $e->getMessage());
        return [];
    }
}

/**
 * Update task status
 * 
 * @param PDO $db Database connection
 * @param int $taskId Task ID
 * @param string $status New status
 * @param string $message Status message
 * @return bool Success or failure
 */
function updateTaskStatus($db, $taskId, $status, $message = '') {
    try {
        $sql = "UPDATE device_tasks 
                SET status = :status, 
                    message = :message, 
                    updated_at = NOW() 
                WHERE id = :id";
        
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':status', $status, PDO::PARAM_STR);
        $stmt->bindParam(':message', $message, PDO::PARAM_STR);
        $stmt->bindParam(':id', $taskId, PDO::PARAM_INT);
        $stmt->execute();
        
        error_log("Updated task #{$taskId} status to: {$status}");
        
        return true;
    } catch (PDOException $e) {
        error_log("Error updating task status: " . $e->getMessage());
        return false;
    }
}
