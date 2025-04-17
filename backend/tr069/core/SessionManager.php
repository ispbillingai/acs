
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
    }

    public function getCurrentTask() {
        return isset($_SESSION['current_task']) ? $_SESSION['current_task'] : null;
    }

    public function setCurrentTask($task) {
        $_SESSION['current_task'] = $task;
    }

    public function getDeviceSerial() {
        return $_SESSION['device_serial'] ?? null;
    }

    public function cleanupSession() {
        session_destroy();
    }
}
