
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
    }
}

// Initialize and run the TR-069 server
try {
    require_once __DIR__ . '/backend/tr069/server.php';
    $server = new TR069Server();
    // Pass the Huawei detection flag to the server
    $server->setHuaweiDetection($isHuawei);
    $server->handleRequest();
} catch (Exception $e) {
    logWithTimestamp("ERROR: " . $e->getMessage());
    logWithTimestamp("Stack trace: " . $e->getTraceAsString());
    header('HTTP/1.1 500 Internal Server Error');
    echo "Internal Server Error";
}

logWithTimestamp("=== REQUEST COMPLETED ===\n");
