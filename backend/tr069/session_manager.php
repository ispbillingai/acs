
<?php
class SessionManager {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function createSession($deviceSerial, $sessionId) {
        try {
            $stmt = $this->db->prepare("INSERT INTO tr069_sessions (device_serial, session_id, created_at, last_activity) VALUES (:device_serial, :session_id, NOW(), NOW())");
            $stmt->execute([
                ':device_serial' => $deviceSerial,
                ':session_id' => $sessionId
            ]);
            
            // Also ensure the device is marked as online
            $this->updateDeviceStatus($deviceSerial, 'online');
            
            // Log session creation
            $this->logAction("Created new TR-069 session for device: $deviceSerial");
            
            return true;
        } catch (PDOException $e) {
            $this->logAction("Error creating session: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    public function validateSession($sessionId) {
        try {
            // Get session that's less than 30 minutes old
            $stmt = $this->db->prepare("SELECT * FROM tr069_sessions WHERE session_id = :session_id AND created_at > NOW() - INTERVAL 30 MINUTE");
            $stmt->execute([':session_id' => $sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($session) {
                // Update the device status to online
                $this->updateDeviceStatus($session['device_serial'], 'online');
                $this->logAction("Validated session for device: {$session['device_serial']}");
            } else {
                $this->logAction("Session validation failed for ID: $sessionId", 'WARNING');
            }
            
            return $session;
        } catch (PDOException $e) {
            $this->logAction("Error validating session: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    public function updateOrCreateSession($deviceSerial, $sessionId) {
        try {
            // Check if session exists
            $stmt = $this->db->prepare("SELECT id FROM tr069_sessions WHERE session_id = :session_id");
            $stmt->execute([':session_id' => $sessionId]);
            $sessionExists = $stmt->fetchColumn();

            if ($sessionExists) {
                // Update existing session
                $stmt = $this->db->prepare("UPDATE tr069_sessions SET last_activity = NOW(), device_serial = :device_serial WHERE session_id = :session_id");
                $stmt->execute([
                    ':device_serial' => $deviceSerial,
                    ':session_id' => $sessionId
                ]);
                $this->logAction("Updated existing session for device: $deviceSerial");
            } else {
                // Create new session
                $this->createSession($deviceSerial, $sessionId);
            }
            
            // Also update the device status
            $this->updateDeviceStatus($deviceSerial, 'online');
            
            return true;
        } catch (PDOException $e) {
            $this->logAction("Error updating/creating session: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    public function updateDeviceStatus($serialNumber, $status) {
        try {
            // First try to update existing device
            $stmt = $this->db->prepare("UPDATE devices SET status = :status, last_contact = NOW() WHERE serial_number = :serial_number");
            $stmt->execute([
                ':status' => $status,
                ':serial_number' => $serialNumber
            ]);
            
            // If no rows were affected, the device might not exist yet
            if ($stmt->rowCount() === 0) {
                // Check if device exists
                $checkStmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial_number");
                $checkStmt->execute([':serial_number' => $serialNumber]);
                $exists = $checkStmt->fetch();
                
                // If device doesn't exist, create it
                if (!$exists) {
                    $insertStmt = $this->db->prepare("INSERT INTO devices (serial_number, status, last_contact) VALUES (:serial_number, :status, NOW())");
                    $insertStmt->execute([
                        ':serial_number' => $serialNumber,
                        ':status' => $status
                    ]);
                    $this->logAction("Created new device record: $serialNumber with status $status");
                } else {
                    // If device exists but no rows were affected, log this anomaly
                    $this->logAction("Device exists but status update had no effect: $serialNumber", 'WARNING');
                    
                    // Force update with additional logging
                    $forceStmt = $this->db->prepare("UPDATE devices SET status = :status, last_contact = NOW() WHERE serial_number = :serial_number");
                    $forceStmt->execute([
                        ':status' => $status,
                        ':serial_number' => $serialNumber
                    ]);
                    $this->logAction("Forced status update for device: $serialNumber to $status");
                }
            } else {
                $this->logAction("Updated device status: $serialNumber is now $status");
            }
            
            // Double-check device status to make sure it was updated
            $verifyStmt = $this->db->prepare("SELECT status FROM devices WHERE serial_number = :serial_number");
            $verifyStmt->execute([':serial_number' => $serialNumber]);
            $currentStatus = $verifyStmt->fetchColumn();
            
            if ($currentStatus !== $status) {
                $this->logAction("Status verification failed! $serialNumber shows as $currentStatus but should be $status", 'ERROR');
                
                // Try one more time with a different query
                $finalStmt = $this->db->prepare("UPDATE devices SET status = :status, last_contact = NOW() WHERE serial_number = :serial_number");
                $finalStmt->execute([
                    ':status' => $status,
                    ':serial_number' => $serialNumber
                ]);
                $this->logAction("Final attempt to update status for $serialNumber to $status");
            }
            
            return true;
        } catch (PDOException $e) {
            $this->logAction("DATABASE ERROR in updateDeviceStatus: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    // Process pending tasks for a device
    public function processDeviceTasks($deviceSerial) {
        try {
            // First get the device ID from serial number
            $deviceStmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial_number");
            $deviceStmt->execute([':serial_number' => $deviceSerial]);
            $deviceId = $deviceStmt->fetchColumn();
            
            if (!$deviceId) {
                $this->logAction("Cannot process tasks: Device with serial $deviceSerial not found in database", 'WARNING');
                return false;
            }
            
            // Get pending tasks for this device
            $taskStmt = $this->db->prepare("SELECT * FROM device_tasks WHERE device_id = :device_id AND status = 'pending' ORDER BY created_at ASC");
            $taskStmt->execute([':device_id' => $deviceId]);
            $tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($tasks)) {
                // No tasks to process
                return true;
            }
            
            $this->logAction("Found " . count($tasks) . " pending tasks for device $deviceSerial");
            
            foreach ($tasks as $task) {
                $this->logAction("Processing task ID: {$task['id']}, Type: {$task['task_type']} for device $deviceSerial");
                
                $taskData = json_decode($task['task_data'], true);
                $success = false;
                $message = '';
                
                // Process based on task type
                switch ($task['task_type']) {
                    case 'wifi':
                        $success = $this->processWifiTask($deviceSerial, $taskData);
                        $message = $success ? "WiFi configuration applied" : "Failed to apply WiFi configuration";
                        break;
                    
                    case 'wan':
                        $success = $this->processWanTask($deviceSerial, $taskData);
                        $message = $success ? "WAN configuration applied" : "Failed to apply WAN configuration";
                        break;
                    
                    case 'reboot':
                        $success = $this->processRebootTask($deviceSerial);
                        $message = $success ? "Reboot command sent" : "Failed to send reboot command";
                        break;
                    
                    default:
                        $message = "Unknown task type: {$task['task_type']}";
                        $this->logAction($message, 'WARNING');
                        break;
                }
                
                // Update task status
                $updateStmt = $this->db->prepare("UPDATE device_tasks SET status = :status, message = :message, updated_at = NOW() WHERE id = :id");
                $updateStmt->execute([
                    ':status' => $success ? 'completed' : 'failed',
                    ':message' => $message,
                    ':id' => $task['id']
                ]);
                
                $this->logAction("Task {$task['id']} marked as " . ($success ? 'completed' : 'failed') . ": $message");
            }
            
            return true;
        } catch (Exception $e) {
            $this->logAction("Error processing tasks: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    private function processWifiTask($deviceSerial, $taskData) {
        $this->logAction("Processing WiFi task for device $deviceSerial: " . json_encode($taskData));
        
        try {
            // Extract task data
            $ssid = $taskData['ssid'] ?? '';
            $password = $taskData['password'] ?? '';
            $isHuawei = $taskData['is_huawei'] ?? false;
            
            if (empty($ssid)) {
                $this->logAction("WiFi task missing SSID", 'ERROR');
                return false;
            }
            
            // Set up parameter list
            $parameterList = [
                [
                    'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                    'value' => $ssid,
                    'type' => 'xsd:string'
                ]
            ];
            
            // Add password with proper path based on device model
            if (!empty($password)) {
                if ($isHuawei) {
                    $this->logAction("Using Huawei-specific password parameter path");
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
            
            // In a real implementation, this would use the TR-069 ACS API
            // For this demo, we'll simulate a successful operation
            $this->logAction("Setting WiFi parameters: SSID=$ssid, Password=" . (empty($password) ? "unchanged" : "changed"));
            
            // Update device data in database for consistency
            $deviceStmt = $this->db->prepare("UPDATE devices SET ssid = :ssid" . (!empty($password) ? ", ssid_password = :password" : "") . " WHERE serial_number = :serial_number");
            $params = [':ssid' => $ssid, ':serial_number' => $deviceSerial];
            if (!empty($password)) {
                $params[':password'] = $password;
            }
            $deviceStmt->execute($params);
            
            $this->logAction("WiFi task processed successfully for device $deviceSerial");
            return true;
        } catch (Exception $e) {
            $this->logAction("Error processing WiFi task: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    private function processWanTask($deviceSerial, $taskData) {
        $this->logAction("Processing WAN task for device $deviceSerial: " . json_encode($taskData));
        
        try {
            // Extract task data
            $ipAddress = $taskData['ip_address'] ?? '';
            $gateway = $taskData['gateway'] ?? '';
            
            if (empty($ipAddress)) {
                $this->logAction("WAN task missing IP address", 'ERROR');
                return false;
            }
            
            // Set up parameter list
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
            
            // In a real implementation, this would use the TR-069 ACS API
            // For this demo, we'll simulate a successful operation
            $this->logAction("Setting WAN parameters: IP=$ipAddress, Gateway=" . (empty($gateway) ? "unchanged" : $gateway));
            
            // Update device data in database for consistency
            $deviceStmt = $this->db->prepare("UPDATE devices SET ip_address = :ip_address WHERE serial_number = :serial_number");
            $deviceStmt->execute([':ip_address' => $ipAddress, ':serial_number' => $deviceSerial]);
            
            $this->logAction("WAN task processed successfully for device $deviceSerial");
            return true;
        } catch (Exception $e) {
            $this->logAction("Error processing WAN task: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    private function processRebootTask($deviceSerial) {
        $this->logAction("Processing reboot task for device $deviceSerial");
        
        try {
            // In a real implementation, this would use the TR-069 ACS API
            // For this demo, we'll simulate a successful operation
            $this->logAction("Sending reboot command to device $deviceSerial");
            
            // Simulated success
            $this->logAction("Reboot command sent successfully to device $deviceSerial");
            return true;
        } catch (Exception $e) {
            $this->logAction("Error processing reboot task: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    private function logAction($message, $level = 'INFO') {
        $logFile = __DIR__ . '/../../tr069.log';
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "$timestamp - [$level] $message\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}
?>
