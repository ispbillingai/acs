
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

// Disable output buffering for real-time logging
if (ob_get_level()) ob_end_clean();
ob_implicit_flush(true);

// Clear any existing output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Basic connection information
logWithTimestamp("=== NEW TR-069 REQUEST ===");
logWithTimestamp("Client IP: " . $_SERVER['REMOTE_ADDR']);
logWithTimestamp("Device User-Agent: " . $_SERVER['HTTP_USER_AGENT']);

// Request Headers
$headers = getallheaders();
if (isset($headers['Authorization'])) {
    logWithTimestamp("Auth Header Present: Yes");
}

// POST Data for debugging
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    if (!empty($raw_post)) {
        logWithTimestamp("Received TR-069 message, length: " . strlen($raw_post));
    }
}

// Initialize and run the TR-069 server
try {
    require_once __DIR__ . '/backend/tr069/server.php';
    $server = new TR069Server();
    $server->handleRequest();
} catch (Exception $e) {
    logWithTimestamp("ERROR: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo "Internal Server Error";
}

logWithTimestamp("=== REQUEST COMPLETED ===\n");
