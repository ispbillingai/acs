
<?php
class SessionManager {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function createSession($serialNumber) {
        $sessionId = $this->generateSessionId();
        $sql = "INSERT INTO sessions (device_serial, session_id, created_at, expires_at) 
                VALUES (:serial, :session_id, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':serial' => $serialNumber,
            ':session_id' => $sessionId
        ]);

        return $sessionId;
    }

    public function validateSession($sessionId) {
        $sql = "SELECT * FROM sessions 
                WHERE session_id = :session_id 
                AND expires_at > NOW()";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':session_id' => $sessionId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function generateSessionId() {
        return bin2hex(random_bytes(16));
    }
}
