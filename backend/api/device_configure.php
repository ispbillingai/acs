<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions/device_functions.php';
require_once __DIR__ . '/../tr069/responses/InformResponseGenerator.php';
require_once __DIR__ . '/../tr069/auth/AuthenticationHandler.php';

$response = ['success' => false, 'message' => 'Invalid request'];

// Set path for the main device log file
$deviceLogFile = __DIR__ . '/../../device.log';
$tr069CommunicationLogFile = __DIR__ . '/../../tr069_communications.log';

// Helper function to write to the device log
function writeToDeviceLog($message) {
    global $deviceLogFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($deviceLogFile, "$timestamp - $message\n", FILE_APPEND);
}

// Helper function to log TR-069 SOAP messages
function logTR069Communication($message, $direction = 'OUT', $xmlContent = null) {
    global $tr069CommunicationLogFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "$timestamp - [$direction] $message\n";
    
    if ($xmlContent) {
        $logEntry .= "--- XML Content ---\n$xmlContent\n-------------------\n";
    }
    
    file_put_contents($tr069CommunicationLogFile, $logEntry, FILE_APPEND);
    
    // Also log important operations to main device log
    if ($direction === 'OUT' && strpos($message, 'SetParameterValues') !== false) {
        writeToDeviceLog("TR-069 SENT: $message");
    } elseif ($direction === 'IN' && strpos($message, 'SetParameterValuesResponse') !== false) {
        writeToDeviceLog("TR-069 RECEIVED: $message");
    }
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $deviceId = $_POST['device_id'] ?? null;
    $action = $_POST['action'] ?? null;

    if (!$deviceId) {
        throw new Exception("Device ID is required");
    }

    // Create logs directory if it doesn't exist
    if (!file_exists(dirname($deviceLogFile))) {
        mkdir(dirname($deviceLogFile), 0755, true);
    }
    
    // Log the incoming request with detailed information
    $timestamp = date('Y-m-d H:i:s');
    writeToDeviceLog("FRONTEND ACTION: $action requested for device $deviceId");
    
    // Get device information from database
    $stmt = $db->prepare("SELECT * FROM devices WHERE id = :id");
    $stmt->bindParam(':id', $deviceId);
    $stmt->execute();
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$device) {
        writeToDeviceLog("ERROR: Device not found with ID $deviceId");
        throw new Exception("Device not found");
    }

    // Log device information for important operations
    writeToDeviceLog("Device: {$device['serial_number']} ({$device['manufacturer']} {$device['model_name']}) - IP: {$device['ip_address']}");
    
    // Create ResponseGenerator for TR-069 SOAP messages
    $responseGenerator = new InformResponseGenerator();
    
    // Create AuthenticationHandler for device authentication
    $authHandler = new AuthenticationHandler();
    
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
            
            writeToDeviceLog("Retrieved current settings for device {$device['serial_number']}");
            break;
            
        case 'wifi':
            $ssid = $_POST['ssid'] ?? '';
            $password = $_POST['password'] ?? '';
            
            if (empty($ssid)) {
                writeToDeviceLog("ERROR: Empty SSID provided for device {$device['serial_number']}");
                throw new Exception("SSID cannot be empty");
            }
            
            // Log detailed WiFi configuration change
            writeToDeviceLog("USER ACTION: WiFi Configuration Change - Device: {$device['serial_number']}");
            writeToDeviceLog("PARAMETER SET: SSID changed from '{$device['ssid']}' to '$ssid'");
            if (!empty($password)) {
                writeToDeviceLog("PARAMETER SET: WiFi password changed (length: " . strlen($password) . " chars)");
            }
            
            // Generate session ID for this transaction
            $sessionId = 'wifi-' . substr(md5(time() . $deviceId), 0, 12);
            
            // Determine if we're dealing with a Huawei HG8546M specifically
            $isHuaweiHg8546 = (stripos($device['manufacturer'], 'huawei') !== false && 
                                stripos($device['model_name'], 'hg8546') !== false);
            
            // Create TR-069 parameters with correct paths
            $parameterStructs = [
                [
                    'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                    'value' => $ssid,
                    'type' => 'xsd:string'
                ]
            ];
            
            // Add password parameter if provided, using the correct parameter path based on device model
            if (!empty($password)) {
                if ($isHuaweiHg8546) {
                    // Use the Huawei HG8546M specific path for password
                    $parameterStructs[] = [
                        'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
                        'value' => $password,
                        'type' => 'xsd:string'
                    ];
                    
                    writeToDeviceLog("Using Huawei HG8546M specific parameter path for password");
                } else {
                    // Try the standard KeyPassphrase parameter
                    $parameterStructs[] = [
                        'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
                        'value' => $password,
                        'type' => 'xsd:string'
                    ];
                }
                
                // Ensure WPA security is enabled
                $parameterStructs[] = [
                    'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',
                    'value' => 'WPAand11i',
                    'type' => 'xsd:string'
                ];
                
                // Ensure WPA encryption is set correctly
                $parameterStructs[] = [
                    'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.WPAEncryptionModes',
                    'value' => 'AESEncryption',
                    'type' => 'xsd:string'
                ];
            }
            
            // Create complete TR-069 SOAP message
            $soapRequest = $responseGenerator->createDetailedSetParameterValuesRequest($sessionId, $parameterStructs);
            
            // Log the SOAP request
            logTR069Communication("SetParameterValues request for WiFi configuration (Session: $sessionId)", "OUT", $soapRequest);
            
            // Log the workflow session
            writeToDeviceLog("TR-069 SESSION STARTED: ID=$sessionId for WiFi configuration");
            foreach ($parameterStructs as $param) {
                $paramValue = $param['name'] === 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase' || 
                              $param['name'] === 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase' ?
                              '[password]' : $param['value'];
                writeToDeviceLog("TR-069 PARAMETER SET: {$param['name']}=$paramValue");
            }
            
            // Now actually try to connect to the router using the appropriate port
            // Common TR-069 ports to try
            $portsToTry = [7547, 30005, 37215, 4567, 8080];
            $connectionSuccess = false;
            $connectionAttempted = false;
            $finalPort = 0;
            
            // Get the device IP
            $deviceIp = $device['ip_address'];
            
            // For Huawei HG8546M, add more specific information for debugging
            if ($isHuaweiHg8546) {
                writeToDeviceLog("DEVICE MODEL: Huawei HG8546M detected - Using specific TR-069 paths");
            }

            // Authentication credentials - try device-specific if available, otherwise use defaults
            $username = $device['connection_request_username'] ?? 'admin';
            $password = $device['connection_request_password'] ?? 'admin';
            
            writeToDeviceLog("TR-069 DIRECT ATTEMPT: Trying to connect directly to device at $deviceIp");
            
            foreach ($portsToTry as $port) {
                $connectionAttempted = true;
                $deviceUrl = "http://{$deviceIp}:{$port}/";
                
                writeToDeviceLog("TR-069 CONNECTION ATTEMPT: Trying port $port at $deviceIp");
                logTR069Communication("Attempting connection to $deviceUrl with credentials $username:***", "OUT");
                
                try {
                    // Using the improved function from device_functions.php to attempt TR-069 request
                    if (makeTR069Request($deviceIp, $port, $soapRequest, $username, $password)) {
                        $connectionSuccess = true;
                        $finalPort = $port;
                        writeToDeviceLog("TR-069 CONNECTION SUCCESS: Successfully connected to device on port $port");
                        break;
                    } else {
                        writeToDeviceLog("TR-069 CONNECTION FAILED: Could not connect to device on port $port");
                    }
                } catch (Exception $e) {
                    writeToDeviceLog("TR-069 CONNECTION ERROR on port $port: " . $e->getMessage());
                }
            }
            
            // If no connection could be established on common ports, try raw HTTP connection
            if (!$connectionSuccess && $connectionAttempted) {
                writeToDeviceLog("TR-069 FALLBACK: Trying raw HTTP connection to various ports");
                
                // Try using cURL directly for more control
                foreach ($portsToTry as $port) {
                    $deviceUrl = "http://{$deviceIp}:{$port}/";
                    
                    // Create a cURL request
                    $ch = curl_init($deviceUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: text/xml; charset=utf-8',
                        'Authorization: Basic ' . base64_encode("$username:$password")
                    ]);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $soapRequest);
                    
                    writeToDeviceLog("TR-069 CURL ATTEMPT: Trying port $port with cURL");
                    
                    $result = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if ($result !== false && $httpCode >= 200 && $httpCode < 300) {
                        $connectionSuccess = true;
                        $finalPort = $port;
                        writeToDeviceLog("TR-069 CURL SUCCESS: Connected to device on port $port (HTTP $httpCode)");
                        logTR069Communication("CURL Response from $deviceUrl", "IN", $result);
                        break;
                    } else {
                        writeToDeviceLog("TR-069 CURL FAILED: Could not connect to device on port $port (HTTP $httpCode)");
                    }
                }
            }
            
            // Update device in database with new WiFi settings (pending)
            try {
                // Check if the new columns exist in the table
                $columnsExist = true;
                try {
                    $columnCheck = $db->query("SHOW COLUMNS FROM devices LIKE 'tr069_last_transaction'");
                    if ($columnCheck->rowCount() == 0) {
                        $columnsExist = false;
                    }
                } catch (Exception $e) {
                    $columnsExist = false;
                }
                
                // Define the SQL statement based on whether the columns exist
                if ($columnsExist) {
                    $sql = "UPDATE devices SET 
                        ssid = :ssid, 
                        ssid_password = :password,
                        tr069_last_transaction = :transaction,
                        tr069_last_attempt = NOW()
                        WHERE id = :id";
                } else {
                    $sql = "UPDATE devices SET 
                        ssid = :ssid, 
                        ssid_password = :password
                        WHERE id = :id";
                }
                
                $updateStmt = $db->prepare($sql);
                $updateStmt->bindParam(':ssid', $ssid);
                $updateStmt->bindParam(':password', $_POST['password'] ?? '');
                if ($columnsExist) {
                    $updateStmt->bindParam(':transaction', $sessionId);
                }
                $updateStmt->bindParam(':id', $deviceId);
                $updateStmt->execute();
                
                writeToDeviceLog("DATABASE UPDATED: Stored new WiFi settings for device {$device['serial_number']}");
            } catch (PDOException $e) {
                writeToDeviceLog("DATABASE ERROR: Failed to update device record: " . $e->getMessage());
                throw $e;
            }
            
            // Success response
            if ($connectionSuccess) {
                $response = [
                    'success' => true,
                    'message' => "WiFi configuration successfully sent to device on port $finalPort.",
                    'connection_status' => 'success',
                    'tr069_session_id' => $sessionId,
                    'connection_details' => [
                        'device_ip' => $deviceIp,
                        'port_used' => $finalPort,
                        'parameters_sent' => count($parameterStructs)
                    ]
                ];
                writeToDeviceLog("TR-069 SUCCESS: WiFi configuration sent to device {$device['serial_number']} on port $finalPort");
            } else {
                $response = [
                    'success' => true, // Still return success since we updated the database
                    'message' => 'WiFi configuration has been saved but could not connect to the device directly.',
                    'connection_status' => 'failed',
                    'tr069_session_id' => $sessionId,
                    'debug_info' => [
                        'attempted_parameters' => array_map(function($p) {
                            return $p['name'] . '=' . ($p['name'] === 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase' || 
                                                      $p['name'] === 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase' ? 
                                                     '[HIDDEN]' : $p['value']);
                        }, $parameterStructs),
                        'ports_tried' => $portsToTry,
                        'device_ip' => $deviceIp
                    ]
                ];
                writeToDeviceLog("TR-069 WARNING: WiFi configuration prepared but could not connect to device {$device['serial_number']}");
            }
            
            writeToDeviceLog("FRONTEND RESPONSE: WiFi configuration process completed for device {$device['serial_number']}");
            break;
            
        case 'wan':
            $ipAddress = $_POST['ip_address'] ?? '';
            $gateway = $_POST['gateway'] ?? '';
            
            if (empty($ipAddress)) {
                throw new Exception("IP address cannot be empty");
            }

            // Log WAN configuration change
            writeToDeviceLog("USER ACTION: WAN Configuration Change - Device: {$device['serial_number']}");
            writeToDeviceLog("PARAMETER SET: IP Address changed from '{$device['ip_address']}' to '$ipAddress'");
            if (!empty($gateway)) {
                writeToDeviceLog("PARAMETER SET: Default Gateway changed to '$gateway'");
            }
            
            // Update device IP in database
            $updateStmt = $db->prepare("UPDATE devices SET ip_address = :ip WHERE id = :id");
            $updateStmt->bindParam(':ip', $ipAddress);
            $updateStmt->bindParam(':id', $deviceId);
            $updateStmt->execute();
            
            writeToDeviceLog("DATABASE UPDATED: Stored new WAN settings for device {$device['serial_number']}");
            $response = ['success' => true, 'message' => 'WAN settings updated successfully'];
            break;

        case 'connection_request':
            writeToDeviceLog("USER ACTION: Connection Request Config Change - Device: {$device['serial_number']}");
            $response = [
                'success' => true, 
                'message' => 'Connection request configuration is now managed through ACS Inform interval'
            ];
            break;

        case 'reboot':
            // Log device reboot attempt
            writeToDeviceLog("USER ACTION: Device Reboot - Device: {$device['serial_number']}");
            
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
            
            writeToDeviceLog("TR-069 COMMAND: Reboot request sent to device {$device['serial_number']}");
            $response = ['success' => true, 'message' => 'Reboot command sent to device'];
            break;

        case 'discover_parameters':
            // New action to discover device parameters
            $path = $_POST['parameter_path'] ?? 'InternetGatewayDevice.';
            
            // Generate parameter discovery request
            $discoveryRequest = $responseGenerator->createDetailedParameterDiscovery($path);
            
            // Log the discovery attempt
            writeToDeviceLog("USER ACTION: Parameter Discovery - Device: {$device['serial_number']}, Path: $path");
            
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
            writeToDeviceLog("USER ACTION: Connection Request Test - Device: {$device['serial_number']}, Port: $port");
            
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
            writeToDeviceLog("ERROR: Invalid action requested: $action");
            throw new Exception("Invalid action: $action");
    }
} catch (Exception $e) {
    // Log the exception
    $timestamp = date('Y-m-d H:i:s');
    writeToDeviceLog("ERROR: " . $e->getMessage());
    
    $response = [
        'success' => false,
        'message' => $e->getMessage()
    ];
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
    global $deviceLogFile, $tr069CommunicationLogFile;
    
    try {
        // Log attempt to send request
        writeToDeviceLog("TR-069 REQUEST: Sending to $deviceUrl");
        logTR069Communication("Attempting connection to $deviceUrl", "OUT");
        
        // For testing purposes, we'll attempt a real connection
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: text/xml; charset=utf-8' . 
                    "\r\nAuthorization: Basic " . base64_encode("$username:$password"),
                'content' => $soapRequest,
                'timeout' => 10, // Increased timeout for real-world network conditions
                'ignore_errors' => true, // Capture error responses
            ]
        ]);
        
        // Log request data
        logTR069Communication("TR-069 Request Headers", "OUT", print_r(get_headers($deviceUrl, 1), true));
        
        // Attempt the actual connection
        $result = @file_get_contents($deviceUrl, false, $context);
        
        // Get response code
        if (isset($http_response_header)) {
            $statusLine = $http_response_header[0];
            preg_match('{HTTP/\S*\s(\d+)}', $statusLine, $match);
            $statusCode = $match[1] ?? 'unknown';
            
            // Log response headers
            logTR069Communication("TR-069 Response Headers (Status: $statusCode)", "IN", print_r($http_response_header, true));
        } else {
            $statusCode = 'connection failed';
        }
        
        if ($result !== false) {
            // Request succeeded
            writeToDeviceLog("TR-069 RESPONSE: Received response from device (Status: $statusCode)");
            logTR069Communication("TR-069 Response Body", "IN", $result);
            
            // Check for fault in the response
            if (stripos($result, '<SOAP-ENV:Fault>') !== false || stripos($result, '<cwmp:Fault>') !== false) {
                preg_match('/<FaultCode>(.*?)<\/FaultCode>.*?<FaultString>(.*?)<\/FaultString>/s', $result, $faultMatches);
                if (!empty($faultMatches)) {
                    $faultCode = $faultMatches[1] ?? 'Unknown';
                    $faultString = $faultMatches[2] ?? 'Unknown error';
                    writeToDeviceLog("TR-069 FAULT: Code=$faultCode, Message=$faultString");
                    return false;
                }
            }
            
            return true;
        } else {
            // Request failed
            writeToDeviceLog("TR-069 ERROR: Connection failed or returned error (Status: $statusCode)");
            logTR069Communication("TR-069 Connection Failed", "ERROR", "Status: $statusCode");
            return false;
        }
    } catch (Exception $e) {
        // Log the error but continue
        writeToDeviceLog("TR-069 ERROR: " . $e->getMessage());
        logTR069Communication("TR-069 Exception", "ERROR", $e->getMessage());
        return false;
    }
}

// Updated function to enable enhanced logging in InformResponseGenerator
InformResponseGenerator::$enableDetailedLogging = true;

// Add a new helper function to attempt TR-069 parameter changes directly
function attemptDirectParameterChange($deviceUrl, $soapRequest, $username = 'admin', $password = 'admin') {
    // Use enhanced simulateTR069Request with detailed logging
    return simulateTR069Request($deviceUrl, $soapRequest, $username, $password);
}

echo json_encode($response);
exit;
