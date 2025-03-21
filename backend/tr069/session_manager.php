
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

            // Check if a session already exists for this serial number
            $existingSession = $this->getSessionBySerial($serialNumber);
            if ($existingSession) {
                // Update the existing session instead of creating a new one
                error_log("Session already exists for device $serialNumber, updating it");
                return $this->updateSession($existingSession['session_id'], $serialNumber);
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

            error_log("Created new session $sessionId for device $serialNumber");
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
                error_log("Updating existing session $sessionId for device $serialNumber");
                return $this->updateSession($sessionId, $serialNumber);
            }
            
            // Otherwise create a new session with the provided ID
            error_log("Creating new session $sessionId for device $serialNumber via updateOrCreate");
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
                    error_log("No session found for ID $sessionId, creating new one");
                    return $this->createSession($serialNumber, $sessionId);
                }
            } else {
                error_log("Updated session $sessionId" . ($serialNumber ? " for device $serialNumber" : ""));
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
                error_log("Session $sessionId is valid for device " . $session['device_serial']);
                return $session;
            }
            
            error_log("Session $sessionId is not valid");
            return false;
        } catch (PDOException $e) {
            error_log("Database error in validateSession: " . $e->getMessage());
            throw $e;
        }
    }

    public function getSessionBySerial($serialNumber) {
        try {
            $sql = "SELECT * FROM sessions 
                    WHERE device_serial = :serial 
                    AND expires_at > NOW()
                    ORDER BY created_at DESC
                    LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':serial' => $serialNumber]);
            
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($session) {
                error_log("Found existing session " . $session['session_id'] . " for device $serialNumber");
                return $session;
            }
            
            error_log("No active session found for device $serialNumber");
            return false;
        } catch (PDOException $e) {
            error_log("Database error in getSessionBySerial: " . $e->getMessage());
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
            
            error_log("Extended session $sessionId timeout");
        } catch (PDOException $e) {
            error_log("Database error in extendSession: " . $e->getMessage());
            throw $e;
        }
    }

    private function clearExpiredSessions() {
        try {
            $sql = "DELETE FROM sessions WHERE expires_at < NOW()";
            $count = $this->db->exec($sql);
            if ($count > 0) {
                error_log("Cleared $count expired sessions");
            }
        } catch (PDOException $e) {
            error_log("Database error in clearExpiredSessions: " . $e->getMessage());
            throw $e;
        }
    }

    private function generateSessionId() {
        return bin2hex(random_bytes(16));
    }
    
    // New method to record SOAP fault response
    public function recordFault($sessionId, $faultCode, $faultString) {
        try {
            error_log("Recording SOAP fault for session $sessionId: $faultCode - $faultString");
            file_put_contents(__DIR__ . '/../../get.log', date('Y-m-d H:i:s') . " SOAP Fault for session $sessionId: $faultCode - $faultString\n", FILE_APPEND);
            
            // We could also store this in the database if needed
            return true;
        } catch (Exception $e) {
            error_log("Error recording fault: " . $e->getMessage());
            return false;
        }
    }
}
