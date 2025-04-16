
<?php
// Enable error reporting with maximum verbosity
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/tr069_error.log');

// Function to log with timestamp and priority level
function logWithTimestamp($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logFile = __DIR__ . '/wifi_discovery.log';
    file_put_contents($logFile, "[$timestamp] [$level] $message\n", FILE_APPEND);
    
    // Only log errors to error_log
    if ($level === 'ERROR') {
        error_log("[$timestamp] $message");
    }
}

// Function to log important WiFi data
function logWifiCredential($paramName, $paramValue) {
    $logFile = __DIR__ . '/wifi_discovery.log';
    $timestamp = date('Y-m-d H:i:s');
    
    // Log in a formatted way that's easy to spot
    file_put_contents($logFile, "[$timestamp] ********************************\n", FILE_APPEND);
    file_put_contents($logFile, "[$timestamp] [WIFI CREDENTIAL FOUND]\n", FILE_APPEND);
    file_put_contents($logFile, "[$timestamp] $paramName = $paramValue\n", FILE_APPEND);
    file_put_contents($logFile, "[$timestamp] ********************************\n", FILE_APPEND);
    
    // Save to the dedicated router_ssids.txt file
    file_put_contents(__DIR__ . '/router_ssids.txt', "$paramName = $paramValue\n", FILE_APPEND);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    
    // Start with a clean router_ssids.txt file for new connections
    if (stripos($raw_post, '<cwmp:Inform>') !== false) {
        // Only delete the file if it's a new session
        if (file_exists(__DIR__ . '/router_ssids.txt')) {
            unlink(__DIR__ . '/router_ssids.txt');
            file_put_contents(__DIR__ . '/router_ssids.txt', "# TR-069 WiFi Parameters Discovered " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
        }
    }
    
    if (!empty($raw_post)) {
        // Check if this is an Inform message
        if (stripos($raw_post, '<cwmp:Inform>') !== false) {
            logWithTimestamp("Processing: Inform", "INFO");
            
            // Extract the SOAP ID
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            
            // Log device model if possible
            preg_match('/<ProductClass>(.*?)<\/ProductClass>.*?<SerialNumber>(.*?)<\/SerialNumber>/s', $raw_post, $deviceMatches);
            if (isset($deviceMatches[1]) && isset($deviceMatches[2])) {
                $model = $deviceMatches[1];
                $serial = $deviceMatches[2];
                logWithTimestamp("Device info - Model: $model, Serial: $serial", "INFO");
            }
            
            // If this is an Inform request, respond with InformResponse
            require_once __DIR__ . '/backend/tr069/responses/InformResponseGenerator.php';
            $responseGenerator = new InformResponseGenerator();
            $response = $responseGenerator->createResponse($soapId);
            
            header('Content-Type: text/xml');
            echo $response;
            logWithTimestamp("=== REQUEST COMPLETED ===", "INFO");
            exit;
        }
        
        // Check if this is a GetParameterNamesResponse
        if (stripos($raw_post, 'GetParameterNamesResponse') !== false) {
            logWithTimestamp("RECEIVED GetParameterNamesResponse - WILL NOW REQUEST CREDENTIALS", "INFO");
            
            // Log only beginning/end of large XML to avoid cluttering logs
            logWithTimestamp("=== WIFI RELATED XML START ===", "DEBUG");
            logWithTimestamp("[XML content omitted for brevity]", "DEBUG");
            logWithTimestamp("=== WIFI RELATED XML END ===", "DEBUG");
            
            // Extract the SOAP ID from the request
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            logWithTimestamp("Found SOAP ID: $soapId", "DEBUG");
            
            // Look for WiFi related parameters
            $containsWifiParams = preg_match('/InternetGatewayDevice\.LANDevice\.1\.WLANConfiguration/', $raw_post);
            
            if ($containsWifiParams) {
                logWithTimestamp("WiFi parameters found, directly requesting credentials", "INFO");
                
                // Now respond with a direct request for credentials
                require_once __DIR__ . '/backend/tr069/responses/InformResponseGenerator.php';
                $responseGenerator = new InformResponseGenerator();
                
                // Define what parameters we want (focused on SSIDs and passwords)
                $params = [
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_WPAKey',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID', 
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.KeyPassphrase',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.X_HW_WPAKey',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.KeyPassphrase'
                ];
                
                logWithTimestamp("Requesting parameters: " . implode(', ', $params), "INFO");
                
                // Generate a request for all parameters in one request
                $xml = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:GetParameterValues>
      <ParameterNames SOAP-ENC:arrayType="xsd:string[' . count($params) . ']">';
                
                foreach ($params as $param) {
                    $xml .= '<string>' . $param . '</string>';
                }
                
                $xml .= '</ParameterNames>
    </cwmp:GetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
                
                logWithTimestamp("GetParameterValues request sent for " . count($params) . " parameters", "INFO");
                logWithTimestamp("Requesting WiFi parameters: " . implode(', ', $params), "INFO");
                
                header('Content-Type: text/xml');
                echo $xml;
                logWithTimestamp("=== REQUEST COMPLETED ===", "INFO");
                exit;
            }
        }
        
        // Check if this is a GetParameterValuesResponse (contains the SSIDs)
        if (stripos($raw_post, 'GetParameterValuesResponse') !== false) {
            logWithTimestamp("=== SSID/PASSWORD RESPONSE RECEIVED ===", "INFO");
            
            // Extract SSID and password information using regex
            preg_match_all('/<Name>(.*?(SSID|KeyPassphrase|WPAKey).*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/si', $raw_post, $matches, PREG_SET_ORDER);
            
            $foundCredentials = false;
            
            if (!empty($matches)) {
                foreach ($matches as $match) {
                    $paramName = $match[1];
                    $paramValue = $match[3];
                    
                    // Skip empty values
                    if (empty($paramValue) || $paramValue === '(null)') {
                        continue;
                    }
                    
                    $foundCredentials = true;
                    
                    // Log the found credential with emphasis
                    logWifiCredential($paramName, $paramValue);
                }
            }
            
            if (!$foundCredentials) {
                logWithTimestamp("No SSID or password values found in the response", "WARNING");
                
                // Try to find any parameter that was returned
                preg_match_all('/<Name>(.*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/si', $raw_post, $allParams, PREG_SET_ORDER);
                
                if (!empty($allParams)) {
                    logWithTimestamp("Found " . count($allParams) . " other parameters in response", "INFO");
                    
                    // Log a few samples to help with debugging
                    $sampleCount = min(3, count($allParams));
                    for ($i = 0; $i < $sampleCount; $i++) {
                        $paramName = $allParams[$i][1];
                        $paramValue = $allParams[$i][2];
                        logWithTimestamp("Sample parameter: $paramName = $paramValue", "DEBUG");
                    }
                }
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
            
            logWithTimestamp("=== SSID DISCOVERY COMPLETED ===", "INFO");
            exit;
        }
        
        // Check for fault messages
        if (stripos($raw_post, '<SOAP-ENV:Fault>') !== false || stripos($raw_post, '<cwmp:Fault>') !== false) {
            preg_match('/<FaultCode>(.*?)<\/FaultCode>.*?<FaultString>(.*?)<\/FaultString>/s', $raw_post, $faultMatches);
            
            if (!empty($faultMatches)) {
                $faultCode = $faultMatches[1];
                $faultString = $faultMatches[2];
                logWithTimestamp("FAULT CODE DETECTED: $faultCode - $faultString", "ERROR");
            } else {
                logWithTimestamp("FAULT DETECTED but couldn't parse details", "ERROR");
            }
            
            // Extract SOAP ID
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            logWithTimestamp("Found SOAP ID: $soapId", "DEBUG");
            
            logWithTimestamp("=== REQUEST COMPLETED ===", "INFO");
            exit;
        }
    } else {
        logWithTimestamp("EMPTY POST RECEIVED - This should trigger SSID discovery", "INFO");
        
        // If we receive an empty POST, this is an opportunity to request parameters
        require_once __DIR__ . '/backend/tr069/responses/InformResponseGenerator.php';
        $responseGenerator = new InformResponseGenerator();
        
        // Generate a session ID
        $sessionId = md5(uniqid(rand(), true));
        logWithTimestamp("Starting SSID discovery with session ID: " . $sessionId, "INFO");
        
        // First try a direct SSID request
        $response = $responseGenerator->createSSIDDiscoveryRequest($sessionId);
        
        logWithTimestamp("Sending SSID discovery request", "INFO");
        header('Content-Type: text/xml');
        echo $response;
        logWithTimestamp("=== REQUEST COMPLETED ===", "INFO");
        exit;
    }
}

// If we haven't sent a parameter request yet, use backend TR-069 server
try {
    require_once __DIR__ . '/backend/config/database.php';
    require_once __DIR__ . '/backend/tr069/server.php';
    $server = new TR069Server();
    
    // Pass the Huawei detection flag to the server
    $server->setHuaweiDetection($isHuawei);
    
    // Handle the request
    $server->handleRequest();
} catch (Exception $e) {
    logWithTimestamp("ERROR: " . $e->getMessage(), "ERROR");
    header('HTTP/1.1 500 Internal Server Error');
    echo "Internal Server Error";
}

logWithTimestamp("=== REQUEST COMPLETED ===", "INFO");

