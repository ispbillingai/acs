
<?php
// Enable error reporting with maximum verbosity
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/tr069_error.log');

// Function to log with timestamp - focus only on WiFi-related info
function logWithTimestamp($message) {
    $timestamp = date('Y-m-d H:i:s');
    
    // Write all messages to the WiFi discovery log
    file_put_contents(__DIR__ . '/wifi_discovery.log', "[$timestamp] $message\n", FILE_APPEND);
    
    // Also log to error_log for server logs
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
$modelHint = '';

if (isset($_SERVER['HTTP_USER_AGENT'])) {
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    // Check for any Huawei-specific strings in User-Agent
    if (stripos($userAgent, 'huawei') !== false || 
        stripos($userAgent, 'hw_') !== false ||
        stripos($userAgent, 'hg8') !== false) {
        $isHuawei = true;
        logWithTimestamp("DETECTED HUAWEI DEVICE: " . $userAgent);
        
        // Try to determine model from user agent
        if (stripos($userAgent, 'hg8145') !== false) {
            $modelHint = 'HG8145V';
            logWithTimestamp("DETECTED SPECIFIC MODEL: " . $modelHint);
        }
    }
}

// Additional check in raw POST data for Huawei identifiers
if (!$isHuawei && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    if (!empty($raw_post)) {
        if (stripos($raw_post, 'huawei') !== false || 
            stripos($raw_post, 'hg8') !== false ||
            stripos($raw_post, 'HG8145V') !== false) {
            $isHuawei = true;
            logWithTimestamp("DETECTED HUAWEI DEVICE FROM XML CONTENT");
        }
    }
}

// Flag to track if we've already sent the parameter request
$parameterRequestSent = false;

// Store the Connection Request URL if found
$connectionRequestURL = '';

// POST Data logging and analysis - focus particularly on WiFi credentials
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    if (!empty($raw_post)) {
        // Check if this is a GetParameterNamesResponse
        if (stripos($raw_post, 'GetParameterNamesResponse') !== false) {
            logWithTimestamp("RECEIVED GetParameterNamesResponse - WILL NOW REQUEST WIFI CREDENTIALS");
            
            // Now we need to respond with a GetParameterValues request for specific WiFi parameters
            require_once __DIR__ . '/backend/tr069/responses/InformResponseGenerator.php';
            $responseGenerator = new InformResponseGenerator();
            
            // Extract the SOAP ID from the request
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            
            // Generate a direct request for WiFi credentials
            $response = $responseGenerator->createCustomGetParameterValuesRequest($soapId, [
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_WPAKey',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.KeyPassphrase',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.X_HW_WPAKey',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.KeyPassphrase'
            ]);
            
            logWithTimestamp("DIRECTLY REQUESTING WIFI CREDENTIALS");
            header('Content-Type: text/xml');
            echo $response;
            $parameterRequestSent = true;
            logWithTimestamp("=== REQUEST COMPLETED ===\n");
            exit;
        }
        
        // Check if this is a GetParameterValuesResponse (contains the WiFi credentials)
        if (stripos($raw_post, 'GetParameterValuesResponse') !== false) {
            logWithTimestamp("=== WIFI CREDENTIALS RESPONSE RECEIVED ===");
            logWithTimestamp($raw_post);
            
            // Try to extract SSID and password information using regex
            preg_match_all('/<Name>(.*?(SSID|KeyPassphrase|WPAKey|PreSharedKey).*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/si', $raw_post, $matches, PREG_SET_ORDER);
            
            if (!empty($matches)) {
                foreach ($matches as $match) {
                    $paramName = $match[1];
                    $paramValue = $match[3];
                    
                    // Log with lots of emphasis for WiFi credentials
                    logWithTimestamp("!!! FOUND WIFI PARAMETER !!! $paramName = $paramValue");
                    file_put_contents(__DIR__ . '/wifi_discovery.log', date('Y-m-d H:i:s') . " !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!\n", FILE_APPEND);
                    file_put_contents(__DIR__ . '/wifi_discovery.log', date('Y-m-d H:i:s') . " !!!     WIFI CREDENTIAL FOUND     !!!\n", FILE_APPEND);
                    file_put_contents(__DIR__ . '/wifi_discovery.log', date('Y-m-d H:i:s') . " !!! $paramName = $paramValue !!!\n", FILE_APPEND);
                    file_put_contents(__DIR__ . '/wifi_discovery.log', date('Y-m-d H:i:s') . " !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!\n", FILE_APPEND);
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
                
                logWithTimestamp("=== WIFI CREDENTIAL TRANSACTION COMPLETED ===\n");
                exit;
            }
        }
        
        // Check for Connection Request URL in the POST data
        if (stripos($raw_post, 'ConnectionRequestURL') !== false) {
            preg_match('/<Name>InternetGatewayDevice\.ManagementServer\.ConnectionRequestURL<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/si', $raw_post, $urlMatches);
            
            if (isset($urlMatches[1])) {
                $connectionRequestURL = $urlMatches[1];
                logWithTimestamp("CONNECTION REQUEST URL FOUND: " . $connectionRequestURL);
                file_put_contents(__DIR__ . '/connection_url.txt', $connectionRequestURL, LOCK_EX);
            }
        }
        
        // Log any WiFi-related information
        if (stripos($raw_post, 'WLAN') !== false || 
            stripos($raw_post, 'WiFi') !== false || 
            stripos($raw_post, 'SSID') !== false || 
            stripos($raw_post, 'WPA') !== false ||
            stripos($raw_post, 'X_HW_') !== false ||
            stripos($raw_post, 'PreSharedKey') !== false ||
            stripos($raw_post, 'KeyPassphrase') !== false ||
            stripos($raw_post, 'DeviceSummary') !== false && 
            stripos($raw_post, 'WiFiLAN') !== false) {
            
            logWithTimestamp("=== WIFI RELATED XML START ===");
            logWithTimestamp($raw_post);
            logWithTimestamp("=== WIFI RELATED XML END ===");
        }
        
        // Look specifically for SSID and password information
        if (stripos($raw_post, 'SSID') !== false || 
            stripos($raw_post, 'KeyPassphrase') !== false ||
            stripos($raw_post, 'WPAKey') !== false ||
            stripos($raw_post, 'PreSharedKey') !== false) {
            
            // Try to extract SSID and password information using regex
            preg_match_all('/<Name>(.*?(SSID|KeyPassphrase|WPAKey|PreSharedKey).*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/si', $raw_post, $matches, PREG_SET_ORDER);
            
            if (!empty($matches)) {
                foreach ($matches as $match) {
                    $paramName = $match[1];
                    $paramValue = $match[3];
                    
                    // Log with lots of emphasis for WiFi credentials
                    logWithTimestamp("!!! FOUND WIFI PARAMETER !!! $paramName = $paramValue");
                    file_put_contents(__DIR__ . '/wifi_discovery.log', date('Y-m-d H:i:s') . " !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!\n", FILE_APPEND);
                    file_put_contents(__DIR__ . '/wifi_discovery.log', date('Y-m-d H:i:s') . " !!!     WIFI CREDENTIAL FOUND     !!!\n", FILE_APPEND);
                    file_put_contents(__DIR__ . '/wifi_discovery.log', date('Y-m-d H:i:s') . " !!! $paramName = $paramValue !!!\n", FILE_APPEND);
                    file_put_contents(__DIR__ . '/wifi_discovery.log', date('Y-m-d H:i:s') . " !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!\n", FILE_APPEND);
                }
            }
        }
        
        // Extract SOAP ID if present
        preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        if (isset($idMatches[1])) {
            $soapId = $idMatches[1];
            logWithTimestamp("Found SOAP ID: " . $soapId);
            
            // Determine if this is an Inform message
            if (stripos($raw_post, '<cwmp:Inform>') !== false) {
                logWithTimestamp("Processing: Inform");
                
                // If this is an Inform request, respond with InformResponse
                require_once __DIR__ . '/backend/tr069/responses/InformResponseGenerator.php';
                $responseGenerator = new InformResponseGenerator();
                $response = $responseGenerator->createResponse($soapId);
                
                // Extract parameters from Inform message
                preg_match_all('/<ParameterValueStruct>.*?<Name>(.*?)<\/Name>.*?<Value.*?>(.*?)<\/Value>.*?<\/ParameterValueStruct>/s', $raw_post, $paramMatches, PREG_SET_ORDER);
                
                if (!empty($paramMatches)) {
                    logWithTimestamp("Found " . count($paramMatches) . " parameters");
                    foreach ($paramMatches as $match) {
                        $name = $match[1];
                        $value = $match[2];
                        logWithTimestamp("Parameter: $name = $value");
                        
                        // Save Connection Request URL if found
                        if ($name === 'InternetGatewayDevice.ManagementServer.ConnectionRequestURL') {
                            $connectionRequestURL = $value;
                            file_put_contents(__DIR__ . '/connection_url.txt', $connectionRequestURL, LOCK_EX);
                        }
                    }
                }
                
                // Extract device info
                preg_match('/<ProductClass>(.*?)<\/ProductClass>.*?<SerialNumber>(.*?)<\/SerialNumber>/s', $raw_post, $deviceMatches);
                if (isset($deviceMatches[1]) && isset($deviceMatches[2])) {
                    $model = $deviceMatches[1];
                    $serial = $deviceMatches[2];
                    logWithTimestamp("Device info - Model: $model, Serial: $serial");
                    
                    if ($model === 'HG8145V') {
                        logWithTimestamp("Confirmed HG8145V model");
                        $modelHint = 'HG8145V';
                    }
                }
                
                logWithTimestamp("Will send WiFi discovery on next empty POST");
                header('Content-Type: text/xml');
                echo $response;
                logWithTimestamp("Created InformResponse for session ID: " . $soapId);
                logWithTimestamp("=== REQUEST COMPLETED ===\n");
                exit;
            } else if (stripos($raw_post, 'GetParameterNamesResponse') !== false) {
                logWithTimestamp("Processing: GetParameterNamesResponse");
                
                // Now we need to respond with a GetParameterValues request for specific WiFi parameters
                require_once __DIR__ . '/backend/tr069/responses/InformResponseGenerator.php';
                $responseGenerator = new InformResponseGenerator();
                
                // Generate a direct request for WiFi credentials
                $response = $responseGenerator->createCustomGetParameterValuesRequest($soapId, [
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_WPAKey',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.KeyPassphrase',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.X_HW_WPAKey',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.KeyPassphrase'
                ]);
                
                logWithTimestamp("DIRECTLY REQUESTING WIFI CREDENTIALS AFTER GetParameterNamesResponse");
                header('Content-Type: text/xml');
                echo $response;
                $parameterRequestSent = true;
                logWithTimestamp("=== REQUEST COMPLETED ===\n");
                exit;
            }
        }
    } else {
        logWithTimestamp("EMPTY POST RECEIVED - This should trigger parameter discovery");
        
        // If we receive an empty POST, this is an opportunity to request parameters
        require_once __DIR__ . '/backend/tr069/responses/InformResponseGenerator.php';
        $responseGenerator = new InformResponseGenerator();
        
        // Generate a session ID
        $sessionId = md5(uniqid(rand(), true));
        logWithTimestamp("Empty POST received");
        logWithTimestamp("Starting WiFi parameter discovery");
        logWithTimestamp("New session ID: " . $sessionId);
        
        if ($modelHint === 'HG8145V') {
            // Direct credential request for HG8145V
            $response = $responseGenerator->createCustomGetParameterValuesRequest($sessionId, [
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_WPAKey',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.KeyPassphrase',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.X_HW_WPAKey',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.KeyPassphrase'
            ]);
            logWithTimestamp("DIRECT REQUEST FOR HG8145V WIFI CREDENTIALS");
        } else {
            // First get all parameter names to see what's available
            $response = $responseGenerator->createWifiDiscoveryRequest($sessionId);
            logWithTimestamp("GetParameterNames request sent for path: InternetGatewayDevice.LANDevice.1.WLANConfiguration.");
        }
        
        logWithTimestamp("Sending discovery request with session ID: " . $sessionId);
        header('Content-Type: text/xml');
        echo $response;
        $parameterRequestSent = true;
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
        
        // If we have a model hint, pass it as well
        if (!empty($modelHint)) {
            $server->setModelHint($modelHint);
        }
        
        // Add flag to indicate that we want to use parameter discovery
        $server->setUseParameterDiscovery(true);
        
        // Handle the request
        $server->handleRequest();
    } catch (Exception $e) {
        logWithTimestamp("ERROR: " . $e->getMessage());
        logWithTimestamp("Stack trace: " . $e->getTraceAsString());
        header('HTTP/1.1 500 Internal Server Error');
        echo "Internal Server Error";
    }
}

logWithTimestamp("=== REQUEST COMPLETED ===\n");
