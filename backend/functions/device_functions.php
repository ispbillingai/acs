
<?php
// Function to retrieve all devices from the database
function getDevices($db) {
    try {
        $sql = "SELECT d.*, 
                (SELECT COUNT(*) FROM device_tasks WHERE device_id = d.id AND status = 'pending') as pending_tasks 
                FROM devices d ORDER BY d.last_contact DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        logError("Database error in getDevices(): " . $e->getMessage());
        return [];
    }
}

// Function to get a single device by ID
function getDevice($db, $id) {
    try {
        $sql = "SELECT * FROM devices WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
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
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                    // Simulate setting WiFi parameters
                    logAction("Processing WiFi task: " . json_encode($taskData));
                    $success = true;
                    $message = "WiFi configuration applied";
                    break;
                
                case 'wan':
                    // Simulate setting WAN parameters
                    logAction("Processing WAN task: " . json_encode($taskData));
                    $success = true;
                    $message = "WAN configuration applied";
                    break;
                
                case 'reboot':
                    // Simulate device reboot
                    logAction("Processing reboot task");
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
        }
        
        return true;
    } catch (Exception $e) {
        logError("Error processing tasks: " . $e->getMessage());
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
