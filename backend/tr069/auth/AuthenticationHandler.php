
<?php
require_once __DIR__ . '/../../config/database.php';

class AuthenticationHandler {
    private $db;
    private $logFile;
    private $tr069CommunicationsLog;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->logFile = __DIR__ . '/../../../device.log';
        $this->tr069CommunicationsLog = __DIR__ . '/../../../tr069_communications.log';
    }

    private function writeLog($message) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->logFile, "$timestamp - $message\n", FILE_APPEND);
    }
    
    private function writeTR069Log($message, $isOutgoing = false) {
        $timestamp = date('Y-m-d H:i:s');
        $direction = $isOutgoing ? "[OUT]" : "[IN]";
        file_put_contents($this->tr069CommunicationsLog, "$timestamp - $direction $message\n", FILE_APPEND);
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
    
    // Enhanced method to handle direct device authentication
    public function authenticateDevice($deviceIp, $username, $password, $port = 7547) {
        $this->writeLog("DEVICE AUTHENTICATION: Attempting to authenticate device at $deviceIp:$port");
        
        // Make a detailed HTTP request to device to check authentication
        if (function_exists('curl_init')) {
            $endpoint = "http://$deviceIp:$port/";
            $this->writeTR069Log("Attempting connection to $endpoint with credentials $username:***", true);
            
            $this->writeLog("TR-069 REQUEST to $endpoint");
            
            // Prepare a TR-069 request to send
            $requestXml = $this->generateTR069Request("session-" . substr(md5(uniqid()), 0, 8));
            
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: text/xml; charset=utf-8',
                'Authorization: Basic ' . base64_encode("$username:$password")
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestXml);
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            
            // Capture verbose output
            $verbose = fopen('php://temp', 'w+');
            curl_setopt($ch, CURLOPT_STDERR, $verbose);
            
            // Log request headers and body
            $this->writeLog("--- REQUEST HEADERS ---");
            $this->writeLog("Content-Type: text/xml; charset=utf-8");
            $this->writeLog("Authorization: Basic " . base64_encode("$username:$password"));
            $this->writeLog("------------------");
            
            $this->writeLog("--- REQUEST BODY ---");
            $this->writeLog($requestXml);
            $this->writeLog("------------------");
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errorCode = curl_errno($ch);
            $errorMessage = curl_error($ch);
            
            // Get verbose information
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            $this->writeLog("TR-069 CURL VERBOSE LOG: \n" . trim($verboseLog));
            
            if ($result !== false) {
                $this->writeLog("TR-069 RESPONSE: HTTP $httpCode");
                $this->writeLog("--- RESPONSE BODY ---");
                $this->writeLog(substr($result, 0, 1000) . (strlen($result) > 1000 ? '...' : ''));
                $this->writeLog("------------------");
                
                // Log the response to the TR-069 communications log
                $this->writeTR069Log("Response from $endpoint (HTTP Code: $httpCode)");
                $this->writeTR069Log("Response Body: " . substr($result, 0, 500) . (strlen($result) > 500 ? '...' : ''));
                
                $authenticated = ($httpCode >= 200 && $httpCode < 400); // Consider 2xx or 3xx as potentially authenticated
                
                if ($authenticated) {
                    $this->writeLog("DEVICE AUTHENTICATION SUCCESS: Device at $deviceIp:$port responded with HTTP $httpCode");
                } else {
                    $this->writeLog("DEVICE AUTHENTICATION FAILED: Device at $deviceIp:$port rejected with HTTP $httpCode");
                }
                
                curl_close($ch);
                return $authenticated;
            } else {
                $this->writeLog("TR-069 ERROR: cURL failed - $errorMessage");
                $this->writeLog("TR-069 CONNECTION FAILED: Could not connect to device on port $port");
                $this->writeTR069Log("Connection failed to $endpoint: $errorMessage");
                
                curl_close($ch);
            }
        }
        
        // Try a simple socket connection to check if the device is reachable
        $this->writeLog("TR-069 FALLBACK: Trying simple socket connection to port $port");
        $connection = @fsockopen($deviceIp, $port, $errno, $errstr, 5);
        if ($connection) {
            fclose($connection);
            $this->writeLog("DEVICE CONNECTION SUCCESS: Device at $deviceIp:$port is reachable via socket");
            return true; // Device is at least reachable
        }
        
        $this->writeLog("DEVICE CONNECTION FAILED: Cannot reach device at $deviceIp:$port via socket");
        return false;
    }
    
    // Get device credentials from database or use defaults with model-specific logic
    public function getDeviceCredentials($deviceId) {
        try {
            $stmt = $this->db->prepare("SELECT serial_number, model_name, manufacturer, connection_request_username, connection_request_password FROM devices WHERE id = :id");
            $stmt->bindParam(':id', $deviceId);
            $stmt->execute();
            $device = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($device) {
                // Check if this is a Huawei HG8546M device
                $isHuaweiHG8546M = (stripos($device['model_name'], 'HG8546M') !== false);
                
                // For Huawei HG8546M, use known default credentials if none specified
                if ($isHuaweiHG8546M && (empty($device['connection_request_username']) || empty($device['connection_request_password']))) {
                    $this->writeLog("DEVICE MODEL: Huawei HG8546M detected - Using model-specific default credentials");
                    return [
                        'username' => 'telecomadmin',
                        'password' => 'admintelecom',
                        'model' => 'HG8546M',
                        'manufacturer' => $device['manufacturer']
                    ];
                }
                
                return [
                    'username' => $device['connection_request_username'] ?? 'admin',
                    'password' => $device['connection_request_password'] ?? 'admin',
                    'model' => $device['model_name'],
                    'manufacturer' => $device['manufacturer'],
                    'serial' => $device['serial_number']
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
    
    // Helper method to generate a TR-069 request XML for testing connection
    private function generateTR069Request($sessionId) {
        return '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope
    xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
    xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $sessionId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:GetParameterValues>
      <ParameterNames SOAP-ENC:arrayType="xsd:string[1]">
        <string>InternetGatewayDevice.DeviceInfo.SoftwareVersion</string>
      </ParameterNames>
    </cwmp:GetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
    }
    
    // Test authentication on multiple ports with different credential sets
    public function testMultiplePortAuthentication($deviceIp, $deviceId) {
        // Get device-specific credentials
        $credentials = $this->getDeviceCredentials($deviceId);
        $username = $credentials['username'];
        $password = $credentials['password'];
        $model = $credentials['model'] ?? '';
        
        $this->writeLog("DEVICE MODEL: " . ($model ? $model : "Unknown") . " detected - Using specific TR-069 credentials");
        
        // Log credentials being used (sanitized)
        $this->writeLog("TR-069 CREDENTIALS: Using username: $username and password: " . str_repeat('*', strlen($password)));
        
        // Common TR-069 ports to try
        $portsToTry = [7547, 30005, 37215, 4567, 8080];
        
        // First try with the device-specific or default credentials
        foreach ($portsToTry as $port) {
            if ($this->authenticateDevice($deviceIp, $username, $password, $port)) {
                return [
                    'success' => true, 
                    'port' => $port,
                    'credentials' => ['username' => $username, 'password' => '***']
                ];
            }
        }
        
        // If it's a Huawei HG8546M, try known alternative credentials
        if (stripos($model, 'HG8546M') !== false) {
            $this->writeLog("DEVICE SPECIFIC: Trying Huawei HG8546M specific credentials");
            
            // Known possible credential sets for Huawei HG8546M
            $credentialSets = [
                ['telecomadmin', 'admintelecom'],
                ['root', 'admin'],
                ['admin', 'admin123'],
                ['adminpldt', 'adminpldt'],
                ['admin', ''],  // Empty password
            ];
            
            foreach ($credentialSets as $credSet) {
                $altUsername = $credSet[0];
                $altPassword = $credSet[1];
                
                $this->writeLog("TRYING CREDENTIALS: $altUsername:***");
                
                foreach ($portsToTry as $port) {
                    if ($this->authenticateDevice($deviceIp, $altUsername, $altPassword, $port)) {
                        // Store these credentials for future use
                        $this->updateDeviceCredentials($deviceId, $altUsername, $altPassword);
                        
                        return [
                            'success' => true,
                            'port' => $port,
                            'credentials' => ['username' => $altUsername, 'password' => '***'],
                            'note' => 'Used alternative credentials for Huawei HG8546M'
                        ];
                    }
                }
            }
        }
        
        // Try STUN connection as last resort for Huawei devices
        if (stripos($model, 'Huawei') !== false || stripos($model, 'HG') !== false) {
            $this->writeLog("TR-069 FALLBACK: Attempting STUN connection for Huawei device");
            
            // STUN request would go here
            // ...
        }
        
        return [
            'success' => false,
            'message' => 'Could not authenticate on any common TR-069 port with any credential set',
            'details' => 'Device may be blocking TR-069 connections or using non-standard configuration'
        ];
    }
    
    // Update device credentials in the database
    private function updateDeviceCredentials($deviceId, $username, $password) {
        try {
            $stmt = $this->db->prepare("UPDATE devices SET connection_request_username = :username, connection_request_password = :password WHERE id = :id");
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':password', $password);
            $stmt->bindParam(':id', $deviceId);
            $stmt->execute();
            
            $this->writeLog("DEVICE CREDENTIALS UPDATED: Stored working credentials for device ID $deviceId");
            return true;
        } catch (Exception $e) {
            $this->writeLog("DATABASE ERROR: Failed to update device credentials: " . $e->getMessage());
            return false;
        }
    }
}
