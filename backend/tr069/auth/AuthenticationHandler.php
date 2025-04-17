<?php
require_once __DIR__ . '/../../config/database.php';

class AuthenticationHandler {
    private $db;
    private $logFile;
    private $tr069CommunicationsLog;
    private $firewallCheckLog;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->logFile = __DIR__ . '/../../../device.log';
        $this->tr069CommunicationsLog = __DIR__ . '/../../../tr069_communications.log';
        $this->firewallCheckLog = __DIR__ . '/../../../firewall_check.log';
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

    private function writeFirewallCheckLog($message) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->firewallCheckLog, "$timestamp - $message\n", FILE_APPEND);
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
        $this->writeFirewallCheckLog("Testing connection to $deviceIp:$port with credentials $username:***");
        
        // Make a detailed HTTP request to device to check authentication
        if (function_exists('curl_init')) {
            $endpoint = "http://$deviceIp:$port/";
            $this->writeTR069Log("Attempting connection to $endpoint with credentials $username:***", true);
            
            $this->writeLog("TR-069 REQUEST to $endpoint");
            
            // Prepare a TR-069 request to send - try a simpler request first
            $requestXml = $this->generateSimpleTR069Request("session-" . substr(md5(uniqid()), 0, 8));
            
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Reduced timeout for faster testing
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Separate connection timeout
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
            $this->writeFirewallCheckLog("CURL VERBOSE: \n" . trim($verboseLog));
            
            // Check for specific error conditions
            if ($errorCode == CURLE_OPERATION_TIMEOUTED) {
                $this->writeFirewallCheckLog("FIREWALL CHECK: Connection timed out - port $port may be blocked or filtered");
                $this->writeTR069Log("Firewall may be blocking port $port. Connection timed out.");
            } elseif ($errorCode == CURLE_COULDNT_CONNECT) {
                $this->writeFirewallCheckLog("FIREWALL CHECK: Connection refused - port $port is likely closed");
            }
            
            if ($result !== false) {
                $this->writeLog("TR-069 RESPONSE: HTTP $httpCode");
                $this->writeLog("--- RESPONSE BODY ---");
                $this->writeLog(substr($result, 0, 1000) . (strlen($result) > 1000 ? '...' : ''));
                $this->writeLog("------------------");
                $this->writeFirewallCheckLog("HTTP Response Code: $httpCode");
                
                // Log the response to the TR-069 communications log
                $this->writeTR069Log("Response from $endpoint (HTTP Code: $httpCode)");
                $this->writeTR069Log("Response Body: " . substr($result, 0, 500) . (strlen($result) > 500 ? '...' : ''));
                
                $authenticated = ($httpCode >= 200 && $httpCode < 400); // Consider 2xx or 3xx as potentially authenticated
                
                if ($authenticated) {
                    $this->writeLog("DEVICE AUTHENTICATION SUCCESS: Device at $deviceIp:$port responded with HTTP $httpCode");
                    $this->writeFirewallCheckLog("CONNECTION SUCCESS: Device responded with HTTP $httpCode");
                } else {
                    $this->writeLog("DEVICE AUTHENTICATION FAILED: Device at $deviceIp:$port rejected with HTTP $httpCode");
                    $this->writeFirewallCheckLog("CONNECTION AUTHENTICATION FAILED: Device rejected with HTTP $httpCode");
                }
                
                curl_close($ch);
                return $authenticated;
            } else {
                $this->writeLog("TR-069 ERROR: cURL failed - $errorMessage");
                $this->writeLog("TR-069 CONNECTION FAILED: Could not connect to device on port $port");
                $this->writeTR069Log("Connection failed to $endpoint: $errorMessage");
                $this->writeFirewallCheckLog("CONNECTION FAILED: cURL error $errorCode - $errorMessage");
                
                curl_close($ch);
            }
        }
        
        // Try a simple socket connection with reduced timeout to check if the device is reachable
        $this->writeLog("TR-069 FALLBACK: Trying simple socket connection to port $port");
        $connection = @fsockopen($deviceIp, $port, $errno, $errstr, 3);
        if ($connection) {
            fclose($connection);
            $this->writeLog("DEVICE CONNECTION SUCCESS: Device at $deviceIp:$port is reachable via socket");
            $this->writeFirewallCheckLog("SOCKET CONNECTION SUCCESS: Port $port is open and reachable");
            return true; // Device is at least reachable
        }
        
        $this->writeLog("DEVICE CONNECTION FAILED: Cannot reach device at $deviceIp:$port via socket");
        $this->writeFirewallCheckLog("SOCKET CONNECTION FAILED: Port $port appears to be closed or filtered");
        
        // Try ping to check basic connectivity
        $pingResult = $this->pingHost($deviceIp);
        $this->writeFirewallCheckLog("PING RESULT: " . ($pingResult ? "Host is reachable" : "Host is not responding to ICMP"));
        
        return false;
    }
    
    // Ping host to check basic connectivity
    private function pingHost($host) {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows
            $pingCommand = "ping -n 2 -w 1000 " . escapeshellarg($host);
        } else {
            // Unix/Linux
            $pingCommand = "ping -c 2 -W 1 " . escapeshellarg($host);
        }
        
        exec($pingCommand, $output, $returnCode);
        $this->writeFirewallCheckLog("PING COMMAND: $pingCommand");
        $this->writeFirewallCheckLog("PING OUTPUT: " . implode("\n", $output));
        
        return $returnCode === 0;
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
    
    // Helper method to generate a simpler TR-069 request XML for testing connection
    private function generateSimpleTR069Request($sessionId) {
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
        <string>InternetGatewayDevice.DeviceSummary</string>
      </ParameterNames>
    </cwmp:GetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
    }
    
    // Regular TR-069 request for parameter retrieval
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
        
        // Clear the firewall check log for this new test
        if (file_exists($this->firewallCheckLog)) {
            unlink($this->firewallCheckLog);
        }
        
        $this->writeFirewallCheckLog("FIREWALL CHECK: Starting connectivity tests for device $deviceId at IP $deviceIp");
        $this->writeFirewallCheckLog("DEVICE MODEL: " . ($model ? $model : "Unknown"));
        
        // Common TR-069 ports to try - prioritize port 7547
        $portsToTry = [7547, 30005, 37215, 4567, 8080];
        
        // First try with the device-specific or default credentials with TCP port scan
        $this->writeFirewallCheckLog("STARTING TCP PORT SCAN: Testing all common TR-069 ports");
        $openPorts = $this->scanPorts($deviceIp, $portsToTry);
        $this->writeFirewallCheckLog("TCP PORT SCAN RESULTS: Open ports: " . implode(", ", $openPorts));
        
        // Prioritize open ports from scan
        if (!empty($openPorts)) {
            $portsToTry = array_merge($openPorts, array_diff($portsToTry, $openPorts));
        }
        
        // Try connections with current credentials on each port
        foreach ($portsToTry as $port) {
            $this->writeFirewallCheckLog("TRYING PORT $port with credentials $username:***");
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
            $this->writeFirewallCheckLog("TRYING MODEL-SPECIFIC CREDENTIALS: Huawei HG8546M");
            
            // Known possible credential sets for Huawei HG8546M
            $credentialSets = [
                ['telecomadmin', 'admintelecom'],
                ['root', 'admin'],
                ['admin', 'admin123'],
                ['adminpldt', 'adminpldt'],
                ['admin', ''],  // Empty password
                ['useradmin', 'admin1234'],
                ['admin', 'admin'],
                ['root', 'root'],
                ['root', ''],
            ];
            
            foreach ($credentialSets as $credSet) {
                $altUsername = $credSet[0];
                $altPassword = $credSet[1];
                
                $this->writeLog("TRYING CREDENTIALS: $altUsername:***");
                $this->writeFirewallCheckLog("TRYING ALTERNATIVE CREDENTIALS: $altUsername:***");
                
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
            $this->writeLog("TR-069 FALLBACK: Attempting alternative connection methods for Huawei device");
            $this->writeFirewallCheckLog("TRYING ALTERNATIVE CONNECTION METHODS FOR HUAWEI DEVICE");
            
            // Check HTTP connection on standard ports
            $webPorts = [80, 443, 8080];
            foreach ($webPorts as $webPort) {
                $webUrl = ($webPort == 443 ? "https://" : "http://") . "$deviceIp:$webPort/";
                $this->writeFirewallCheckLog("CHECKING WEB INTERFACE: $webUrl");
                
                $ch = curl_init($webUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                
                $result = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                
                $this->writeFirewallCheckLog("WEB INTERFACE CHECK ($webPort): HTTP Code=$httpCode, Error=$error");
                
                if ($httpCode > 0) {
                    $this->writeFirewallCheckLog("WEB INTERFACE AVAILABLE: Port $webPort returned HTTP $httpCode");
                    $this->writeLog("ROUTER WEB INTERFACE: Available at $webUrl (HTTP $httpCode)");
                }
            }
        }
        
        // Create a comprehensive connection report
        $this->writeFirewallCheckLog("CONNECTION DIAGNOSIS SUMMARY:");
        $this->writeFirewallCheckLog("1. IP Address: $deviceIp was " . ($this->pingHost($deviceIp) ? "reachable" : "unreachable") . " via ping");
        $this->writeFirewallCheckLog("2. TR-069 ports tested: " . implode(", ", $portsToTry));
        $this->writeFirewallCheckLog("3. Open ports detected: " . (empty($openPorts) ? "None" : implode(", ", $openPorts)));
        $this->writeFirewallCheckLog("4. Authentication attempted with " . (stripos($model, 'HG8546M') !== false ? "multiple credential sets" : "default credentials"));
        $this->writeFirewallCheckLog("5. Web interface checked on ports: 80, 443, 8080");
        $this->writeFirewallCheckLog("CONCLUSION: Unable to establish TR-069 connection with available methods");
        
        return [
            'success' => false,
            'message' => 'Could not authenticate on any common TR-069 port with any credential set',
            'details' => 'Device may be blocking TR-069 connections or using non-standard configuration',
            'diagnostics' => file_exists($this->firewallCheckLog) ? file_get_contents($this->firewallCheckLog) : 'No diagnostic data available'
        ];
    }
    
    // Scan for open ports (lightweight implementation)
    private function scanPorts($host, $ports) {
        $openPorts = [];
        foreach ($ports as $port) {
            $this->writeFirewallCheckLog("Checking port $port on $host...");
            $fp = @fsockopen($host, $port, $errno, $errstr, 1);
            if ($fp) {
                $openPorts[] = $port;
                $this->writeFirewallCheckLog("Port $port is OPEN");
                fclose($fp);
            } else {
                $this->writeFirewallCheckLog("Port $port is CLOSED or FILTERED: $errstr ($errno)");
            }
        }
        return $openPorts;
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
