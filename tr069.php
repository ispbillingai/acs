
<?php
require_once __DIR__ . '/backend/tr069/server.php';

// Enable error reporting with maximum verbosity
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/tr069_error.log');

// Test write permissions by attempting to write to log file
$logFile = __DIR__ . '/tr069_error.log';
file_put_contents($logFile, "Log file initialized: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

// Function to log with timestamp
function logWithTimestamp($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message");
    file_put_contents(__DIR__ . '/tr069_error.log', "[$timestamp] $message\n", FILE_APPEND);
}

// Set unlimited execution time for long-running sessions
set_time_limit(0);

// Basic connection information
logWithTimestamp("=== NEW REQUEST STARTED ===");
logWithTimestamp("Script Path: " . __FILE__);
logWithTimestamp("Document Root: " . $_SERVER['DOCUMENT_ROOT']);
logWithTimestamp("Client IP: " . $_SERVER['REMOTE_ADDR']);
logWithTimestamp("Request Method: " . $_SERVER['REQUEST_METHOD']);
logWithTimestamp("Request URI: " . $_SERVER['REQUEST_URI']);
logWithTimestamp("Query String: " . (isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : 'none'));

// HTTPS and SSL Information
logWithTimestamp("HTTPS Status: " . (isset($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] : 'off'));
logWithTimestamp("Server Protocol: " . $_SERVER['SERVER_PROTOCOL']);
logWithTimestamp("SSL Version: " . (isset($_SERVER['SSL_PROTOCOL']) ? $_SERVER['SSL_PROTOCOL'] : 'unknown'));

// Server Information
logWithTimestamp("Server Software: " . $_SERVER['SERVER_SOFTWARE']);
logWithTimestamp("Server Name: " . $_SERVER['SERVER_NAME']);
logWithTimestamp("Server Port: " . $_SERVER['SERVER_PORT']);

// Request Headers
logWithTimestamp("=== REQUEST HEADERS ===");
foreach (getallheaders() as $name => $value) {
    logWithTimestamp("Header [$name]: $value");
}

// Authentication Information
logWithTimestamp("=== AUTHENTICATION INFO ===");
logWithTimestamp("Auth Type: " . (isset($_SERVER['AUTH_TYPE']) ? $_SERVER['AUTH_TYPE'] : 'none'));
logWithTimestamp("PHP Auth User: " . (isset($_SERVER['PHP_AUTH_USER']) ? 'present' : 'not present'));
logWithTimestamp("PHP Auth PW: " . (isset($_SERVER['PHP_AUTH_PW']) ? 'present' : 'not present'));

// POST Data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logWithTimestamp("=== POST DATA ===");
    $raw_post = file_get_contents('php://input');
    logWithTimestamp("Raw POST data length: " . strlen($raw_post));
    logWithTimestamp("Raw POST data: " . $raw_post);
    
    if (!empty($_POST)) {
        foreach ($_POST as $key => $value) {
            logWithTimestamp("POST[$key]: " . print_r($value, true));
        }
    }
}

// SSL Certificate Information
logWithTimestamp("=== SSL CERTIFICATE INFO ===");
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    $ssl_info = openssl_get_cert_locations();
    logWithTimestamp("SSL Certificate Locations: " . print_r($ssl_info, true));
    
    // Get current SSL context
    $context = stream_context_get_default();
    logWithTimestamp("Current SSL Context: " . print_r($context, true));
    
    // Attempt to disable SSL verification
    $ssl_context = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];
    
    try {
        stream_context_set_default($ssl_context);
        logWithTimestamp("SSL Context modified successfully");
        logWithTimestamp("New SSL Context: " . print_r(stream_context_get_default(), true));
    } catch (Exception $e) {
        logWithTimestamp("Error setting SSL context: " . $e->getMessage());
        logWithTimestamp("SSL Error Stack: " . print_r(error_get_last(), true));
    }
}

// Initialize and run the TR-069 server
try {
    logWithTimestamp("=== INITIALIZING TR-069 SERVER ===");
    $server = new TR069Server();
    logWithTimestamp("TR-069 Server initialized successfully");
    
    logWithTimestamp("=== HANDLING TR-069 REQUEST ===");
    $server->handleRequest();
    logWithTimestamp("TR-069 request handled successfully");
} catch (Exception $e) {
    logWithTimestamp("=== FATAL ERROR ===");
    logWithTimestamp("Error Message: " . $e->getMessage());
    logWithTimestamp("Error Code: " . $e->getCode());
    logWithTimestamp("Error File: " . $e->getFile());
    logWithTimestamp("Error Line: " . $e->getLine());
    logWithTimestamp("Error Trace: " . $e->getTraceAsString());
    logWithTimestamp("Last PHP Error: " . print_r(error_get_last(), true));
    
    header('HTTP/1.1 500 Internal Server Error');
    echo "Internal Server Error - Check Logs";
}

logWithTimestamp("=== REQUEST COMPLETED ===");
