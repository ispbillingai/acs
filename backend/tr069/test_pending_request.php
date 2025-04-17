
<?php
// Test script to verify pending request functionality

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Testing TR-069 Pending Request System</h1>";

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/core/XMLGenerator.php';
require_once __DIR__ . '/tasks/TaskHandler.php';

// Test parameters
$testSerial = "TESTDEVICE-" . substr(md5(time()), 0, 8);
echo "<p>Using test serial number: $testSerial</p>";

// Initialize XMLGenerator
XMLGenerator::initialize();

// Create a test database connection
$database = new Database();
$db = $database->getConnection();

// Initialize task handler
$taskHandler = new TaskHandler();

echo "<h2>Step 1: Creating a device task</h2>";

try {
    // First make sure the device exists
    $stmt = $db->prepare("
        INSERT INTO devices 
            (serial_number, manufacturer, model_name, status, created_at, updated_at) 
        VALUES 
            (:serial, 'Test Manufacturer', 'Test Model', 'online', NOW(), NOW())
        ON DUPLICATE KEY UPDATE 
            updated_at = NOW()
    ");
    $stmt->execute([':serial' => $testSerial]);
    
    // Get the device ID
    $deviceStmt = $db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
    $deviceStmt->execute([':serial' => $testSerial]);
    $deviceId = $deviceStmt->fetchColumn();
    
    echo "<p>Device created/found with ID: $deviceId</p>";
    
    // Create a test task
    $taskData = json_encode([
        'ssid' => 'TestSSID-' . substr(md5(rand()), 0, 8),
        'password' => 'TestPassword' . rand(1000, 9999)
    ]);
    
    $taskStmt = $db->prepare("
        INSERT INTO device_tasks 
            (device_id, task_type, task_data, status, created_at, updated_at) 
        VALUES 
            (:device_id, 'wifi', :task_data, 'pending', NOW(), NOW())
    ");
    $taskStmt->execute([
        ':device_id' => $deviceId,
        ':task_data' => $taskData
    ]);
    
    $taskId = $db->lastInsertId();
    echo "<p>Created task with ID: $taskId</p>";
    echo "<pre>Task data: " . print_r(json_decode($taskData, true), true) . "</pre>";
    
} catch (PDOException $e) {
    echo "<p style='color:red'>Database error: " . $e->getMessage() . "</p>";
}

echo "<h2>Step 2: Retrieving and processing pending tasks</h2>";

try {
    // Get pending tasks for this device
    $tasks = $taskHandler->getPendingTasks($testSerial);
    echo "<p>Found " . count($tasks) . " pending tasks</p>";
    
    if (!empty($tasks)) {
        echo "<pre>Task details: " . print_r($tasks[0], true) . "</pre>";
        
        // Check if the task was stored as a pending request
        $hasPending = XMLGenerator::hasPendingRequest($testSerial);
        echo "<p>XMLGenerator has pending request: " . ($hasPending ? "YES" : "NO") . "</p>";
        
        if ($hasPending) {
            // Simulate retrieving the request
            $pendingRequest = XMLGenerator::retrievePendingRequest($testSerial);
            echo "<p>Retrieved pending request:</p>";
            echo "<pre>" . print_r($pendingRequest, true) . "</pre>";
            
            // Generate the XML for this request
            if ($pendingRequest) {
                $xml = XMLGenerator::generatePendingSetParameterRequestXML(
                    "test-" . rand(1000, 9999),
                    $pendingRequest['params']
                );
                
                echo "<p>Generated XML (" . strlen($xml) . " bytes):</p>";
                echo "<textarea style='width:100%;height:300px'>" . htmlspecialchars($xml) . "</textarea>";
            }
            
            // Verify the pending request was removed
            $stillHasPending = XMLGenerator::hasPendingRequest($testSerial);
            echo "<p>Still has pending request after retrieval: " . ($stillHasPending ? "YES (ERROR)" : "NO (CORRECT)") . "</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

echo "<p><a href='test_logging.php'>Go to Logging Test</a></p>";
