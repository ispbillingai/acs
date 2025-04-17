
<?php
require_once __DIR__ . '/../../config/database.php';

class AuthenticationHandler {
    private $db;
    private $logFile;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->logFile = __DIR__ . '/../../../device.log';
    }

    private function writeLog($message) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->logFile, "$timestamp - $message\n", FILE_APPEND);
    }

    public function authenticate() {
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
            $this->writeLog("TR-069 AUTHENTICATION FAILED: Missing credentials from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            return false;
        }

        $username = $_SERVER['PHP_AUTH_USER'];
        $password = $_SERVER['PHP_AUTH_PW'];
        
        try {
            $stmt = $this->db->prepare("SELECT username, password FROM tr069_config LIMIT 1");
            $stmt->execute();
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($config && $username === $config['username'] && $password === $config['password']) {
                $this->writeLog("TR-069 AUTHENTICATION SUCCESS: Device from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . " authenticated");
                return true;
            }
            
            $this->writeLog("TR-069 AUTHENTICATION FAILED: Invalid credentials from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        } catch (Exception $e) {
            $this->writeLog("TR-069 AUTHENTICATION ERROR: " . $e->getMessage());
        }
        
        return false;
    }
}
