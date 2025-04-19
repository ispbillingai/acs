<?php

function createPendingInfoTask($deviceId, $db) {
    try {
        tr069_log("TASK CREATION: Starting task creation for device ID: $deviceId", "INFO");
        
        // Check for existing in-progress or pending tasks
        $checkStmt = $db->prepare("
            SELECT id, task_type FROM device_tasks 
            WHERE device_id = :device_id AND status IN ('in_progress', 'pending') 
            LIMIT 1
        ");
        $checkStmt->execute([':device_id' => $deviceId]);
        $existingTask = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingTask) {
            tr069_log("TASK CREATION: Found existing task ID: {$existingTask['id']} ({$existingTask['task_type']}), skipping new task creation", "INFO");
            return false;
        }
        
        // Define parameter groups
        $parameterGroups = [
            [
                'task_type' => 'info',
                'group' => 'Core',
                'parameters' => [
                    'InternetGatewayDevice.DeviceInfo.HardwareVersion',
                    'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
                    'InternetGatewayDevice.DeviceInfo.UpTime',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                    'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries'
                ]
            ],
            [
                'task_type' => 'info_group',
                'group' => 'PPPOE DETAILS',
                'parameters' => [
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.DefaultGateway',
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username',
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.ExternalIPAddress'
                ]
            ],
            [
                'task_type' => 'info_group',
                'group' => 'GPON',
                'parameters' => [
                    'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.TXPower',
                    'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.RXPower'
                ]
            ]
        ];
        
        $createdTasks = [];
        
        foreach ($parameterGroups as $group) {
            $taskData = json_encode([
                'group' => $group['group'],
                'parameters' => $group['parameters']
            ]);
            
            $insertStmt = $db->prepare("
                INSERT INTO device_tasks 
                    (device_id, task_type, task_data, status, message, created_at, updated_at) 
                VALUES 
                    (:device_id, :task_type, :task_data, 'pending', 'Auto-created for {$group['group']} parameters', NOW(), NOW())
            ");
            
            $insertResult = $insertStmt->execute([
                ':device_id' => $deviceId,
                ':task_type' => $group['task_type'],
                ':task_data' => $taskData
            ]);
            
            if ($insertResult) {
                $taskId = $db->lastInsertId();
                $createdTasks[] = $taskId;
                tr069_log("TASK CREATION: Successfully created {$group['task_type']} task with ID: $taskId for group: {$group['group']}", "INFO");
                
                $verifyStmt = $db->prepare("SELECT * FROM device_tasks WHERE id = :id");
                $verifyStmt->execute([':id' => $taskId]);
                if ($verifyStmt->fetch(PDO::FETCH_ASSOC)) {
                    tr069_log("TASK CREATION: Verified task exists in database with ID: $taskId", "INFO");
                } else {
                    tr069_log("TASK CREATION ERROR: Task not found in database after creation for ID: $taskId", "ERROR");
                }
            } else {
                tr069_log("TASK CREATION ERROR: Failed to create {$group['task_type']} task for group: {$group['group']}. Database error: " . print_r($insertStmt->errorInfo(), true), "ERROR");
            }
        }
        
        return !empty($createdTasks);
    } catch (PDOException $e) {
        tr069_log("TASK CREATION ERROR: Exception: " . $e->getMessage(), "ERROR");
        return false;
    }
}

?>