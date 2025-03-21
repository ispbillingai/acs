
<?php
// Enable error reporting with maximum verbosity
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/tr069_error.log');

// Function to log with timestamp - only log WiFi-related info
function logWithTimestamp($message) {
    $timestamp = date('Y-m-d H:i:s');
    
    // Only write WiFi-related messages to special log
    if (strpos($message, 'WLAN') !== false || 
        strpos($message, 'WiFi') !== false || 
        strpos($message, 'SSID') !== false || 
        strpos($message, 'WPA') !== false ||
        strpos($message, '9005') !== false) {
        error_log("[$timestamp] $message");
        file_put_contents(__DIR__ . '/wifi_discovery.log', "[$timestamp] $message\n", FILE_APPEND);
    }
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

// POST Data logging - focus only on WiFi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    if (!empty($raw_post)) {
        // Log WiFi-related information only
        if (stripos($raw_post, 'WLAN') !== false || 
            stripos($raw_post, 'WiFi') !== false || 
            stripos($raw_post, 'SSID') !== false || 
            stripos($raw_post, 'X_HW_') !== false ||
            stripos($raw_post, 'DeviceSummary') !== false && 
            stripos($raw_post, 'WiFiLAN') !== false) {
            
            logWithTimestamp("=== WIFI RELATED XML START ===");
            logWithTimestamp($raw_post);
            logWithTimestamp("=== WIFI RELATED XML END ===");
            
            file_put_contents(__DIR__ . '/wifi_discovery.log', date('Y-m-d H:i:s') . " Received WiFi-related XML: " . $raw_post . "\n", FILE_APPEND);
        }
        
        // Log any fault codes
        if (stripos($raw_post, '<FaultCode>') !== false) {
            preg_match('/<FaultCode>(.*?)<\/FaultCode>/', $raw_post, $matches);
            if (isset($matches[1])) {
                logWithTimestamp("FAULT CODE DETECTED: " . $matches[1]);
                file_put_contents(__DIR__ . '/wifi_discovery.log', date('Y-m-d H:i:s') . " FAULT: " . $matches[1] . " in response\n", FILE_APPEND);
            }
        }
    } else {
        logWithTimestamp("EMPTY POST RECEIVED - This should trigger parameter discovery");
    }
}

// Initialize and run the TR-069 server
try {
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
    $server->handleRequest();
} catch (Exception $e) {
    logWithTimestamp("ERROR: " . $e->getMessage());
    logWithTimestamp("Stack trace: " . $e->getTraceAsString());
    file_put_contents(__DIR__ . '/wifi_discovery.log', date('Y-m-d H:i:s') . " ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    header('HTTP/1.1 500 Internal Server Error');
    echo "Internal Server Error";
}

logWithTimestamp("=== REQUEST COMPLETED ===\n");
