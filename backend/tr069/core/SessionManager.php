
<?php
class SessionManager {
    private $db;
    private $logger;
    private $deviceId;
    private $serialNumber;

    public function __construct($db, $logger) {
        $this->db = $db;
        $this->logger = $logger;
        session_start();
    }

    public function startNewSession($serialNumber) {
        $_SESSION['device_serial'] = $serialNumber;
        $_SESSION['attempted_parameters'] = [];
        $_SESSION['successful_parameters'] = [];
        $_SESSION['host_count'] = 0;
        $_SESSION['current_host_index'] = 1;
        
        // Clear any previous task
        if (isset($_SESSION['current_task'])) {
            unset($_SESSION['current_task']);
        }
        
        $this->logger->logToFile("Started new session for device: $serialNumber");
    }

    public function getCurrentTask() {
        return isset($_SESSION['current_task']) ? $_SESSION['current_task'] : null;
    }

    public function setCurrentTask($task) {
        $_SESSION['current_task'] = $task;
        $this->logger->logToFile("Set current task: " . ($task ? $task['task_type'] : 'null'));
    }

    public function getCurrentSessionDeviceSerial() {
        return $_SESSION['device_serial'] ?? null;
    }

    public function cleanupSession() {
        session_destroy();
        $this->logger->logToFile("Session cleaned up");
    }
}
