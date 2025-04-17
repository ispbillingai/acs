
<?php
/* -----------------------------------------------------------------
 *  ultra‑minimal TR‑069 endpoint – single file demo
 * ----------------------------------------------------------------*/

error_reporting(E_ALL);
ini_set('display_errors', 0);  // <-- change to 1 only while debugging
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/apache2/tr069_error.log'); // Ensure Apache can write to this file

// Log function that writes to error_log and optionally to a custom file
function tr069_debug_log($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[$timestamp] [TR-069] [$level] $message";
    
    // Always log to error_log
    error_log($formatted);
    
    // Also log to a custom file if available
    $log_file = __DIR__ . '/tr069_debug.log';
    if (is_writable(dirname($log_file))) {
        file_put_contents($log_file, $formatted . "\n", FILE_APPEND);
    }
}

/* ---------- helper: build empty envelope ----------------------- */
function empty_rpc($id = null)
{
    $id = $id ?: uniqid();
    return '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
   xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
   xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
 <soapenv:Header>
   <cwmp:ID soapenv:mustUnderstand="1">'.$id.'</cwmp:ID>
 </soapenv:Header>
 <soapenv:Body/>
</soapenv:Envelope>';
}

/* ---------- helper: inner GetParameterValues block ------------- */
function gpv_block(array $names, $id = null)
{
    $id = $id ?: uniqid();
    $list = '';
    foreach ($names as $n) {
        $list .= "\n      <string>".htmlspecialchars($n)."</string>";
    }

    return '<cwmp:GetParameterValues>
      <ParameterNames soap-enc:arrayType="xsd:string['.count($names).']" xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">'.
        $list.'
      </ParameterNames>
    </cwmp:GetParameterValues>';
}

/* ---------- helper: InformResponse envelope -------------------- */
function inform_response($soapId)
{
    return '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">'.$soapId.'</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:InformResponse>
      <MaxEnvelopes>1</MaxEnvelopes>
    </cwmp:InformResponse>
  </soapenv:Body>
</soapenv:Envelope>';
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

    // build InformResponse
    $resp = inform_response($soapId);
    tr069_debug_log("Built InformResponse, length: " . strlen($resp), "DEBUG");

    // normalise closing tag (no whitespace)
    $resp = preg_replace('~\s+</([A-Za-z0-9_-]+):Body>~', '</$1:Body>', $resp, 1);

    // inject GPV before </*:Body>
    $gpvXml = gpv_block($need);
    tr069_debug_log("Generated GPV block: " . $gpvXml, "DEBUG");
    
    $compound = preg_replace(
        '~</([A-Za-z0-9_-]+):Body>~',
        $gpvXml . "\n  </$1:Body>",
        $resp,
        1
    );

    if ($compound === $resp) {
        tr069_debug_log("ERROR: Failed to inject GetParameterValues into InformResponse", "ERROR");
    } else {
        tr069_debug_log("Successfully created compound response with GetParameterValues", "INFO");
    }

    tr069_debug_log("TR‑069 sending compound response (first 200): " . substr($compound, 0, 200), "DEBUG");
    header('Content-Type: text/xml'); 
    echo $compound; 
    exit;
}

/* ---- GetParameterValuesResponse --------------------------------*/
if (stripos($raw, '<cwmp:GetParameterValuesResponse') !== false) {
    tr069_debug_log("Received GetParameterValuesResponse", "INFO");
    tr069_debug_log("GPV‑RESPONSE head=" . substr($raw, 0, 200), "DEBUG");
    
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
    
    // TODO: parse and store values here
    header('Content-Type: text/xml'); 
    echo empty_rpc(); 
    exit;
}

/* ---- any other SOAP (SetParameterValuesResponse etc.) ----------*/
tr069_debug_log("Received unhandled SOAP message type, first 100 chars: " . substr($raw, 0, 100), "INFO");
header('Content-Type: text/xml'); 
echo empty_rpc(); 
exit;
