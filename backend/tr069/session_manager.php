
<?php
class SessionManager {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function createSession($deviceSerial, $sessionId) {
        try {
            $stmt = $this->db->prepare("INSERT INTO tr069_sessions (device_serial, session_id, created_at, last_activity) VALUES (:device_serial, :session_id, NOW(), NOW())");
            $stmt->execute([
                ':device_serial' => $deviceSerial,
                ':session_id' => $sessionId
            ]);
            
            // Also ensure the device is marked as online
            $this->updateDeviceStatus($deviceSerial, 'online');
            
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
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($session) {
                // Update the device status to online
                $this->updateDeviceStatus($session['device_serial'], 'online');
            }
            
            return $session;
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
            
            // Also update the device status
            $this->updateDeviceStatus($deviceSerial, 'online');
            
            return true;
        } catch (PDOException $e) {
            error_log("Error updating session: " . $e->getMessage());
            return false;
        }
    }
    
    private function updateDeviceStatus($serialNumber, $status) {
        try {
            // Update device status and last_contact
            $stmt = $this->db->prepare("UPDATE devices SET status = :status, last_contact = NOW() WHERE serial_number = :serial_number");
            $stmt->execute([
                ':status' => $status,
                ':serial_number' => $serialNumber
            ]);
            
            // If no rows were affected, the device might not exist yet
            if ($stmt->rowCount() === 0) {
                // Check if device exists
                $checkStmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial_number");
                $checkStmt->execute([':serial_number' => $serialNumber]);
                $exists = $checkStmt->fetch();
                
                // If device doesn't exist, create it
                if (!$exists) {
                    $insertStmt = $this->db->prepare("INSERT INTO devices (serial_number, status, last_contact) VALUES (:serial_number, :status, NOW())");
                    $insertStmt->execute([
                        ':serial_number' => $serialNumber,
                        ':status' => $status
                    ]);
                }
            }
            
            return true;
        } catch (PDOException $e) {
            error_log("Error updating device status: " . $e->getMessage());
            return false;
        }
    }
}
