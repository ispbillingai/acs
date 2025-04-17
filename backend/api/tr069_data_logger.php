
<?php
// Simple logging utility for TR-069 data that also logs to Apache error log
function logTR069Data($message, $severity = 'INFO') {
    $logFile = __DIR__ . '/../../tr069_data.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$severity}] {$message}\n";
    
    // Always log to the file
    file_put_contents($logFile, $logEntry, FILE_APPEND);
    
    // Always log to Apache error log with severity prefix
    error_log("[TR069][{$severity}] {$message}");
    
    // If it's an error or warning, also output to PHP error log
    if ($severity === 'ERROR' || $severity === 'WARNING') {
        error_log("[TR069][{$severity}] {$message}", 0);
    }
}

// Function to dump router data to log
function dumpRouterData($data, $label = 'Router Data') {
    $dataStr = is_array($data) || is_object($data) ? print_r($data, true) : $data;
    logTR069Data("{$label}: {$dataStr}");
    
    // Also log to Apache error log for critical data
    error_log("[TR069][DATA] {$label}: {$dataStr}");
}

// Function to log database operations
function logDatabaseOperation($operation, $details, $success = true) {
    $status = $success ? "SUCCESS" : "FAILED";
    $message = "{$operation} {$status}: {$details}";
    logTR069Data($message, $success ? 'INFO' : 'ERROR');
    
    // Always log database operations to Apache error log
    error_log("[TR069][DB] {$message}");
}

// Function to log directly to Apache error log (use for debugging)
function logToApache($message, $severity = 'DEBUG') {
    $formattedMessage = "[TR069][{$severity}] {$message}";
    error_log($formattedMessage);
    
    // Also write to our standard log
    $logFile = __DIR__ . '/../../tr069_data.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$severity}] {$message}\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}
