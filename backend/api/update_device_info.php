
<?php
// This script can be called via cron job or after TR-069 session to update device info

require_once __DIR__ . '/../tr069/utils/DeviceInfoUpdater.php';

// Simple logger for this script
class SimpleLogger {
    private $logFile;
    
    public function __construct() {
        $this->logFile = __DIR__ . '/../../device.log';
    }
    
    public function logToFile($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [API] $message\n";
        
        // Log to device.log file
        if (file_exists($this->logFile) && is_writable($this->logFile)) {
            file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        }
        
        // Also log to error_log as backup
        error_log("[API] $message");
    }
}

// Create updater and process log file
$logger = new SimpleLogger();
$logger->logToFile("Starting device info update from log file");

$updater = new DeviceInfoUpdater($logger);
$result = $updater->updateFromLogFile();

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success' => $result,
    'timestamp' => date('Y-m-d H:i:s')
]);

$logger->logToFile("Update process completed with result: " . ($result ? "success" : "failure"));
