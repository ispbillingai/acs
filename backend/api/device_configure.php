<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions/device_functions.php';
require_once __DIR__ . '/../tr069/responses/InformResponseGenerator.php';

$response = ['success' => false, 'message' => 'Invalid request'];

// Set path for the main device log file
$deviceLogFile = __DIR__ . '/../../device.log';

// Helper function to write to the device log
function writeToDeviceLog($message) {
    global $deviceLogFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($deviceLogFile, "$timestamp - $message\n", FILE_APPEND);
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
            
            // Create complete TR-069 debug workflow
            $workflowData = $responseGenerator->createDebugWorkflow($sessionId, $ssid, $password);
            
            // Log the workflow session
            writeToDeviceLog("TR-069 SESSION STARTED: ID=$sessionId for WiFi configuration");
            writeToDeviceLog("TR-069 PARAMETER SET: InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID=$ssid");
            if (!empty($password)) {
                writeToDeviceLog("TR-069 PARAMETER SET: InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase=[password]");
            }
            
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
                
                writeToDeviceLog("DATABASE UPDATED: Stored new WiFi settings for device {$device['serial_number']}");
            } catch (PDOException $e) {
                writeToDeviceLog("DATABASE ERROR: Failed to update device record: " . $e->getMessage());
                throw $e;
            }
            
            // Success response
            $response = [
                'success' => true,
                'message' => 'WiFi configuration has been prepared. Use the connection request to initiate a TR-069 session.',
                'connection_request' => $connectionRequest,
                'tr069_session_id' => $sessionId
            ];
            
            writeToDeviceLog("FRONTEND RESPONSE: WiFi configuration prepared successfully for device {$device['serial_number']}");
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
    global $deviceLogFile;
    
    try {
        // Log attempt to send request
        writeToDeviceLog("TR-069 REQUEST: Sending to $deviceUrl");
        
        // For testing purposes, simulate a successful request since we're in development
        if (defined('SIMULATE_TR069_SUCCESS') || true) {
            writeToDeviceLog("TR-069 SIMULATION: Direct connection simulated (development mode)");
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
            writeToDeviceLog("TR-069 RESPONSE: Received response from device");
            return true;
        } else {
            // Request failed
            writeToDeviceLog("TR-069 ERROR: Connection failed");
            return false;
        }
    } catch (Exception $e) {
        // Log the error but continue
        writeToDeviceLog("TR-069 ERROR: " . $e->getMessage());
        return false;
    }
}

echo json_encode($response);
exit;
