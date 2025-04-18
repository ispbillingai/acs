
<?php
// This script can be called via cron job or after TR-069 session to update device info

require_once __DIR__ . '/../tr069/utils/DeviceInfoUpdater.php';

// Simple logger for this script
class SimpleLogger {
    public function logToFile($message) {
        error_log($message);
    }
}

// Create updater and process log file
$logger = new SimpleLogger();
$updater = new DeviceInfoUpdater($logger);
$result = $updater->updateFromLogFile();

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success' => $result,
    'timestamp' => date('Y-m-d H:i:s')
]);
