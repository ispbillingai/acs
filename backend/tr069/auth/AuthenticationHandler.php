
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

    public function authenticate() {
        // Log authentication attempt
        $this->log("Authentication attempt from IP: " . $_SERVER['REMOTE_ADDR']);
        
        // First try regular HTTP authentication
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
            // Check if this is a router sending TR-069 XML without auth
            // Check raw post for the presence of SOAP envelope
            $rawPost = file_get_contents('php://input');
            if (!empty($rawPost) && strpos($rawPost, '<SOAP-ENV:Envelope') !== false) {
                $this->log("SOAP envelope found but missing credentials, allowing special case");
                return true; // Allow some routers that don't properly authenticate
            }
            
            $this->log("TR-069 Authentication Failed: Missing credentials");
            return false;
        }

        $username = $_SERVER['PHP_AUTH_USER'];
        $password = $_SERVER['PHP_AUTH_PW'];
        
        try {
            $stmt = $this->db->prepare("SELECT username, password FROM tr069_config LIMIT 1");
            $stmt->execute();
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($config && $username === $config['username'] && $password === $config['password']) {
                $this->log("Authentication successful for user: $username");
                return true;
            }
            
            // Try a fallback default credential for testing
            if (($username === 'admin' && $password === 'admin') || 
                ($username === 'tr069' && $password === 'tr069')) {
                $this->log("Authentication successful using fallback credentials");
                return true;
            }
            
            $this->log("TR-069 Authentication Failed: Invalid credentials for user: $username");
        } catch (Exception $e) {
            $this->log("TR-069 Authentication Error: " . $e->getMessage());
        }
        
        return false;
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [AUTH] $message\n";
        
        // Log to device.log file
        if (file_exists($this->logFile) && is_writable($this->logFile)) {
            file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        }
        
        // Also log to error_log as backup
        error_log("[AUTH] $message");
    }
}
