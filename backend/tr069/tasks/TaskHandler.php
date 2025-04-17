<?php
require_once __DIR__ . '/../../config/database.php';

class TaskHandler {
    private $db;
    private $logFile;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->logFile = $_SERVER['DOCUMENT_ROOT'] . '/device.log';
        
        // Make sure log directory exists
        if (!file_exists(dirname($this->logFile))) {
            mkdir(dirname($this->logFile), 0755, true);
        }
    }
    
    // Log to device.log file
    private function logToFile($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [TR-069] {$message}" . PHP_EOL;
        
        // Log to Apache error log as backup
        error_log("[TR-069] {$message}", 0);
        
        // Log to dedicated device.log file
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }
    
    public function getPendingTasks($deviceSerialNumber) {
        try {
            // Get device ID from serial number
            $deviceStmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial_number");
            $deviceStmt->execute([':serial_number' => $deviceSerialNumber]);
            $deviceId = $deviceStmt->fetchColumn();
            
            if (!$deviceId) {
                $this->logToFile("No device found with serial number: $deviceSerialNumber");
                return [];
            }
            
            // Get pending tasks for this device
            $stmt = $this->db->prepare("
                SELECT * FROM device_tasks 
                WHERE device_id = :device_id 
                AND status = 'pending' 
                ORDER BY created_at ASC"
            );
            $stmt->execute([':device_id' => $deviceId]);
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($tasks)) {
                $this->logToFile("Found " . count($tasks) . " pending tasks for device ID: $deviceId");
                
                // Log details of each task
                foreach ($tasks as $task) {
                    $this->logToFile("Task ID: {$task['id']} - Type: {$task['task_type']} - Data: {$task['task_data']}");
                }
            } else {
                $this->logToFile("No pending tasks for device ID: $deviceId");
            }
            
            return $tasks;
        } catch (PDOException $e) {
            $this->logToFile("Database error in getPendingTasks: " . $e->getMessage());
            return [];
        }
    }
    
    public function updateTaskStatus($taskId, $status, $message = null) {
        try {
            $sql = "UPDATE device_tasks SET 
                    status = :status, 
                    message = :message,
                    updated_at = NOW() 
                    WHERE id = :id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':status' => $status,
                ':message' => $message,
                ':id' => $taskId
            ]);
            
            $this->logToFile("Updated task ID: $taskId - Status: $status - Message: $message");
            return true;
        } catch (PDOException $e) {
            $this->logToFile("Database error in updateTaskStatus: " . $e->getMessage());
            return false;
        }
    }
    
    public function generateParameterValues($taskType, $taskData) {
        $data = json_decode($taskData, true);
        
        if (!$data) {
            $this->logToFile("Invalid task data JSON: $taskData");
            return null;
        }
        
        $this->logToFile("Generating parameters for task type: $taskType with data: $taskData");
        
        switch ($taskType) {
            case 'wifi':
                return $this->generateWifiParameters($data);
            case 'wan':
                return $this->generateWanParameters($data);
            case 'reboot':
                return $this->generateRebootCommand($data);
            default:
                $this->logToFile("Unsupported task type: $taskType");
                return null;
        }
    }
    
    private function generateWifiParameters($data) {
        $ssid = $data['ssid'] ?? null;
        $password = $data['password'] ?? null;
        
        if (!$ssid) {
            $this->logToFile("SSID is required for WiFi configuration");
            return null;
        }
        
        $parameters = [
            [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                'value' => $ssid,
                'type' => 'xsd:string'
            ]
        ];
        
        // Only add password parameter if provided
        if ($password) {
            // Try both common password parameter paths
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
                'value' => $password,
                'type' => 'xsd:string'
            ];
            
            // Some devices use PreSharedKey instead of KeyPassphrase
            $parameters[] = [
                'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey',
                'value' => $password,
                'type' => 'xsd:string'
            ];
        }
        
        $this->logToFile("Generated WiFi parameters - SSID: $ssid, Password length: " . 
                 ($password ? strlen($password) : 0) . " chars");
        
        return [
            'method' => 'SetParameterValues',
            'parameters' => $parameters
        ];
    }
    
    private function generateWanParameters($data) {
        $ipAddress = $data['ip_address'] ?? null;
        $gateway = $data['gateway'] ?? null;
        
        if (!$ipAddress) {
            $this->logToFile("IP Address is required for WAN configuration");
            return null;
        }
        
        $parameters = [
            [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
                'value' => $ipAddress,
                'type' => 'xsd:string'
            ]
        ];
        
        if ($gateway) {
            $parameters[] = [
                'name' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DefaultGateway',
                'value' => $gateway,
                'type' => 'xsd:string'
            ];
        }
        
        $this->logToFile("Generated WAN parameters - IP: $ipAddress, Gateway: $gateway");
        
        return [
            'method' => 'SetParameterValues',
            'parameters' => $parameters
        ];
    }
    
    private function generateRebootCommand($data) {
        $reason = $data['reboot_reason'] ?? 'User initiated reboot';
        $commandKey = 'Reboot-' . substr(md5(time()), 0, 8);
        
        $this->logToFile("Generated Reboot command with reason: $reason, CommandKey: $commandKey");
        
        return [
            'method' => 'Reboot',
            'commandKey' => $commandKey
        ];
    }
}
