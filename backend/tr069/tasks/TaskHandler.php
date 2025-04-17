
<?php
require_once __DIR__ . '/../../config/database.php';

class TaskHandler {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    public function getPendingTasks($deviceSerialNumber) {
        try {
            // Get device ID from serial number
            $deviceStmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial_number");
            $deviceStmt->execute([':serial_number' => $deviceSerialNumber]);
            $deviceId = $deviceStmt->fetchColumn();
            
            if (!$deviceId) {
                error_log("[TR-069] No device found with serial number: $deviceSerialNumber", 0);
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
                error_log("[TR-069] Found " . count($tasks) . " pending tasks for device ID: $deviceId", 0);
            }
            
            return $tasks;
        } catch (PDOException $e) {
            error_log("[TR-069] Database error in getPendingTasks: " . $e->getMessage(), 0);
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
            
            error_log("[TR-069] Updated task ID: $taskId - Status: $status - Message: $message", 0);
            return true;
        } catch (PDOException $e) {
            error_log("[TR-069] Database error in updateTaskStatus: " . $e->getMessage(), 0);
            return false;
        }
    }
    
    public function generateParameterValues($taskType, $taskData) {
        $data = json_decode($taskData, true);
        
        if (!$data) {
            error_log("[TR-069] Invalid task data JSON: $taskData", 0);
            return null;
        }
        
        switch ($taskType) {
            case 'wifi':
                return $this->generateWifiParameters($data);
            case 'wan':
                return $this->generateWanParameters($data);
            case 'reboot':
                return $this->generateRebootCommand();
            default:
                error_log("[TR-069] Unsupported task type: $taskType", 0);
                return null;
        }
    }
    
    private function generateWifiParameters($data) {
        $ssid = $data['ssid'] ?? null;
        $password = $data['password'] ?? null;
        
        if (!$ssid) {
            error_log("[TR-069] SSID is required for WiFi configuration", 0);
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
        
        error_log("[TR-069] Generated WiFi parameters - SSID: $ssid, Password length: " . 
                 ($password ? strlen($password) : 0) . " chars", 0);
        
        return [
            'method' => 'SetParameterValues',
            'parameters' => $parameters
        ];
    }
    
    private function generateWanParameters($data) {
        $ipAddress = $data['ip_address'] ?? null;
        $gateway = $data['gateway'] ?? null;
        
        if (!$ipAddress) {
            error_log("[TR-069] IP Address is required for WAN configuration", 0);
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
        
        error_log("[TR-069] Generated WAN parameters - IP: $ipAddress, Gateway: $gateway", 0);
        
        return [
            'method' => 'SetParameterValues',
            'parameters' => $parameters
        ];
    }
    
    private function generateRebootCommand() {
        error_log("[TR-069] Generated Reboot command", 0);
        
        return [
            'method' => 'Reboot',
            'commandKey' => 'Reboot-' . substr(md5(time()), 0, 8)
        ];
    }
}
