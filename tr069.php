
<?php
// Enable error reporting with maximum verbosity
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/tr069_error.log');

// Function to log with timestamp
function logWithTimestamp($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(__DIR__ . '/wifi_discovery.log', "[$timestamp] $message\n", FILE_APPEND);
    error_log("[$timestamp] $message");
}

// Set unlimited execution time for long-running sessions
set_time_limit(0);

// Basic connection information
logWithTimestamp("=== NEW TR-069 REQUEST ===");
logWithTimestamp("Client IP: " . $_SERVER['REMOTE_ADDR']);
if (isset($_SERVER['HTTP_USER_AGENT'])) {
    logWithTimestamp("Device User-Agent: " . $_SERVER['HTTP_USER_AGENT']);
}

// Enhanced Huawei device detection based on User-Agent
$isHuawei = false;
if (isset($_SERVER['HTTP_USER_AGENT'])) {
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    if (stripos($userAgent, 'huawei') !== false || 
        stripos($userAgent, 'hw_') !== false ||
        stripos($userAgent, 'hg8') !== false) {
        $isHuawei = true;
        logWithTimestamp("DETECTED HUAWEI DEVICE: " . $userAgent);
    }
}

// Additional check in raw POST data for Huawei identifiers
if (!$isHuawei && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    if (!empty($raw_post)) {
        if (stripos($raw_post, 'huawei') !== false || 
            stripos($raw_post, 'hg8') !== false) {
            $isHuawei = true;
            logWithTimestamp("DETECTED HUAWEI DEVICE FROM XML CONTENT");
        }
    }
}

// Flag to track if we've already sent the parameter request
$parameterRequestSent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    if (!empty($raw_post)) {
        // Check if this is an Inform message
        if (stripos($raw_post, '<cwmp:Inform>') !== false) {
            logWithTimestamp("Processing: Inform");
            
            // Extract the SOAP ID
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            
            // If this is an Inform request, respond with InformResponse
            require_once __DIR__ . '/backend/tr069/responses/InformResponseGenerator.php';
            $responseGenerator = new InformResponseGenerator();
            $response = $responseGenerator->createResponse($soapId);
            
            // Log device model if possible
            preg_match('/<ProductClass>(.*?)<\/ProductClass>.*?<SerialNumber>(.*?)<\/SerialNumber>/s', $raw_post, $deviceMatches);
            if (isset($deviceMatches[1]) && isset($deviceMatches[2])) {
                $model = $deviceMatches[1];
                $serial = $deviceMatches[2];
                logWithTimestamp("Device info - Model: $model, Serial: $serial");
            }
            
            logWithTimestamp("Will send SSID discovery on next empty POST");
            header('Content-Type: text/xml');
            echo $response;
            logWithTimestamp("=== REQUEST COMPLETED ===\n");
            exit;
        }
        
        // Check if this is a GetParameterNamesResponse
        if (stripos($raw_post, 'GetParameterNamesResponse') !== false) {
            logWithTimestamp("RECEIVED GetParameterNamesResponse - WILL NOW REQUEST SSID");
            
            // Now respond with a direct SSID request
            require_once __DIR__ . '/backend/tr069/responses/InformResponseGenerator.php';
            $responseGenerator = new InformResponseGenerator();
            
            // Extract the SOAP ID from the request
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            
            // Generate a direct request for SSIDs
            $response = $responseGenerator->createSSIDDiscoveryRequest($soapId);
            
            logWithTimestamp("DIRECTLY REQUESTING SSID");
            header('Content-Type: text/xml');
            echo $response;
            $parameterRequestSent = true;
            logWithTimestamp("=== REQUEST COMPLETED ===\n");
            exit;
        }
        
        // Check if this is a GetParameterValuesResponse (contains the SSIDs)
        if (stripos($raw_post, 'GetParameterValuesResponse') !== false) {
            logWithTimestamp("=== SSID RESPONSE RECEIVED ===");
            
            // Extract SSID information using regex
            preg_match_all('/<Name>(.*?SSID.*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/si', $raw_post, $matches, PREG_SET_ORDER);
            
            if (!empty($matches)) {
                logWithTimestamp("========================================");
                logWithTimestamp("          SSID VALUES FOUND            ");
                logWithTimestamp("========================================");
                
                foreach ($matches as $match) {
                    $paramName = $match[1];
                    $paramValue = $match[2];
                    
                    // Log with emphasis for SSIDs
                    logWithTimestamp("!!! FOUND SSID !!! $paramName = $paramValue");
                    file_put_contents(__DIR__ . '/wifi_discovery.log', date('Y-m-d H:i:s') . " !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!\n", FILE_APPEND);
                    file_put_contents(__DIR__ . '/wifi_discovery.log', date('Y-m-d H:i:s') . " !!!     SSID FOUND     !!!\n", FILE_APPEND);
                    file_put_contents(__DIR__ . '/wifi_discovery.log', date('Y-m-d H:i:s') . " !!! $paramName = $paramValue !!!\n", FILE_APPEND);
                    file_put_contents(__DIR__ . '/wifi_discovery.log', date('Y-m-d H:i:s') . " !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!\n", FILE_APPEND);
                    
                    // Save SSIDs to a dedicated file for easy retrieval
                    file_put_contents(__DIR__ . '/router_ssids.txt', "$paramName = $paramValue\n", FILE_APPEND);
                }
                
                // Send a simple response to complete the transaction
                header('Content-Type: text/xml');
                echo '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">1</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:SetParameterValuesResponse>
      <Status>0</Status>
    </cwmp:SetParameterValuesResponse>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
                
                logWithTimestamp("=== SSID DISCOVERY COMPLETED ===\n");
                exit;
            }
        }
    } else {
        logWithTimestamp("EMPTY POST RECEIVED - This should trigger SSID discovery");
        
        // If we receive an empty POST, this is an opportunity to request parameters
        require_once __DIR__ . '/backend/tr069/responses/InformResponseGenerator.php';
        $responseGenerator = new InformResponseGenerator();
        
        // Generate a session ID
        $sessionId = md5(uniqid(rand(), true));
        logWithTimestamp("Starting SSID discovery");
        logWithTimestamp("New session ID: " . $sessionId);
        
        // First try a direct SSID request
        $response = $responseGenerator->createSSIDDiscoveryRequest($sessionId);
        
        logWithTimestamp("Sending SSID discovery request with session ID: " . $sessionId);
        header('Content-Type: text/xml');
        echo $response;
        logWithTimestamp("=== REQUEST COMPLETED ===\n");
        exit;
    }
}

// If we haven't sent a parameter request yet, use backend TR-069 server
if (!$parameterRequestSent) {
    try {
        require_once __DIR__ . '/backend/config/database.php';
        require_once __DIR__ . '/backend/tr069/server.php';
        $server = new TR069Server();
        
        // Pass the Huawei detection flag to the server
        $server->setHuaweiDetection($isHuawei);
        
        // Handle the request
        $server->handleRequest();
    } catch (Exception $e) {
        logWithTimestamp("ERROR: " . $e->getMessage());
        header('HTTP/1.1 500 Internal Server Error');
        echo "Internal Server Error";
    }
}

logWithTimestamp("=== REQUEST COMPLETED ===\n");
