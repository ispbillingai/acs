
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
        } catch (Exception $e) {
            error_log("TR069 Authentication Error: " . $e->getMessage());
        }
        
        return false;
    }
}
