
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
        $this->logger->logToFile("Session started/resumed with ID: " . session_id());
    }

    public function startNewSession($serialNumber) {
        $_SESSION['device_serial'] = $serialNumber;
        $_SESSION['attempted_parameters'] = [];
        $_SESSION['successful_parameters'] = [];
        $_SESSION['host_count'] = 0;
        $_SESSION['current_host_index'] = 1;
        $_SESSION['session_started'] = date('Y-m-d H:i:s');
        
        // Clear any previous task
        if (isset($_SESSION['current_task'])) {
            unset($_SESSION['current_task']);
        }
        
        $this->logger->logToFile("Started new session for device: $serialNumber with session ID: " . session_id());
    }

    public function getCurrentTask() {
        $task = isset($_SESSION['current_task']) ? $_SESSION['current_task'] : null;
        $this->logger->logToFile("Retrieved current task: " . ($task ? $task['task_type'] . ' (ID: ' . $task['id'] . ')' : 'none'));
        return $task;
    }

    public function setCurrentTask($task) {
        $_SESSION['current_task'] = $task;
        $this->logger->logToFile("Set current task: " . ($task ? $task['task_type'] . ' (ID: ' . $task['id'] . ')' : 'null'));
    }

    public function getCurrentSessionDeviceSerial() {
        $serial = $_SESSION['device_serial'] ?? null;
        $this->logger->logToFile("Retrieved current device serial: " . ($serial ?: 'none'));
        return $serial;
    }

    public function cleanupSession() {
        $this->logger->logToFile("Cleaning up session with ID: " . session_id());
        session_destroy();
        $this->logger->logToFile("Session cleaned up");
    }
}
