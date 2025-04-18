
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
        //working now
        // Make sure log directory exists
        if (!file_exists(dirname($this->logFile))) {
            mkdir(dirname($this->logFile), 0755, true);
        }
        
        // Initialize task generators
        $this->wifiTaskGenerator = new WifiTaskGenerator($this);
        $this->wanTaskGenerator = new WanTaskGenerator($this);
        $this->rebootTaskGenerator = new RebootTaskGenerator($this);
        $this->infoTaskGenerator = new InfoTaskGenerator($this);
        $this->commitHelper = new CommitHelper($this);
    }
    
    // Log to device.log file
    public function logToFile($message) {
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
                
                // If no pending tasks, let's create an info task
                $infoTaskData = json_encode(['host_count' => 10]); // Request info for up to 10 hosts
                
                // Create the info task
                $insertStmt = $this->db->prepare("
                    INSERT INTO device_tasks (device_id, task_type, task_data, status, message, created_at, updated_at)
                    VALUES (:device_id, 'info', :task_data, 'pending', 'Auto-created on device checking for tasks', NOW(), NOW())
                ");
                
                $insertResult = $insertStmt->execute([
                    ':device_id' => $deviceId,
                    ':task_data' => $infoTaskData
                ]);
                
                if ($insertResult) {
                    $taskId = $this->db->lastInsertId();
                    $this->logToFile("Created auto info task with ID: {$taskId} for device ID: {$deviceId}");
                    
                    // Now get the task we just created
                    $stmt = $this->db->prepare("
                        SELECT * FROM device_tasks 
                        WHERE id = :task_id"
                    );
                    $stmt->execute([':task_id' => $taskId]);
                    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Double-check the connected_clients column for this device
                    $this->logToFile("Checking current connected_clients value for device ID: {$deviceId}");
                    $clientsStmt = $this->db->prepare("SELECT connected_clients FROM devices WHERE id = :id");
                    $clientsStmt->execute([':id' => $deviceId]);
                    $currentClients = $clientsStmt->fetchColumn();
                    $this->logToFile("Current connected_clients value: {$currentClients}");
                }
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
            
            // If task was completed successfully and it was an info task, make sure we update device info
            if ($status === 'completed') {
                $taskStmt = $this->db->prepare("SELECT task_type, device_id FROM device_tasks WHERE id = :id");
                $taskStmt->execute([':id' => $taskId]);
                $taskInfo = $taskStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($taskInfo && $taskInfo['task_type'] === 'info') {
                    $this->logToFile("Completed info task - ensuring device data is updated for device ID: {$taskInfo['device_id']}");
                    
                    // Double-check the connected_clients column for this device
                    $clientsStmt = $this->db->prepare("SELECT connected_clients FROM devices WHERE id = :id");
                    $clientsStmt->execute([':id' => $taskInfo['device_id']]);
                    $currentClients = $clientsStmt->fetchColumn();
                    $this->logToFile("Current connected_clients value after task completion: {$currentClients}");
                    
                    // If we have connected clients in the parameters table, use that value
                    $paramStmt = $this->db->prepare("
                        SELECT param_value FROM parameters 
                        WHERE device_id = :device_id 
                        AND param_name LIKE '%HostNumberOfEntries%'
                        ORDER BY updated_at DESC LIMIT 1
                    ");
                    $paramStmt->execute([':device_id' => $taskInfo['device_id']]);
                    $hostCount = $paramStmt->fetchColumn();
                    
                    if ($hostCount !== false && $hostCount != $currentClients) {
                        $this->logToFile("Found different host count in parameters table: {$hostCount}, updating device record");
                        
                        $updateStmt = $this->db->prepare("UPDATE devices SET connected_clients = :count WHERE id = :id");
                        $updateStmt->execute([
                            ':count' => $hostCount,
                            ':id' => $taskInfo['device_id']
                        ]);
                        
                        // Verify the update
                        $verifyStmt = $this->db->prepare("SELECT connected_clients FROM devices WHERE id = :id");
                        $verifyStmt->execute([':id' => $taskInfo['device_id']]);
                        $verifiedCount = $verifyStmt->fetchColumn();
                        $this->logToFile("Verified connected_clients value after update: {$verifiedCount}");
                    }
                }
            }
            
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
            case 'reboot':
                return $this->rebootTaskGenerator->generateParameters($data);
            case 'info':
                return $this->infoTaskGenerator->generateParameters($data);
            default:
                $this->logToFile("Unsupported task type: $taskType");
                return null;
        }
    }
    
    public function getCommitHelper() {
        return $this->commitHelper;
    }
}
