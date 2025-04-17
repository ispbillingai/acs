
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
    
    // Get device information from database
    $stmt = $db->prepare("SELECT * FROM devices WHERE id = :id");
    $stmt->bindParam(':id', $deviceId);
    $stmt->execute();
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$device) {
        throw new Exception("Device not found");
    }
    
    // Create logs directory if it doesn't exist
    if (!file_exists(dirname($logFile))) {
        mkdir(dirname($logFile), 0755, true);
    }

    switch ($action) {
        case 'get_settings':
            // Return current device settings
            $response = [
                'success' => true, 
                'settings' => [
                    'ssid' => $device['ssid'] ?? '',
                    'password' => $device['ssid_password'] ?? '',
                    'ip_address' => $device['ip_address'] ?? '',
                    'gateway' => ''  // Get from database if available
                ]
            ];
            break;
            
        case 'wifi':
            $ssid = $_POST['ssid'] ?? '';
            $password = $_POST['password'] ?? '';
            
            if (empty($ssid)) {
                throw new Exception("SSID cannot be empty");
            }
            
            // Log WiFi configuration change attempt
            $logEntry = date('Y-m-d H:i:s') . " - Device $deviceId: WiFi configuration change attempted. SSID: $ssid\n";
            file_put_contents($logFile, $logEntry, FILE_APPEND);
            
            // Create TR-069 SOAP request to change SSID
            $tr069Request = createSetParameterValuesRequest($ssid, $password);
            
            // Log the generated TR-069 request
            file_put_contents($logFile, date('Y-m-d H:i:s') . " - TR-069 Request: " . $tr069Request . "\n", FILE_APPEND);
            
            // In a real implementation, we would send this request to the device
            // But for this demo, we'll simulate success and update the database
            
            // Update device record in database
            $updateStmt = $db->prepare("UPDATE devices SET ssid = :ssid, ssid_password = :password WHERE id = :id");
            $updateStmt->bindParam(':ssid', $ssid);
            $updateStmt->bindParam(':password', $password);
            $updateStmt->bindParam(':id', $deviceId);
            $updateStmt->execute();
            
            $response = ['success' => true, 'message' => 'WiFi settings updated successfully'];
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

        case 'reboot':
            // Log device reboot attempt
            $logEntry = date('Y-m-d H:i:s') . " - Device $deviceId: Reboot initiated\n";
            file_put_contents($logFile, $logEntry, FILE_APPEND);
            
            // Create TR-069 reboot request (not implemented in this example)
            // In a real environment, this would send a Reboot request to the device
            
            $response = ['success' => true, 'message' => 'Reboot command sent to device'];
            break;

        default:
            throw new Exception("Invalid action: $action");
    }
} catch (Exception $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
    
    // Log the error
    if (isset($logFile) && isset($deviceId)) {
        $errorEntry = date('Y-m-d H:i:s') . " - Device $deviceId: ERROR - " . $e->getMessage() . "\n";
        file_put_contents($logFile, $errorEntry, FILE_APPEND);
    }
}

// Function to create TR-069 SetParameterValues SOAP request for SSID change
function createSetParameterValuesRequest($ssid, $password = null) {
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
    <cwmp:ID>' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:SetParameterValues>
      <ParameterList soap-enc:arrayType="cwmp:ParameterValueStruct[' . $parameterCount . ']">
' . $parameterXml . '      </ParameterList>
      <ParameterKey>ChangeSSID' . substr(md5(time()), 0, 3) . '</ParameterKey>
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
    <cwmp:ID>' . $soapId . '</cwmp:ID>
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

echo json_encode($response);
exit;
