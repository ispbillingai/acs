<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/utils/WifiTaskGenerator.php';
require_once __DIR__ . '/utils/WanTaskGenerator.php';
require_once __DIR__ . '/utils/RebootTaskGenerator.php';
require_once __DIR__ . '/utils/InfoTaskGenerator.php';
require_once __DIR__ . '/utils/CommitHelper.php';

class TaskHandler {
    private $db;
    private $logFile;
    private $wifiTaskGenerator;
    private $wanTaskGenerator;
    private $rebootTaskGenerator;
    private $infoTaskGenerator;
    private $commitHelper;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->logFile = $_SERVER['DOCUMENT_ROOT'] . '/device.log';
        
        if (!file_exists(dirname($this->logFile))) {
            mkdir(dirname($this->logFile), 0755, true);
        }
        
        $this->wifiTaskGenerator = new WifiTaskGenerator($this);
        $this->wanTaskGenerator = new WanTaskGenerator($this);
        $this->rebootTaskGenerator = new RebootTaskGenerator($this);
        $this->infoTaskGenerator = new InfoTaskGenerator($this);
        $this->commitHelper = new CommitHelper($this);
    }
    
    public function logToFile($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [TR-069] {$message}" . PHP_EOL;
        
        error_log("[TR-069] {$message}", 0);
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }
    
    public function getPendingTasks($deviceSerialNumber) {
        try {
            $deviceStmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial_number");
            $deviceStmt->execute([':serial_number' => $deviceSerialNumber]);
            $deviceId = $deviceStmt->fetchColumn();
            
            if (!$deviceId) {
                $this->logToFile("No device found with serial number: $deviceSerialNumber");
                return [];
            }
            
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
                return $this->wifiTaskGenerator->generateParameters($data);
            case 'wan':
                return $this->wanTaskGenerator->generateParameters($data);
            case 'pppoe':
                return $this->wanTaskGenerator->generateParameters($data);
            case 'reboot':
                return $this->rebootTaskGenerator->generateParameters($data);
            case 'info':
                return $this->infoTaskGenerator->generateParameters($data);
            default:
                $this->logToFile("Unsupported task type: $taskType");
                return null;
        }
    }
    
    public function createFollowUpInfoTask($deviceId, $hostCount) {
        try {
            $taskData = json_encode(['host_count' => $hostCount]);
            
            $this->logToFile("Creating follow-up info task for host details with host count: $hostCount");
            
            $sql = "INSERT INTO device_tasks 
                    (device_id, task_type, task_data, status, message, created_at, updated_at) 
                    VALUES 
                    (:device_id, 'info', :task_data, 'pending', 'Auto-created follow-up for host details', NOW(), NOW())";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':device_id' => $deviceId,
                ':task_data' => $taskData
            ]);
            
            $taskId = $this->db->lastInsertId();
            $this->logToFile("Created follow-up info task with ID: $taskId");
            
            return $taskId;
        } catch (PDOException $e) {
            $this->logToFile("Database error creating follow-up info task: " . $e->getMessage());
            return false;
        }
    }
    
    public function getDeviceIdFromSerialNumber($serialNumber) {
        try {
            $stmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
            $stmt->execute([':serial' => $serialNumber]);
            return $stmt->fetchColumn();
        } catch (PDOException $e) {
            $this->logToFile("Database error in getDeviceIdFromSerialNumber: " . $e->getMessage());
            return null;
        }
    }
    
    public function getCommitHelper() {
        return $this->commitHelper;
    }
}
?>