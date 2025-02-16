
<?php
require_once __DIR__ . '/backend/tr069/server.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/tr069_error.log');

// Set unlimited execution time for long-running sessions
set_time_limit(0);

// Disable SSL verification requirement
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    // Allow self-signed certificates and disable verification
    stream_context_set_default([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);
}

// Initialize and run the TR-069 server
try {
    $server = new TR069Server();
    $server->handleRequest();
} catch (Exception $e) {
    error_log("Fatal TR-069 Server Error: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo "Internal Server Error";
}
