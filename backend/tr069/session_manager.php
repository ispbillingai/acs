
<?php
class SessionManager {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function createSession($deviceSerial, $sessionId) {
        try {
            $stmt = $this->db->prepare("INSERT INTO tr069_sessions (device_serial, session_id, created_at) VALUES (:device_serial, :session_id, NOW())");
            $stmt->execute([
                ':device_serial' => $deviceSerial,
                ':session_id' => $sessionId
            ]);
            return true;
        } catch (PDOException $e) {
            error_log("Error creating session: " . $e->getMessage());
            return false;
        }
    }

    public function validateSession($sessionId) {
        try {
            // Get session that's less than 30 minutes old
            $stmt = $this->db->prepare("SELECT * FROM tr069_sessions WHERE session_id = :session_id AND created_at > NOW() - INTERVAL 30 MINUTE");
            $stmt->execute([':session_id' => $sessionId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error validating session: " . $e->getMessage());
            return false;
        }
    }

    public function updateOrCreateSession($deviceSerial, $sessionId) {
        try {
            // Check if session exists
            $stmt = $this->db->prepare("SELECT id FROM tr069_sessions WHERE session_id = :session_id");
            $stmt->execute([':session_id' => $sessionId]);
            $sessionExists = $stmt->fetchColumn();

            if ($sessionExists) {
                // Update existing session
                $stmt = $this->db->prepare("UPDATE tr069_sessions SET last_activity = NOW(), device_serial = :device_serial WHERE session_id = :session_id");
                $stmt->execute([
                    ':device_serial' => $deviceSerial,
                    ':session_id' => $sessionId
                ]);
            } else {
                // Create new session
                $this->createSession($deviceSerial, $sessionId);
            }
            return true;
        } catch (PDOException $e) {
            error_log("Error updating session: " . $e->getMessage());
            return false;
        }
    }
}
