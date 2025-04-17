
<?php
class SessionManager {
    private $db;
    private $logger;
    private $deviceId;
    private $serialNumber;

    public function __construct($db, $logger) {
        $this->db = $db;
        $this->logger = $logger;
        
        // Ensure we have a session
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        $this->logger->logToFile("Session started/resumed with ID: " . session_id());
        error_log("TR-069: Session started/resumed with ID: " . session_id());
        
        // Log any existing serial number in session
        if (isset($_SESSION['device_serial'])) {
            error_log("TR-069: Existing device serial in session: " . $_SESSION['device_serial']);
        }
    }

    public function startNewSession($serialNumber) {
        // Reset all session data to ensure clean start
        $_SESSION = [];
        
        $_SESSION['device_serial'] = $serialNumber;
        $_SESSION['attempted_parameters'] = [];
        $_SESSION['successful_parameters'] = [];
        $_SESSION['host_count'] = 0;
        $_SESSION['current_host_index'] = 1;
        $_SESSION['session_started'] = date('Y-m-d H:i:s');
        $_SESSION['last_activity'] = time();
        
        // Clear any previous task
        if (isset($_SESSION['current_task'])) {
            unset($_SESSION['current_task']);
        }
        
        $this->logger->logToFile("Started new session for device: $serialNumber with session ID: " . session_id());
        error_log("TR-069: Started new session for device: $serialNumber with session ID: " . session_id());
        
        // Also store the serial number in the class property
        $this->serialNumber = $serialNumber;
        
        // Look up the device ID by serial number
        try {
            $stmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
            $stmt->bindParam(':serial', $serialNumber);
            $stmt->execute();
            
            if ($deviceId = $stmt->fetchColumn()) {
                $this->deviceId = $deviceId;
                $_SESSION['device_id'] = $deviceId;
                $this->logger->logToFile("Associated session with device ID: $deviceId");
                error_log("TR-069: Associated session with device ID: $deviceId");
            } else {
                // Device not found in database, create it
                error_log("TR-069: Device not found in database, will create a new record for: $serialNumber");
                try {
                    $stmt = $this->db->prepare("INSERT INTO devices (serial_number, status, created_at, updated_at) VALUES (:serial, 'new', NOW(), NOW())");
                    $stmt->bindParam(':serial', $serialNumber);
                    $stmt->execute();
                    
                    $newDeviceId = $this->db->lastInsertId();
                    $this->deviceId = $newDeviceId;
                    $_SESSION['device_id'] = $newDeviceId;
                    $this->logger->logToFile("Created new device with ID: $newDeviceId");
                    error_log("TR-069: Created new device with ID: $newDeviceId for serial: $serialNumber");
                } catch (PDOException $e) {
                    $this->logger->logToFile("Error creating device: " . $e->getMessage());
                    error_log("TR-069 ERROR: Failed to create device: " . $e->getMessage());
                }
            }
        } catch (PDOException $e) {
            $this->logger->logToFile("Database error looking up device ID: " . $e->getMessage());
            error_log("TR-069 ERROR: Database error looking up device ID: " . $e->getMessage());
        }
    }

    public function getCurrentTask() {
        $task = isset($_SESSION['current_task']) ? $_SESSION['current_task'] : null;
        $this->logger->logToFile("Retrieved current task: " . ($task ? $task['task_type'] . ' (ID: ' . $task['id'] . ')' : 'none'));
        error_log("TR-069: Retrieved current task: " . ($task ? $task['task_type'] . ' (ID: ' . $task['id'] . ')' : 'none'));
        return $task;
    }

    public function setCurrentTask($task) {
        $_SESSION['current_task'] = $task;
        $_SESSION['last_activity'] = time();
        $this->logger->logToFile("Set current task: " . ($task ? $task['task_type'] . ' (ID: ' . $task['id'] . ')' : 'null'));
        error_log("TR-069: Set current task: " . ($task ? $task['task_type'] . ' (ID: ' . $task['id'] . ')' : 'null'));
    }

    public function getCurrentSessionDeviceSerial() {
        $serial = $_SESSION['device_serial'] ?? null;
        $this->logger->logToFile("Retrieved current device serial from session: " . ($serial ?: 'none'));
        error_log("TR-069: Retrieved current device serial from session: " . ($serial ?: 'none'));
        
        // If we don't have it in the session but we do in the class property, use that
        if (!$serial && $this->serialNumber) {
            $serial = $this->serialNumber;
            $this->logger->logToFile("Used class property serial: $serial");
            error_log("TR-069: Used class property serial: $serial");
            
            // Update the session
            $_SESSION['device_serial'] = $serial;
        }
        
        return $serial;
    }

    public function cleanupSession() {
        $this->logger->logToFile("Cleaning up session with ID: " . session_id());
        error_log("TR-069: Cleaning up session with ID: " . session_id());
        
        // Log session data before cleaning
        if (isset($_SESSION['device_serial'])) {
            error_log("TR-069: Session contained device serial: " . $_SESSION['device_serial']);
        }
        
        session_destroy();
        $this->logger->logToFile("Session cleaned up");
        error_log("TR-069: Session destroyed");
    }
    
    public function updateLastActivity() {
        $_SESSION['last_activity'] = time();
    }
    
    public function isSessionExpired($maxLifetime = 1800) { // 30 minutes default
        if (!isset($_SESSION['last_activity'])) {
            return true;
        }
        
        return (time() - $_SESSION['last_activity']) > $maxLifetime;
    }
    
    public function logSessionState() {
        $sessionData = [];
        foreach ($_SESSION as $key => $value) {
            if (is_array($value)) {
                $sessionData[$key] = '[ARRAY:' . count($value) . ' items]';
            } else {
                $sessionData[$key] = $value;
            }
        }
        
        error_log("TR-069: Current session state: " . json_encode($sessionData));
        $this->logger->logToFile("Current session state: " . json_encode($sessionData));
    }
}
