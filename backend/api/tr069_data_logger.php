
<?php
// Simple logging utility for TR-069 data
function logTR069Data($message, $severity = 'INFO') {
    $logFile = __DIR__ . '/../../tr069_data.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$severity}] {$message}\n";
    
    // Always log to the file
    file_put_contents($logFile, $logEntry, FILE_APPEND);
    
    // If it's an error or warning, also output to PHP error log
    if ($severity === 'ERROR' || $severity === 'WARNING') {
        error_log($message);
    }
}

// Function to dump router data to log
function dumpRouterData($data, $label = 'Router Data') {
    $dataStr = is_array($data) || is_object($data) ? print_r($data, true) : $data;
    logTR069Data("{$label}: {$dataStr}");
}

// Function to log database operations
function logDatabaseOperation($operation, $details, $success = true) {
    $status = $success ? "SUCCESS" : "FAILED";
    logTR069Data("{$operation} {$status}: {$details}", $success ? 'INFO' : 'ERROR');
}
