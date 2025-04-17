
<?php
/* -----------------------------------------------------------------
 *  ultra‑minimal TR‑069 endpoint – single file demo
 * ----------------------------------------------------------------*/

error_reporting(E_ALL);
ini_set('display_errors', 0);  // <-- change to 1 only while debugging
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/apache2/tr069_error.log'); // Ensure Apache can write to this file

// Include the XMLGenerator class
require_once __DIR__ . '/tr069/core/XMLGenerator.php';

// Log function that writes to error_log and optionally to a custom file
function tr069_debug_log($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[$timestamp] [TR-069] [$level] $message";
    
    // Always log to error_log
    error_log($formatted);
    
    // Also log to a custom file if available
    $log_file = __DIR__ . '/retrieve.log';
    file_put_contents($log_file, $formatted . "\n", FILE_APPEND);
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

    // pick parameters we always want
    $need = [
        'InternetGatewayDevice.DeviceInfo.UpTime',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
        'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
        'InternetGatewayDevice.DeviceInfo.HardwareVersion'
    ];
    tr069_debug_log("Requesting parameters: " . implode(', ', $need), "DEBUG");

    // Use XMLGenerator to create the compound response
    $compound = XMLGenerator::generateCompoundInformResponseWithGPV($soapId, $need);
    tr069_debug_log("Generated compound response, length: " . strlen($compound), "DEBUG");
    tr069_debug_log("TR‑069 sending compound response (first 200): " . substr($compound, 0, 200), "DEBUG");

    header('Content-Type: text/xml'); 
    echo $compound; 
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
    
    // Extract and log parameters for debugging
    preg_match_all('/<ParameterValueStruct>\s*<Name>(.*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>\s*<\/ParameterValueStruct>/s', $raw, $params, PREG_SET_ORDER);
    
    if (!empty($params)) {
        tr069_debug_log("Found " . count($params) . " parameters in response", "INFO");
        foreach ($params as $param) {
            $name = trim($param[1]);
            $value = trim($param[2]);
            tr069_debug_log("Parameter: $name = $value", "DEBUG");
        }
    } else {
        tr069_debug_log("No parameters found in GetParameterValuesResponse", "WARNING");
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
tr069_debug_log("Received unhandled SOAP message type, first 100 chars: " . substr($raw, 0, 100), "INFO");

// Extract SOAP ID if possible
preg_match('~<cwmp:ID [^>]*>(.*?)</cwmp:ID>~', $raw, $m);
$soapId = $m[1] ?? uniqid();

// For empty POST requests, try to detect device based on HTTP headers
if (empty($raw) || $rawLength < 10) {
    tr069_debug_log("Empty or near-empty POST received, content length: $rawLength", "WARNING");
    
    // Log all headers for debugging
    $headers = getallheaders();
    foreach ($headers as $name => $value) {
        tr069_debug_log("Header: $name = $value", "DEBUG");
    }
    
    // Check if there's a device serial in the User-Agent
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    tr069_debug_log("User-Agent: $userAgent", "DEBUG");
    
    // Return a GetParameterValues request for common parameters
    $need = [
        'InternetGatewayDevice.DeviceInfo.UpTime',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
        'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
        'InternetGatewayDevice.DeviceInfo.HardwareVersion'
    ];
    
    $getParamRequest = XMLGenerator::generateFullGetParameterValuesRequestXML(uniqid(), $need);
    tr069_debug_log("Sending GetParameterValues request for empty POST", "INFO");
    header('Content-Type: text/xml'); 
    echo $getParamRequest; 
    exit;
}

header('Content-Type: text/xml'); 
echo XMLGenerator::generateEmptyResponse($soapId); 
exit;
