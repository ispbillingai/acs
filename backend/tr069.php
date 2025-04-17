
<?php
/* -----------------------------------------------------------------
 *  TR‑069 endpoint - enhanced with direct parameter retrieval
 * ----------------------------------------------------------------*/

error_reporting(E_ALL);
ini_set('display_errors', 0);  // <-- change to 1 only while debugging
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/apache2/tr069_error.log'); // Ensure Apache can write to this file

// Include the XMLGenerator class
require_once __DIR__ . '/tr069/core/XMLGenerator.php';

// Create retrieve.log if it doesn't exist
$retrieveLog = __DIR__ . '/retrieve.log';
if (!file_exists($retrieveLog)) {
    touch($retrieveLog);
    chmod($retrieveLog, 0666); // Make writable
}

// Log function that writes to error_log, device.log and retrieve.log
function tr069_debug_log($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[$timestamp] [TR-069] [$level] $message";
    
    // Always log to error_log
    error_log($formatted);
    
    // Also log to device.log
    $log_file = __DIR__ . '/device.log';
    file_put_contents($log_file, $formatted . "\n", FILE_APPEND);
    
    // NEW - Also log to retrieve.log
    $retrieve_log = __DIR__ . '/retrieve.log';
    file_put_contents($retrieve_log, $formatted . "\n", FILE_APPEND);
}

/* ---------- main entry ----------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tr069_debug_log("Non-POST request received and ignored", "WARNING");
    exit;    // CPEs always POST
}

$raw = file_get_contents('php://input');
$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
$rawLength = strlen($raw);

tr069_debug_log("TR‑069 raw LEN=$rawLength (Content-Length=$contentLength) head=" . substr($raw, 0, 120), "DEBUG");

// Save the complete raw request for debugging
$requestLogFile = __DIR__ . '/tr069_request_' . date('Ymd_His') . '.xml';
file_put_contents($requestLogFile, $raw);
tr069_debug_log("Saved complete request to: $requestLogFile", "DEBUG");

// NEW - Also save to retrieve.log
file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " RAW REQUEST:\n" . $raw . "\n", FILE_APPEND);

/* ---- INFORM ----------------------------------------------------*/
if (stripos($raw, '<cwmp:Inform>') !== false) {
    tr069_debug_log("Detected Inform message", "INFO");
    
    // extract the cwmp:ID
    preg_match('~<cwmp:ID [^>]*>(.*?)</cwmp:ID>~', $raw, $m);
    $soapId = $m[1] ?? '1';
    tr069_debug_log("Extracted SOAP ID: $soapId", "DEBUG");

    // Extract serial number for logging
    preg_match('~<SerialNumber>(.*?)</SerialNumber>~s', $raw, $serial);
    $serialNumber = isset($serial[1]) ? trim($serial[1]) : 'unknown';
    tr069_debug_log("Device with serial $serialNumber sent Inform", "INFO");

    // Log to retrieve.log about the Inform
    file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " INFORM received from device: $serialNumber\n", FILE_APPEND);

    // pick parameters we always want
    $need = [
        'InternetGatewayDevice.DeviceInfo.UpTime',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
        'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
        'InternetGatewayDevice.DeviceInfo.HardwareVersion'
    ];
    tr069_debug_log("Requesting parameters: " . implode(', ', $need), "DEBUG");
    file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " Requesting parameters: " . implode(', ', $need) . "\n", FILE_APPEND);

    // After Inform, we want to immediately request GetParameterValues
    // BUT we can't do that directly, so we send a standard InformResponse
    // and then handle the follow-up empty POST with the GetParameterValues
    
    // Send standard InformResponse
    $informResponse = XMLGenerator::generateInformResponseXML($soapId);
    
    // Log what we're sending
    file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " Sending standard InformResponse\n", FILE_APPEND);
    file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " Response:\n" . $informResponse . "\n", FILE_APPEND);
    
    header('Content-Type: text/xml'); 
    echo $informResponse; 
    exit;
}

