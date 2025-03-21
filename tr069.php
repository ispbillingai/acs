
<?php
// Enable error reporting with maximum verbosity
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/tr069_error.log');

// Function to log with timestamp
function logWithTimestamp($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message");
    file_put_contents(__DIR__ . '/tr069_error.log', "[$timestamp] $message\n", FILE_APPEND);
}

// Set unlimited execution time for long-running sessions
set_time_limit(0);

// Basic connection information
logWithTimestamp("=== NEW TR-069 REQUEST ===");
logWithTimestamp("Client IP: " . $_SERVER['REMOTE_ADDR']);
logWithTimestamp("Device User-Agent: " . $_SERVER['HTTP_USER_AGENT']);
logWithTimestamp("Request Method: " . $_SERVER['REQUEST_METHOD']);

// Enhanced Huawei device detection based on User-Agent and XML content
$isHuawei = false;
if (isset($_SERVER['HTTP_USER_AGENT'])) {
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    // Check for any Huawei-specific strings in User-Agent
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
            stripos($raw_post, 'hg8') !== false ||
            stripos($raw_post, '00259e') !== false) {  // Common Huawei OUI
            $isHuawei = true;
            logWithTimestamp("DETECTED HUAWEI DEVICE FROM XML CONTENT");
        }
    }
}

// Request Headers (only important ones)
$headers = getallheaders();
if (isset($headers['Authorization'])) {
    logWithTimestamp("Auth Header Present: Yes");
}

// Check for cookies - important for session tracking
if (isset($_SERVER['HTTP_COOKIE'])) {
    logWithTimestamp("Cookies Present: " . $_SERVER['HTTP_COOKIE']);
}

// Check if we have the ID in the SOAP header for empty POST correlation
$soapID = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty(file_get_contents('php://input'))) {
    // Try to extract the SOAP ID from headers
    if (isset($headers['SOAPACTION']) || isset($headers['SOAPAction'])) {
        $soapAction = isset($headers['SOAPACTION']) ? $headers['SOAPACTION'] : $headers['SOAPAction'];
        logWithTimestamp("SOAP Action header found: " . $soapAction);
    }
    // Extract session from URL if present
    if (isset($_GET['session'])) {
        logWithTimestamp("Session parameter found in URL: " . $_GET['session']);
    }
}

// POST Data for debugging
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    if (!empty($raw_post)) {
        logWithTimestamp("Received TR-069 message, length: " . strlen($raw_post));
        
        // For Huawei devices, log the full XML (but remove sensitive data)
        if ($isHuawei) {
            // Simple sanitization to hide passwords and connection URLs
            $sanitized_xml = preg_replace('/<Value(.*?)>([^<]{8,})<\/Value>/i', '<Value$1>[REDACTED]</Value>', $raw_post);
            $sanitized_xml = preg_replace('/(<Name>.*?Password.*?<\/Name>\s*<Value.*?>).*?(<\/Value>)/is', '$1[REDACTED]$2', $sanitized_xml);
            $sanitized_xml = preg_replace('/(<Name>.*?ConnectionRequestURL.*?<\/Name>\s*<Value.*?>).*?(<\/Value>)/is', '$1[REDACTED]$2', $sanitized_xml);
            
            logWithTimestamp("=== HUAWEI RAW XML START ===");
            logWithTimestamp($sanitized_xml);
            logWithTimestamp("=== HUAWEI RAW XML END ===");
        }
    } else {
        logWithTimestamp("EMPTY POST RECEIVED - This should trigger GetParameterValues if in session");
        
        // Create get.log with timestamp if it doesn't exist
        if (!file_exists(__DIR__ . '/get.log')) {
            file_put_contents(__DIR__ . '/get.log', date('Y-m-d H:i:s') . " GetParameterValues log initialized\n", FILE_APPEND);
        }
        
        // Also log empty POST to get.log
        file_put_contents(__DIR__ . '/get.log', date('Y-m-d H:i:s') . " Empty POST received from " . $_SERVER['REMOTE_ADDR'] . " with UA: " . $_SERVER['HTTP_USER_AGENT'] . "\n", FILE_APPEND);
        
        // Log cookie info again specifically for empty POSTs
        if (isset($_SERVER['HTTP_COOKIE'])) {
            logWithTimestamp("Session Cookie for empty POST: " . $_SERVER['HTTP_COOKIE']);
            file_put_contents(__DIR__ . '/get.log', date('Y-m-d H:i:s') . " Empty POST received with cookie: " . $_SERVER['HTTP_COOKIE'] . "\n", FILE_APPEND);
        } else {
            logWithTimestamp("WARNING: Empty POST with no session cookie");
            file_put_contents(__DIR__ . '/get.log', date('Y-m-d H:i:s') . " Empty POST received with NO cookie\n", FILE_APPEND);
        }
    }
}

// Initialize and run the TR-069 server
try {
    require_once __DIR__ . '/backend/tr069/server.php';
    $server = new TR069Server();
    // Pass the Huawei detection flag to the server
    $server->setHuaweiDetection($isHuawei);
    // Add flag to indicate that we want to use parameter discovery
    $server->setUseParameterDiscovery(true);
    $server->handleRequest();
} catch (Exception $e) {
    logWithTimestamp("ERROR: " . $e->getMessage());
    logWithTimestamp("Stack trace: " . $e->getTraceAsString());
    file_put_contents(__DIR__ . '/get.log', date('Y-m-d H:i:s') . " ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    header('HTTP/1.1 500 Internal Server Error');
    echo "Internal Server Error";
}

logWithTimestamp("=== REQUEST COMPLETED ===\n");
