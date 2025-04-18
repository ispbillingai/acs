
<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/utils/WifiTaskGenerator.php';
require_once __DIR__ . '/utils/WanTaskGenerator.php';
require_once __DIR__ . '/utils/RebootTaskGenerator.php';
require_once __DIR__ . '/utils/CommitHelper.php';

class TaskHandler {
    private $db;
    private $wifiTaskGenerator;
    private $wanTaskGenerator;
    private $rebootTaskGenerator;
    private $commitHelper;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        
        // Initialize task generators
        $this->wifiTaskGenerator = new WifiTaskGenerator($this);
        $this->wanTaskGenerator = new WanTaskGenerator($this);
        $this->rebootTaskGenerator = new RebootTaskGenerator($this);
        $this->commitHelper = new CommitHelper($this);
    }
    
    public function getPendingTasks($deviceSerialNumber) {
        try {
            $deviceStmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial_number");
            $deviceStmt->execute([':serial_number' => $deviceSerialNumber]);
            $deviceId = $deviceStmt->fetchColumn();
            
            if (!$deviceId) {
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
            
            return $tasks;
        } catch (PDOException $e) {
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
            
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    public function generateParameterValues($taskType, $taskData) {
        $data = json_decode($taskData, true);
        
        if (!$data) {
            return null;
        }
        
        switch ($taskType) {
            case 'wifi':
                return $this->wifiTaskGenerator->generateParameters($data);
            case 'wan':
                return $this->wanTaskGenerator->generateParameters($data);
            case 'reboot':
                return $this->rebootTaskGenerator->generateParameters($data);
            default:
                return null;
        }
    }
    
    public function getCommitHelper() {
        return $this->commitHelper;
    }
}
