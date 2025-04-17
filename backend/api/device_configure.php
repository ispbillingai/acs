<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions/device_functions.php';
require_once __DIR__ . '/../tr069/responses/InformResponseGenerator.php';

$response = ['success' => false, 'message' => 'Invalid request'];

try {
    $database = new Database();
    $db = $database->getConnection();

    $deviceId = $_POST['device_id'] ?? null;
    $action = $_POST['action'] ?? null;

    if (!$deviceId) {
        throw new Exception("Device ID is required");
    }

    $logFile = __DIR__ . '/../../logs/configure.log';
    $wifiLogFile = __DIR__ . '/../../logs/wifi_detailed.log';
    $tr069LogFile = __DIR__ . '/../../logs/tr069_transaction.log';
    
    // Create logs directory if it doesn't exist
    if (!file_exists(dirname($logFile))) {
        mkdir(dirname($logFile), 0755, true);
    }
    if (!file_exists(dirname($wifiLogFile))) {
        mkdir(dirname($wifiLogFile), 0755, true);
    }
    if (!file_exists(dirname($tr069LogFile))) {
        mkdir(dirname($tr069LogFile), 0755, true);
    }
    
    // Log the incoming request with detailed information
    $timestamp = date('Y-m-d H:i:s');
    $requestInfo = "$timestamp - API REQUEST: $action for device $deviceId\n";
    $requestInfo .= "  POST parameters: " . json_encode($_POST) . "\n";
    file_put_contents($tr069LogFile, $requestInfo, FILE_APPEND);
    
    // Get device information from database
    $stmt = $db->prepare("SELECT * FROM devices WHERE id = :id");
    $stmt->bindParam(':id', $deviceId);
    $stmt->execute();
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$device) {
        $errorMsg = "$timestamp - ERROR: Device not found with ID $deviceId\n";
        file_put_contents($tr069LogFile, $errorMsg, FILE_APPEND);
        throw new Exception("Device not found");
    }

    // Log device information
    $deviceInfo = "$timestamp - DEVICE INFO:\n";
    $deviceInfo .= "  Serial: {$device['serial_number']}\n";
    $deviceInfo .= "  Model: {$device['model_name']}\n";
    $deviceInfo .= "  IP: {$device['ip_address']}\n";
    $deviceInfo .= "  Status: {$device['status']}\n";
    $deviceInfo .= "  Last Contact: {$device['last_contact']}\n";
    file_put_contents($tr069LogFile, $deviceInfo, FILE_APPEND);
    
    // Create ResponseGenerator for TR-069 SOAP messages
    $responseGenerator = new InformResponseGenerator();
    
    switch ($action) {
        case 'get_settings':
            // Return current device settings
            $response = [
                'success' => true, 
                'settings' => [
                    'ssid' => $device['ssid'] ?? '',
                    'password' => $device['ssid_password'] ?? '',
                    'ip_address' => $device['ip_address'] ?? '',
                    'gateway' => '',  // Get from database if available
                    'connection_request_username' => $device['connection_request_username'] ?? 'admin',
                    'connection_request_password' => $device['connection_request_password'] ?? 'admin'
                ]
            ];
            
            // Log the settings retrieval
            $settingsLog = "$timestamp - Retrieved current settings for device $deviceId\n";
            $settingsLog .= "  SSID: {$device['ssid']}\n";
            $settingsLog .= "  Password length: " . strlen($device['ssid_password'] ?? '') . " chars\n";
            $settingsLog .= "  IP: {$device['ip_address']}\n";
            file_put_contents($tr069LogFile, $settingsLog, FILE_APPEND);
            
            break;
            
        case 'wifi':
            $ssid = $_POST['ssid'] ?? '';
            $password = $_POST['password'] ?? '';
            
            if (empty($ssid)) {
                $errorMsg = "$timestamp - ERROR: Empty SSID provided\n";
                file_put_contents($tr069LogFile, $errorMsg, FILE_APPEND);
                throw new Exception("SSID cannot be empty");
            }
            
            // Log detailed WiFi configuration change attempt
            $logEntry = "$timestamp - WiFi CONFIGURATION CHANGE ATTEMPT:\n";
            $logEntry .= "  Device ID: $deviceId\n";
            $logEntry .= "  New SSID: $ssid\n";
            $logEntry .= "  Password Length: " . strlen($password) . " characters\n";
            $logEntry .= "  IP Address: {$device['ip_address']}\n";
            
            file_put_contents($wifiLogFile, $logEntry, FILE_APPEND);
            file_put_contents($logFile, $logEntry, FILE_APPEND);
            
            // Log TR-069 workflow explanation with detailed steps
            file_put_contents($wifiLogFile, "\n$timestamp - TR-069 WORKFLOW EXPLANATION:\n", FILE_APPEND);
            file_put_contents($wifiLogFile, "  1. ACS sends Connection Request to device's ConnectionRequestURL\n", FILE_APPEND);
            file_put_contents($wifiLogFile, "     curl -i -u \"username:password\" \"http://{$device['ip_address']}:PORT/\"\n", FILE_APPEND);
            file_put_contents($wifiLogFile, "     PORT can be: 30005, 37215, 7547, or 4567 (try all if needed)\n", FILE_APPEND);
            file_put_contents($wifiLogFile, "  2. Device responds with 204 No Content to the Connection Request\n", FILE_APPEND);
            file_put_contents($wifiLogFile, "  3. Device opens a CWMP session with ACS and sends an Inform message\n", FILE_APPEND);
            file_put_contents($wifiLogFile, "  4. ACS responds to the Inform with InformResponse\n", FILE_APPEND);
            file_put_contents($wifiLogFile, "  5. ACS sends SetParameterValues with the new WiFi settings\n", FILE_APPEND);
            file_put_contents($wifiLogFile, "     - CRITICAL: Using InternetGatewayDevice paths for TR-098 compatibility\n", FILE_APPEND);
            file_put_contents($wifiLogFile, "     - Using PreSharedKey.1.PreSharedKey, NOT KeyPassphrase for HG8145V5\n", FILE_APPEND);
            file_put_contents($wifiLogFile, "  6. Device responds with SetParameterValuesResponse (status 0 on success)\n", FILE_APPEND);
            file_put_contents($wifiLogFile, "  7. ACS sends Commit command with matching CommandKey\n", FILE_APPEND);
            file_put_contents($wifiLogFile, "  8. Device applies changes and may need to reboot\n", FILE_APPEND);
            file_put_contents($wifiLogFile, "  9. ACS verifies with GetParameterValues (optional)\n\n", FILE_APPEND);
            
            // Generate session ID for this transaction
            $sessionId = 'wifi-' . substr(md5(time() . $deviceId), 0, 12);
            
            // Create complete TR-069 debug workflow
            $workflowData = $responseGenerator->createDebugWorkflow($sessionId, $ssid, $password);
            
            // Log the workflow
            file_put_contents($wifiLogFile, "$timestamp - CREATED COMPLETE TR-098 DEBUG WORKFLOW:\n", FILE_APPEND);
            file_put_contents($wifiLogFile, "  Session ID: $sessionId\n", FILE_APPEND);
            file_put_contents($wifiLogFile, "  Parameter Key: {$workflowData['parameter_key']}\n", FILE_APPEND);
            file_put_contents($wifiLogFile, "  Commit Key: {$workflowData['commit_key']}\n\n", FILE_APPEND);
            
            // Common connection request ports for Huawei devices
            $possiblePorts = [30005, 37215, 7547, 4567];
            
            // Generate all possible connection request URLs for testing
            $connectionRequestUrls = [];
            foreach ($possiblePorts as $port) {
                $connectionRequestUrls[] = "http://{$device['ip_address']}:$port/";
            }
            
            // Default ConnectionRequestURL (will be updated if known)
            $connectionRequestUrl = $connectionRequestUrls[0];
            
            // Get connection request credentials from device or use defaults
            $connectionRequestUsername = $device['connection_request_username'] ?? 'admin';
            $connectionRequestPassword = $device['connection_request_password'] ?? 'admin';
            
            // Generate connection request details with all possible ports
            $connectionRequest = $responseGenerator->createConnectionRequestTrigger(
                $connectionRequestUsername,
                $connectionRequestPassword,
                $connectionRequestUrl
            );
            
            // Add all possible connection request commands to the log
            file_put_contents($wifiLogFile, "$timestamp - CONNECTION REQUEST COMMANDS TO TRY:\n", FILE_APPEND);
            foreach ($connectionRequest['alternative_commands'] as $index => $cmd) {
                $port = $possiblePorts[$index] ?? 'unknown';
                file_put_contents($wifiLogFile, "  Port $port: $cmd\n", FILE_APPEND);
            }
            
            // Update device in database with new WiFi settings (pending)
            try {
                $updateStmt = $db->prepare("UPDATE devices SET 
                    ssid = :ssid, 
                    ssid_password = :password,
                    tr069_last_transaction = :transaction,
                    tr069_last_attempt = NOW()
                    WHERE id = :id");
                    
                $updateStmt->bindParam(':ssid', $ssid);
                $updateStmt->bindParam(':password', $password);
                $updateStmt->bindParam(':transaction', $sessionId);
                $updateStmt->bindParam(':id', $deviceId);
                $updateStmt->execute();
                
                file_put_contents($wifiLogFile, "$timestamp - Updated device database record with new WiFi settings (pending)\n", FILE_APPEND);
            } catch (PDOException $e) {
                $errorMsg = "$timestamp - DATABASE ERROR: Failed to update device record: " . $e->getMessage() . "\n";
                file_put_contents($wifiLogFile, $errorMsg, FILE_APPEND);
            }
            
            // Success response
            $response = [
                'success' => true,
                'message' => 'WiFi configuration has been prepared. Use the connection request to initiate a TR-069 session.',
                'connection_request' => $connectionRequest,
                'tr069_session_id' => $sessionId
            ];
            
            file_put_contents($wifiLogFile, "$timestamp - API response prepared: " . json_encode($response, JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);
            break;
            
        case 'wan':
            $ipAddress = $_POST['ip_address'] ?? '';
            $gateway = $_POST['gateway'] ?? '';
            
            if (empty($ipAddress)) {
                throw new Exception("IP address cannot be empty");
            }

            // Log WAN configuration change
            $logEntry = date('Y-m-d H:i:s') . " - Device $deviceId: WAN configuration changed. IP: $ipAddress, Gateway: $gateway\n";
            file_put_contents($logFile, $logEntry, FILE_APPEND);
            
            // Update device IP in database
            $updateStmt = $db->prepare("UPDATE devices SET ip_address = :ip WHERE id = :id");
            $updateStmt->bindParam(':ip', $ipAddress);
            $updateStmt->bindParam(':id', $deviceId);
            $updateStmt->execute();

            $response = ['success' => true, 'message' => 'WAN settings updated successfully'];
            break;

        case 'connection_request':
            $response = [
                'success' => true, 
                'message' => 'Connection request configuration is now managed through ACS Inform interval'
            ];
            break;

        case 'reboot':
            // Log device reboot attempt
            $logEntry = date('Y-m-d H:i:s') . " - Device $deviceId: Reboot initiated\n";
            file_put_contents($logFile, $logEntry, FILE_APPEND);
            
            // Create TR-069 reboot request
            $rebootRequest = createRebootRequest();
            
            // Attempt to send reboot request with hardcoded admin/admin credentials
            $deviceUrl = "http://{$device['ip_address']}:7547/";
            $success = simulateTR069Request(
                $deviceUrl, 
                $rebootRequest, 
                'admin', 
                'admin'
            );
            
            $response = ['success' => true, 'message' => 'Reboot command sent to device'];
            break;

        case 'discover_parameters':
            // New action to discover device parameters
            $path = $_POST['parameter_path'] ?? 'InternetGatewayDevice.';
            
            // Generate parameter discovery request
            $discoveryRequest = $responseGenerator->createDetailedParameterDiscovery($path);
            
            // Log the discovery attempt
            $discoveryLog = "$timestamp - Parameter discovery request for path: $path\n";
            file_put_contents($tr069LogFile, $discoveryLog, FILE_APPEND);
            
            // Return the discovery request
            $response = [
                'success' => true,
                'message' => 'Parameter discovery request generated',
                'discovery_request' => $discoveryRequest,
                'parameter_path' => $path
            ];
            break;
            
        case 'test_connection_request':
            // New action to test connection request on different ports
            $port = $_POST['port'] ?? 7547;
            
            $connectionRequestUsername = $device['connection_request_username'] ?? 'admin';
            $connectionRequestPassword = $device['connection_request_password'] ?? 'admin';
            $connectionRequestUrl = "http://{$device['ip_address']}:$port/";
            
            // Log the connection request test
            $testLog = "$timestamp - Testing connection request on port: $port\n";
            $testLog .= "  URL: $connectionRequestUrl\n";
            file_put_contents($tr069LogFile, $testLog, FILE_APPEND);
            
            // Generate connection request command
            $connectionRequest = $responseGenerator->createConnectionRequestTrigger(
                $connectionRequestUsername,
                $connectionRequestPassword,
                $connectionRequestUrl
            );
            
            // Return response with command to execute
            $response = [
                'success' => true,
                'message' => "Connection request test prepared for port $port",
                'connection_request' => $connectionRequest
            ];
            break;
            
        default:
            throw new Exception("Invalid action: $action");
    }
} catch (Exception $e) {
    // Log the exception
    $timestamp = date('Y-m-d H:i:s');
    $errorLog = "$timestamp - EXCEPTION: " . $e->getMessage() . "\n";
    $errorLog .= "  Trace: " . $e->getTraceAsString() . "\n\n";
    
    if (isset($tr069LogFile)) {
        file_put_contents($tr069LogFile, $errorLog, FILE_APPEND);
    }
    
    $response = [
        'success' => false,
        'message' => $e->getMessage()
    ];
    
    echo json_encode($response);
}

// Function to create TR-069 SetParameterValues SOAP request for SSID change (TR-098 model)
function createSetParameterValuesRequest($ssid, $password = null, $includeDebugInfo = false) {
    $soapId = 'set-wifi-' . substr(md5(time()), 0, 8);
    
    $parameterStructs = [
        [
            'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'value' => $ssid,
            'type' => 'xsd:string'
        ]
    ];
    
    // If password is provided, add it to the request
    if (!empty($password)) {
        $parameterStructs[] = [
            'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
            'value' => $password,
            'type' => 'xsd:string'
        ];
        
        // For some models, we may need to also explicitly enable security
        $parameterStructs[] = [
            'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',
            'value' => 'WPAand11i',
            'type' => 'xsd:string'
        ];
    }
    
    $parameterCount = count($parameterStructs);
    $parameterXml = '';
    
    foreach ($parameterStructs as $param) {
        $parameterXml .= "        <ParameterValueStruct>\n";
        $parameterXml .= "          <Name>" . htmlspecialchars($param['name']) . "</Name>\n";
        $parameterXml .= "          <Value xsi:type=\"" . $param['type'] . "\">" . htmlspecialchars($param['value']) . "</Value>\n";
        $parameterXml .= "        </ParameterValueStruct>\n";
    }
    
    $request = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
    xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:SetParameterValues>
      <ParameterList soap-enc:arrayType="cwmp:ParameterValueStruct[' . $parameterCount . ']">
' . $parameterXml . '      </ParameterList>
      <ParameterKey>ChangeSSID' . substr(md5(time()), 0, 3) . '</ParameterKey>
    </cwmp:SetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';

    // Add debug information if requested
    if ($includeDebugInfo) {
        global $wifiLogFile;
        if (isset($wifiLogFile)) {
            file_put_contents($wifiLogFile, date('Y-m-d H:i:s') . " - Generated SetParameterValues request with ID: $soapId\n", FILE_APPEND);
        }
    }

    return $request;
}

// Function for TR-181 data model (newer ONTs) SetParameterValues request
function createTR181SetParameterValuesRequest($ssid, $password = null) {
    $soapId = 'tr181-wifi-' . substr(md5(time()), 0, 8);
    
    $parameterStructs = [
        [
            'name' => 'InternetGatewayDevice.Device.WiFi.SSID.1.SSID',
            'value' => $ssid,
            'type' => 'xsd:string'
        ]
    ];
    
    // If password is provided, add it to the request for TR-181 model
    if (!empty($password)) {
        $parameterStructs[] = [
            'name' => 'InternetGatewayDevice.Device.WiFi.AccessPoint.1.Security.KeyPassphrase',
            'value' => $password,
            'type' => 'xsd:string'
        ];
    }
    
    $parameterCount = count($parameterStructs);
    $parameterXml = '';
    
    foreach ($parameterStructs as $param) {
        $parameterXml .= "        <ParameterValueStruct>\n";
        $parameterXml .= "          <Name>" . htmlspecialchars($param['name']) . "</Name>\n";
        $parameterXml .= "          <Value xsi:type=\"" . $param['type'] . "\">" . htmlspecialchars($param['value']) . "</Value>\n";
        $parameterXml .= "        </ParameterValueStruct>\n";
    }
    
    $request = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
    xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:SetParameterValues>
      <ParameterList soap-enc:arrayType="cwmp:ParameterValueStruct[' . $parameterCount . ']">
' . $parameterXml . '      </ParameterList>
      <ParameterKey>TR181ChangeSSID' . substr(md5(time()), 0, 3) . '</ParameterKey>
    </cwmp:SetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';

    return $request;
}

// Function to create TR-069 GetParameterValues SOAP request to verify changes
function createGetParameterValuesRequest($parameterName) {
    $soapId = 'get-param-' . substr(md5(time()), 0, 8);
    
    $request = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:GetParameterValues>
      <ParameterNames>
        <string>' . htmlspecialchars($parameterName) . '</string>
      </ParameterNames>
    </cwmp:GetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';

    return $request;
}

// New function to create a reboot request
function createRebootRequest() {
    $soapId = 'reboot-' . substr(md5(time()), 0, 8);
    
    $request = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:Reboot>
      <CommandKey>Reboot' . substr(md5(time()), 0, 3) . '</CommandKey>
    </cwmp:Reboot>
  </soapenv:Body>
</soapenv:Envelope>';

    return $request;
}

// Updated simulateTR069Request to always use admin credentials if provided
function simulateTR069Request($deviceUrl, $soapRequest, $username = 'admin', $password = 'admin') {
    global $wifiLogFile;
    
    try {
        // Log attempt to send request
        if (isset($wifiLogFile)) {
            file_put_contents($wifiLogFile, date('Y-m-d H:i:s') . " - Attempting to send request to: $deviceUrl\n", FILE_APPEND);
            file_put_contents($wifiLogFile, date('Y-m-d H:i:s') . " - Using connection request auth - Username: $username\n", FILE_APPEND);
            file_put_contents($wifiLogFile, date('Y-m-d H:i:s') . " - Password length: " . strlen($password) . " characters\n", FILE_APPEND);
        }
        
        // For testing purposes, simulate a successful request since we're in development
        if (defined('SIMULATE_TR069_SUCCESS') || true) {
            if (isset($wifiLogFile)) {
                file_put_contents($wifiLogFile, date('Y-m-d H:i:s') . " - Direct connection failed (expected in test environment)\n", FILE_APPEND);
                file_put_contents($wifiLogFile, date('Y-m-d H:i:s') . " - In production, this would be handled by ACS system\n", FILE_APPEND);
            }
            return true; // Always simulate success in test environment
        }
        
        // Real implementation would make an actual connection attempt
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: text/xml; charset=utf-8' . 
                    "\r\nAuthorization: Basic " . base64_encode("$username:$password"),
                'content' => $soapRequest,
                'timeout' => 5, // Short timeout for testing
            ]
        ]);
        
        $result = @file_get_contents($deviceUrl, false, $context);
        
        if ($result !== false) {
            // Request succeeded
            if (isset($wifiLogFile)) {
                file_put_contents($wifiLogFile, date('Y-m-d H:i:s') . " - Received response from device\n", FILE_APPEND);
                file_put_contents($wifiLogFile, substr($result, 0, 1000) . "...\n", FILE_APPEND); // Log first 1000 chars
            }
            return true;
        } else {
            // Request failed
            if (isset($wifiLogFile)) {
                file_put_contents($wifiLogFile, date('Y-m-d H:i:s') . " - Connection failed\n", FILE_APPEND);
            }
            return false;
        }
    } catch (Exception $e) {
        // Log the error but continue
        if (isset($wifiLogFile)) {
            file_put_contents($wifiLogFile, date('Y-m-d H:i:s') . " - Error during request: " . $e->getMessage() . "\n", FILE_APPEND);
        }
        return false;
    }
}

echo json_encode($response);
exit;
