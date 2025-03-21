<?php
class SessionManager {
    private $db;
    private $sessionTimeout = 3600; // 1 hour

    public function __construct($db) {
        $this->db = $db;
    }

    public function createSession($serialNumber, $sessionId = null) {
        try {
            // Clear expired sessions first
            $this->clearExpiredSessions();

            // Generate session ID if not provided
            if ($sessionId === null) {
                $sessionId = $this->generateSessionId();
            }

            $sql = "INSERT INTO sessions 
                    (device_serial, session_id, created_at, expires_at) 
                    VALUES (:serial, :session_id, NOW(), 
                    DATE_ADD(NOW(), INTERVAL :timeout SECOND))";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':serial' => $serialNumber,
                ':session_id' => $sessionId,
                ':timeout' => $this->sessionTimeout
            ]);

            return $sessionId;
        } catch (PDOException $e) {
            error_log("Database error in createSession: " . $e->getMessage());
            // If the session already exists, try to update it instead
            if ($e->getCode() == 23000) { // Duplicate entry
                return $this->updateSession($sessionId, $serialNumber);
            }
            throw $e;
        }
    }

    public function updateOrCreateSession($serialNumber, $sessionId) {
        try {
            // First try to validate the session
            $session = $this->validateSession($sessionId);
            
            // If valid session exists, update it
            if ($session) {
                return $this->updateSession($sessionId, $serialNumber);
            }
            
            // Otherwise create a new session with the provided ID
            return $this->createSession($serialNumber, $sessionId);
        } catch (PDOException $e) {
            error_log("Database error in updateOrCreateSession: " . $e->getMessage());
            throw $e;
        }
    }

    public function updateSession($sessionId, $serialNumber = null) {
        try {
            $sql = "UPDATE sessions 
                    SET expires_at = DATE_ADD(NOW(), INTERVAL :timeout SECOND)";
            
            $params = [
                ':session_id' => $sessionId,
                ':timeout' => $this->sessionTimeout
            ];
            
            // If we have a serial number, update that too
            if ($serialNumber !== null) {
                $sql .= ", device_serial = :serial";
                $params[':serial'] = $serialNumber;
            }
            
            $sql .= " WHERE session_id = :session_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            // Check if any rows were updated
            if ($stmt->rowCount() == 0) {
                // No session found, create a new one
                if ($serialNumber !== null) {
                    return $this->createSession($serialNumber, $sessionId);
                }
            }
            
            return $sessionId;
        } catch (PDOException $e) {
            error_log("Database error in updateSession: " . $e->getMessage());
            throw $e;
        }
    }

    public function validateSession($sessionId) {
        try {
            $sql = "SELECT * FROM sessions 
                    WHERE session_id = :session_id 
                    AND expires_at > NOW()";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':session_id' => $sessionId]);
            
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($session) {
                // Extend session
                $this->extendSession($sessionId);
                return $session;
            }
            
            return false;
        } catch (PDOException $e) {
            error_log("Database error in validateSession: " . $e->getMessage());
            throw $e;
        }
    }

    private function extendSession($sessionId) {
        try {
            $sql = "UPDATE sessions 
                    SET expires_at = DATE_ADD(NOW(), INTERVAL :timeout SECOND) 
                    WHERE session_id = :session_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':session_id' => $sessionId,
                ':timeout' => $this->sessionTimeout
            ]);
        } catch (PDOException $e) {
            error_log("Database error in extendSession: " . $e->getMessage());
            throw $e;
        }
    }

    private function clearExpiredSessions() {
        try {
            $sql = "DELETE FROM sessions WHERE expires_at < NOW()";
            $this->db->exec($sql);
        } catch (PDOException $e) {
            error_log("Database error in clearExpiredSessions: " . $e->getMessage());
            throw $e;
        }
    }

    private function generateSessionId() {
        return bin2hex(random_bytes(16));
    }
}
