
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
            
            $this->writeLog("TR-069 AUTHENTICATION FAILED: Invalid credentials from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . " (User: $username)");
        } catch (Exception $e) {
            $this->writeLog("TR-069 AUTHENTICATION ERROR: " . $e->getMessage());
        }
        
        return false;
    }
    
    // New method to handle direct device authentication
    public function authenticateDevice($deviceIp, $username, $password, $port = 7547) {
        $this->writeLog("DEVICE AUTHENTICATION: Attempting to authenticate device at $deviceIp:$port");
        
        // Make a basic HTTP request to device to check authentication
        if (function_exists('curl_init')) {
            $ch = curl_init("http://$deviceIp:$port/");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Basic ' . base64_encode("$username:$password")
            ]);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $authenticated = ($httpCode != 401); // Consider any non-401 response as potentially authenticated
            
            if ($authenticated) {
                $this->writeLog("DEVICE AUTHENTICATION SUCCESS: Device at $deviceIp:$port responded with HTTP $httpCode");
            } else {
                $this->writeLog("DEVICE AUTHENTICATION FAILED: Device at $deviceIp:$port rejected authentication");
            }
            
            return $authenticated;
        }
        
        // If cURL is not available, use a simple socket connection to check if the device is reachable
        $connection = @fsockopen($deviceIp, $port, $errno, $errstr, 5);
        if ($connection) {
            fclose($connection);
            $this->writeLog("DEVICE CONNECTION SUCCESS: Device at $deviceIp:$port is reachable");
            return true; // Device is at least reachable
        }
        
        $this->writeLog("DEVICE CONNECTION FAILED: Cannot reach device at $deviceIp:$port");
        return false;
    }
    
    // Get device credentials from database or use defaults
    public function getDeviceCredentials($deviceId) {
        try {
            $stmt = $this->db->prepare("SELECT connection_request_username, connection_request_password FROM devices WHERE id = :id");
            $stmt->bindParam(':id', $deviceId);
            $stmt->execute();
            $device = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($device) {
                return [
                    'username' => $device['connection_request_username'] ?? 'admin',
                    'password' => $device['connection_request_password'] ?? 'admin'
                ];
            }
        } catch (Exception $e) {
            $this->writeLog("DATABASE ERROR: Failed to get device credentials: " . $e->getMessage());
        }
        
        // Return default credentials if no device-specific ones found
        return [
            'username' => 'admin',
            'password' => 'admin'
        ];
    }
    
    // Test authentication on multiple ports
    public function testMultiplePortAuthentication($deviceIp, $username, $password) {
        $portsToTry = [7547, 30005, 37215, 4567, 8080];
        
        foreach ($portsToTry as $port) {
            if ($this->authenticateDevice($deviceIp, $username, $password, $port)) {
                return [
                    'success' => true, 
                    'port' => $port
                ];
            }
        }
        
        return [
            'success' => false,
            'message' => 'Could not authenticate on any common TR-069 port'
        ];
    }
}
