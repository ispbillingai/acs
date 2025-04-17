
<?php
// Test script to verify logging functionality

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting logging tests...<br>";

// Test direct file writing
$logFile = __DIR__ . '/../../retrieve.log';
echo "Testing write to: $logFile<br>";

// Check if directory exists
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    echo "Creating directory: $logDir<br>";
    $result = mkdir($logDir, 0777, true);
    if (!$result) {
        echo "ERROR: Failed to create directory!<br>";
    } else {
        echo "Directory created successfully.<br>";
    }
} else {
    echo "Directory already exists: $logDir<br>";
}

// Test file creation
if (!file_exists($logFile)) {
    echo "Creating log file: $logFile<br>";
    $result = touch($logFile);
    if (!$result) {
        echo "ERROR: Failed to create log file!<br>";
    } else {
        echo "Log file created successfully.<br>";
        chmod($logFile, 0666);
        echo "Set permissions to 0666<br>";
    }
} else {
    echo "Log file already exists: $logFile<br>";
    
    // Check if writable
    if (is_writable($logFile)) {
        echo "Log file is writable.<br>";
    } else {
        echo "WARNING: Log file is not writable!<br>";
        echo "Current permissions: " . substr(sprintf('%o', fileperms($logFile)), -4) . "<br>";
        echo "Attempting to set permissions to 0666...<br>";
        chmod($logFile, 0666);
        echo "New permissions: " . substr(sprintf('%o', fileperms($logFile)), -4) . "<br>";
    }
}

// Test writing to file
$message = "Test log entry from test_logging.php at " . date('Y-m-d H:i:s');
$result = file_put_contents($logFile, $message . "\n", FILE_APPEND);

if ($result === false) {
    echo "ERROR: Failed to write to log file!<br>";
    echo "Error details: " . error_get_last()['message'] . "<br>";
} else {
    echo "Successfully wrote $result bytes to log file.<br>";
}

// Test error_log
echo "Testing error_log function...<br>";
$success = error_log("TR-069 TEST: This is a test message from test_logging.php");
if ($success) {
    echo "error_log function succeeded.<br>";
} else {
    echo "ERROR: error_log function failed!<br>";
}

// Load and test the XMLGenerator class
echo "<hr>Testing XMLGenerator class...<br>";
require_once __DIR__ . '/core/XMLGenerator.php';

try {
    echo "Calling XMLGenerator::directLogToFile...<br>";
    XMLGenerator::directLogToFile("Test message from test_logging.php");
    echo "XMLGenerator::directLogToFile completed.<br>";
    
    echo "Calling XMLGenerator::writeLog...<br>";
    // Use reflection to access the private method
    $reflectionClass = new ReflectionClass('XMLGenerator');
    $method = $reflectionClass->getMethod('writeLog');
    $method->setAccessible(true);
    $method->invoke(null, "Test message from test_logging.php using reflection");
    echo "XMLGenerator::writeLog completed.<br>";
    
} catch (Exception $e) {
    echo "ERROR: Exception in XMLGenerator tests: " . $e->getMessage() . "<br>";
}

echo "<hr>All tests completed.";
