
<?php
require_once __DIR__ . '/backend/tr069/server.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/tr069_error.log');

// Set unlimited execution time for long-running sessions
set_time_limit(0);

error_log("TR-069 Script Started - " . date('Y-m-d H:i:s'));
error_log("Client IP: " . $_SERVER['REMOTE_ADDR']);
error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
error_log("HTTPS Status: " . (isset($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] : 'off'));
error_log("Server Protocol: " . $_SERVER['SERVER_PROTOCOL']);
error_log("User Agent: " . (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Not provided'));

// Log SSL/TLS information
$ssl_info = openssl_get_cert_locations();
error_log("SSL Certificate Locations: " . print_r($ssl_info, true));

// Disable SSL verification requirement
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    error_log("Attempting to configure SSL context");
    // Allow self-signed certificates and disable verification
    $ssl_context = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];
    
    error_log("SSL Context Configuration: " . print_r($ssl_context, true));
    
    try {
        stream_context_set_default($ssl_context);
        error_log("SSL Context set successfully");
    } catch (Exception $e) {
        error_log("Error setting SSL context: " . $e->getMessage());
    }
}

// Log request headers
$headers = getallheaders();
error_log("Request Headers: " . print_r($headers, true));

// Log POST data if available
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    error_log("Raw POST data length: " . strlen($raw_post));
    if (!empty($raw_post)) {
        error_log("Raw POST data preview (first 500 chars): " . substr($raw_post, 0, 500));
    } else {
        error_log("No POST data received");
    }
}

// Initialize and run the TR-069 server
try {
    error_log("Initializing TR-069 Server");
    $server = new TR069Server();
    error_log("TR-069 Server initialized successfully");
    
    error_log("Handling TR-069 request");
    $server->handleRequest();
    error_log("TR-069 request handled successfully");
} catch (Exception $e) {
    error_log("Fatal TR-069 Server Error: " . $e->getMessage());
    error_log("Error trace: " . $e->getTraceAsString());
    error_log("Error occurred in file: " . $e->getFile() . " on line " . $e->getLine());
    header('HTTP/1.1 500 Internal Server Error');
    echo "Internal Server Error";
}

error_log("TR-069 Script Completed - " . date('Y-m-d H:i:s'));