/* ---- GetParameterValuesResponse --------------------------------*/
if (stripos($raw, '<cwmp:GetParameterValuesResponse') !== false) {
    tr069_debug_log("Received GetParameterValuesResponse", "INFO");
    tr069_debug_log("GPV‑RESPONSE head=" . substr($raw, 0, 200), "DEBUG");
    
    // Save the complete response for analysis
    $responseLogFile = __DIR__ . '/tr069_gpv_response_' . date('Ymd_His') . '.xml';
    file_put_contents($responseLogFile, $raw);
    tr069_debug_log("Saved GPV response to: $responseLogFile", "DEBUG");
    
    // NEW - Also save to retrieve.log
    file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " PARAMETER RESPONSE RECEIVED:\n" . $raw . "\n", FILE_APPEND);
    
    // Extract and log parameters for debugging
    preg_match_all('/<ParameterValueStruct>\s*<Name>(.*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>\s*<\/ParameterValueStruct>/s', $raw, $params, PREG_SET_ORDER);
    
    if (!empty($params)) {
        tr069_debug_log("Found " . count($params) . " parameters in response", "INFO");
        file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " Found " . count($params) . " parameters in response\n", FILE_APPEND);
        
        foreach ($params as $param) {
            $name = trim($param[1]);
            $value = trim($param[2]);
            tr069_debug_log("Parameter: $name = $value", "DEBUG");
            file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " Parameter: $name = $value\n", FILE_APPEND);
            
            // Store parameters in the database
            try {
                require_once __DIR__ . '/config/database.php';
                $database = new Database();
                $db = $database->getConnection();
                
                // Get device ID
                $stmt = $db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
                $stmt->execute([':serial' => $serialNumber]);
                $deviceId = $stmt->fetchColumn();
                
                if ($deviceId) {
                    // Store parameter
                    $stmt = $db->prepare("
                        INSERT INTO parameters (device_id, param_name, param_value, created_at, updated_at)
                        VALUES (:deviceId, :paramName, :paramValue, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE param_value = :paramValue, updated_at = NOW()
                    ");
                    
                    $stmt->execute([
                        ':deviceId' => $deviceId,
                        ':paramName' => $name,
                        ':paramValue' => $value
                    ]);
                    
                    file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " Stored parameter in database: $name\n", FILE_APPEND);
                    
                    // Update specific device fields
                    if (strpos($name, '.UpTime') !== false) {
                        $db->prepare("UPDATE devices SET uptime = ? WHERE id = ?")->execute([(int)$value, $deviceId]);
                        file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " Updated device uptime: $value\n", FILE_APPEND);
                    } else if (strpos($name, '.SSID') !== false) {
                        $db->prepare("UPDATE devices SET ssid = ? WHERE id = ?")->execute([$value, $deviceId]);
                        file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " Updated device SSID: $value\n", FILE_APPEND);
                    } else if (strpos($name, '.ExternalIPAddress') !== false) {
                        $db->prepare("UPDATE devices SET ip_address = ? WHERE id = ?")->execute([$value, $deviceId]);
                        file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " Updated device IP: $value\n", FILE_APPEND);
                    } else if (strpos($name, '.SoftwareVersion') !== false) {
                        $db->prepare("UPDATE devices SET software_version = ? WHERE id = ?")->execute([$value, $deviceId]);
                        file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " Updated device software: $value\n", FILE_APPEND);
                    } else if (strpos($name, '.HardwareVersion') !== false) {
                        $db->prepare("UPDATE devices SET hardware_version = ? WHERE id = ?")->execute([$value, $deviceId]);
                        file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " Updated device hardware: $value\n", FILE_APPEND);
                    }
                }
            } catch (Exception $e) {
                tr069_debug_log("Error storing parameter: " . $e->getMessage(), "ERROR");
                file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " ERROR storing parameter: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    } else {
        tr069_debug_log("No parameters found in GetParameterValuesResponse", "WARNING");
        file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " WARNING: No parameters found in response\n", FILE_APPEND);
    }
    
    // Extract SOAP ID
    preg_match('~<cwmp:ID [^>]*>(.*?)</cwmp:ID>~', $raw, $m);
    $soapId = $m[1] ?? uniqid();
    
    // Return empty response
    $emptyResponse = XMLGenerator::generateEmptyResponse($soapId);
    header('Content-Type: text/xml'); 
    echo $emptyResponse; 
    exit;
}

/* ---- any other SOAP (SetParameterValuesResponse etc.) ----------*/
if (stripos($raw, 'SOAP') !== false) {
    tr069_debug_log("Received unhandled SOAP message type, first 100 chars: " . substr($raw, 0, 100), "INFO");
    file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " Unhandled SOAP message: " . substr($raw, 0, 100) . "\n", FILE_APPEND);
    
    // Extract SOAP ID if possible
    preg_match('~<cwmp:ID [^>]*>(.*?)</cwmp:ID>~', $raw, $m);
    $soapId = $m[1] ?? uniqid();
    
    header('Content-Type: text/xml'); 
    echo XMLGenerator::generateEmptyResponse($soapId); 
    exit;
}

// For empty POST requests, try to detect device based on HTTP headers
if (empty($raw) || $rawLength < 10) {
    tr069_debug_log("Empty or near-empty POST received, content length: $rawLength", "WARNING");
    file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " Empty POST received (len: $rawLength)\n", FILE_APPEND);
    
    // Log all headers for debugging
    $headers = getallheaders();
    foreach ($headers as $name => $value) {
        tr069_debug_log("Header: $name = $value", "DEBUG");
        file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " Header: $name = $value\n", FILE_APPEND);
    }
    
    // Check if there's a device serial in the User-Agent
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    tr069_debug_log("User-Agent: $userAgent", "DEBUG");
    
    // After the Inform, the device sends an empty request to ask "what next?"
    // This is our chance to send the GetParameterValues request
    $need = [
        'InternetGatewayDevice.DeviceInfo.UpTime',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
        'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
        'InternetGatewayDevice.DeviceInfo.HardwareVersion'
    ];
    
    $getParamRequest = XMLGenerator::generateFullGetParameterValuesRequestXML(uniqid(), $need);
    tr069_debug_log("Sending GetParameterValues request for empty POST", "INFO");
    file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " Sending GetParameterValues request after empty POST\n", FILE_APPEND);
    file_put_contents(__DIR__ . '/retrieve.log', date('Y-m-d H:i:s') . " Request:\n" . $getParamRequest . "\n", FILE_APPEND);
    
    header('Content-Type: text/xml'); 
    echo $getParamRequest; 
    exit;
}

header('Content-Type: text/xml'); 
echo XMLGenerator::generateEmptyResponse(uniqid()); 
exit;
