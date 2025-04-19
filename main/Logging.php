<?php

function initializeLogFiles() {
    if (!file_exists($GLOBALS['device_log'])) {
        touch($GLOBALS['device_log']);
        chmod($GLOBALS['device_log'], 0666);
    }

    if (!file_exists($GLOBALS['retrieve_log'])) {
        touch($GLOBALS['retrieve_log']);
        chmod($GLOBALS['retrieve_log'], 0666);
    }
}

function tr069_log($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[TR-069][$level][{$GLOBALS['session_id']}] $message";
    
    error_log($logMessage, 0);
    
    if (isset($GLOBALS['device_log']) && is_writable($GLOBALS['device_log'])) {
        file_put_contents($GLOBALS['device_log'], "[$timestamp] $logMessage\n", FILE_APPEND);
    }
    
    if (isset($GLOBALS['retrieve_log']) && is_writable($GLOBALS['retrieve_log'])) {
        file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] $logMessage\n", FILE_APPEND);
    }
}

?>