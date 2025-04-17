
<?php
require_once __DIR__ . '/../../config/database.php';

class AuthenticationHandler {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    public function authenticate() {
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
            // Log to device.log instead of separate error log
            error_log("TR-069 Authentication Failed: Missing credentials", 3, __DIR__ . '/../../../device.log');
            return false;
        }

        $username = $_SERVER['PHP_AUTH_USER'];
        $password = $_SERVER['PHP_AUTH_PW'];
        
        try {
            $stmt = $this->db->prepare("SELECT username, password FROM tr069_config LIMIT 1");
            $stmt->execute();
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($config && $username === $config['username'] && $password === $config['password']) {
                return true;
            }
            
            // Log failed authentication attempt to device.log
            error_log("TR-069 Authentication Failed: Invalid credentials", 3, __DIR__ . '/../../../device.log');
        } catch (Exception $e) {
            // Log any database-related authentication errors to device.log
            error_log("TR-069 Authentication Error: " . $e->getMessage(), 3, __DIR__ . '/../../../device.log');
        }
        
        return false;
    }
}
