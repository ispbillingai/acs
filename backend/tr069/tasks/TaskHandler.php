<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/utils/WifiTaskGenerator.php';
require_once __DIR__ . '/utils/WanTaskGenerator.php';
require_once __DIR__ . '/utils/RebootTaskGenerator.php';
require_once __DIR__ . '/utils/InfoTaskGenerator.php';
require_once __DIR__ . '/utils/CommitHelper.php';

class TaskHandler
{
    /* --------------------------------------------------------------
     *  Properties
     * ------------------------------------------------------------*/
    private $db;
    private $logFile;

    private $wifiTaskGenerator;
    private $wanTaskGenerator;
    private $rebootTaskGenerator;
    private $infoTaskGenerator;
    private $commitHelper;

    /* --------------------------------------------------------------
     *  Constructor
     * ------------------------------------------------------------*/
    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();

        // ----- log file ---------------------------------------------------
        $this->logFile = $_SERVER['DOCUMENT_ROOT'] . '/device.log';
        if (!file_exists(dirname($this->logFile))) {
            mkdir(dirname($this->logFile), 0755, true);
        }

        // ----- task generators -------------------------------------------
        $this->wifiTaskGenerator   = new WifiTaskGenerator($this);
        $this->wanTaskGenerator    = new WanTaskGenerator($this);
        $this->rebootTaskGenerator = new RebootTaskGenerator($this);
        $this->infoTaskGenerator   = new InfoTaskGenerator($this);
        $this->commitHelper        = new CommitHelper($this);
    }

    /* --------------------------------------------------------------
     *  Logging helper
     * ------------------------------------------------------------*/
    public function logToFile($message)
    {
        $ts  = date('Y-m-d H:i:s');
        $msg = "[$ts] [TR-069] $message" . PHP_EOL;
        // Apache error log as fallback
        error_log("[TR-069] $message", 0);
        file_put_contents($this->logFile, $msg, FILE_APPEND);
    }

    /* --------------------------------------------------------------
     *  Retrieve pending tasks for a device
     * ------------------------------------------------------------*/
    public function getPendingTasks($serial)
    {
        try {
            $stmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :s");
            $stmt->execute([':s' => $serial]);
            $deviceId = $stmt->fetchColumn();

            if (!$deviceId) {
                $this->logToFile("No device row for serial $serial");
                return [];
            }

            $taskStmt = $this->db->prepare("SELECT * FROM device_tasks WHERE device_id = :d AND status = 'pending' ORDER BY created_at ASC");
            $taskStmt->execute([':d' => $deviceId]);
            $tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);

            if ($tasks) {
                $this->logToFile('Found ' . count($tasks) . " pending tasks (device $deviceId)");
                foreach ($tasks as $t) {
                    $this->logToFile("  • Task {$t['id']} type={$t['task_type']} data={$t['task_data']}");
                }
            } else {
                $this->logToFile("No pending tasks for device $deviceId");
            }
            return $tasks;
        } catch (PDOException $e) {
            $this->logToFile('getPendingTasks DB error: ' . $e->getMessage());
            return [];
        }
    }

    /* --------------------------------------------------------------
     *  Update task status
     * ------------------------------------------------------------*/
    public function updateTaskStatus($taskId, $status, $message = null)
    {
        try {
            $sql = "UPDATE device_tasks SET status = :status, message = :message, updated_at = NOW() WHERE id = :id";
            $this->db->prepare($sql)->execute([
                ':status'  => $status,
                ':message' => $message,
                ':id'      => $taskId
            ]);
            $this->logToFile("Task $taskId → $status ($message)");
            return true;
        } catch (PDOException $e) {
            $this->logToFile('updateTaskStatus DB error: ' . $e->getMessage());
            return false;
        }
    }

    /* --------------------------------------------------------------
     *  Dispatch: build parameters for the specified task
     * ------------------------------------------------------------*/
    public function generateParameterValues($taskType, $taskData)
    {
        $data = json_decode($taskData, true) ?? [];
        $this->logToFile("generateParameterValues: type=$taskType");

        switch ($taskType) {
            case 'wifi':   return $this->wifiTaskGenerator  ->generateParameters($data);
            case 'wan':    return $this->wanTaskGenerator   ->generateParameters($data);
            case 'reboot': return $this->rebootTaskGenerator->generateParameters($data);
            case 'info':   return $this->infoTaskGenerator  ->generateParameters($data);
            default:
                $this->logToFile("Unsupported task type '$taskType'");
                return null;
        }
    }

    /* --------------------------------------------------------------
     *  Commit helper accessor
     * ------------------------------------------------------------*/
    public function getCommitHelper()
    {
        return $this->commitHelper;
    }
}
?>
