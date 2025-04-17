
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/tr069_data_logger.php';

// Start logging this request
logTR069Data("===== Starting device_configure request =====");
logTR069Data("Request method: " . $_SERVER['REQUEST_METHOD']);

// Log all request parameters
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logTR069Data("POST data: " . print_r($_POST, true));
    if (!empty($_FILES)) {
        logTR069Data("Files included in request: " . print_r(array_keys($_FILES), true));
    }
} else {
    logTR069Data("GET params: " . print_r($_GET, true));
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        logTR069Data("Database connection failed", "ERROR");
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        exit;
    }
    
    logTR069Data("Database connection established");
    
    // Check action parameter
    $action = $_POST['action'] ?? $_GET['action'] ?? null;
    
    if (!$action) {
        logTR069Data("No action specified", "ERROR");
        http_response_code(400);
        echo json_encode(['error' => 'Action not specified']);
        exit;
    }
    
    // Get device ID
    $deviceId = $_POST['device_id'] ?? $_GET['device_id'] ?? null;
    
    // Handle different actions
    switch ($action) {
        case 'check_connection':
            logTR069Data("Performing connection check for device ID: {$deviceId}");
            
            if (!$deviceId) {
                logTR069Data("No device ID provided for connection check", "ERROR");
                http_response_code(400);
                echo json_encode(['error' => 'Device ID is required']);
                exit;
            }
            
            // Get current device information
            $stmt = $db->prepare("SELECT * FROM devices WHERE id = :id");
            $stmt->execute([':id' => $deviceId]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$device) {
                logTR069Data("Device not found: {$deviceId}", "ERROR");
                http_response_code(404);
                echo json_encode(['error' => 'Device not found']);
                exit;
            }
            
            logTR069Data("Found device: " . print_r($device, true));
            
            // Check last contact time
            $lastContact = $device['last_contact'] ?? null;
            $currentStatus = $device['status'] ?? 'unknown';
            
            // Calculate if the device should be considered online
            $deviceShouldBeOnline = false;
            $routerResponse = false;
            $databaseUpdated = false;
            
            if ($lastContact) {
                $lastContactTime = new DateTime($lastContact);
                $currentTime = new DateTime();
                $difference = $currentTime->diff($lastContactTime);
                
                // Calculate total minutes difference
                $minutesDiff = $difference->days * 24 * 60 + $difference->h * 60 + $difference->i;
                
                logTR069Data("Last contact was {$minutesDiff} minutes ago");
                
                // Device is online if last contact was within the last 5 minutes
                $deviceShouldBeOnline = $minutesDiff <= 5;
            }
            
            // Check if status needs to be updated
            if (($deviceShouldBeOnline && $currentStatus !== 'online') || 
                (!$deviceShouldBeOnline && $currentStatus !== 'offline')) {
                
                $newStatus = $deviceShouldBeOnline ? 'online' : 'offline';
                logTR069Data("Updating device status from {$currentStatus} to {$newStatus}");
                
                // Update status in database
                $updateStmt = $db->prepare("UPDATE devices SET status = :status, updated_at = NOW() WHERE id = :id");
                $updateResult = $updateStmt->execute([
                    ':status' => $newStatus,
                    ':id' => $deviceId
                ]);
                
                if ($updateResult) {
                    logDatabaseOperation("UPDATE DEVICE STATUS", "Device ID: {$deviceId}, New status: {$newStatus}", true);
                    $databaseUpdated = true;
                } else {
                    logDatabaseOperation("UPDATE DEVICE STATUS", "Device ID: {$deviceId}, New status: {$newStatus}", false);
                }
            } else {
                logTR069Data("No status update needed, current status: {$currentStatus}");
            }
            
            // Try to contact the router directly
            if (isset($device['ip_address']) && !empty($device['ip_address'])) {
                $ipAddress = $device['ip_address'];
                logTR069Data("Attempting to contact router at IP: {$ipAddress}");
                
                // Try to ping or connect to the device
                $pingSuccessful = false;
                
                // On Linux/Unix systems
                if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
                    exec("ping -c 1 -W 1 {$ipAddress}", $pingOutput, $pingResult);
                    $pingSuccessful = $pingResult === 0;
                } else {
                    // On Windows
                    exec("ping -n 1 -w 1000 {$ipAddress}", $pingOutput, $pingResult);
                    $pingSuccessful = $pingResult === 0;
                }
                
                if ($pingSuccessful) {
                    logTR069Data("Ping to {$ipAddress} successful");
                    $routerResponse = true;
                    
                    // If the ping was successful, update the last_contact and status
                    $updateStmt = $db->prepare("UPDATE devices SET last_contact = NOW(), status = 'online', updated_at = NOW() WHERE id = :id");
                    $updateResult = $updateStmt->execute([':id' => $deviceId]);
                    
                    if ($updateResult) {
                        logDatabaseOperation("UPDATE DEVICE AFTER PING", "Device ID: {$deviceId}, updated last_contact and status to online", true);
                        $databaseUpdated = true;
                    } else {
                        logDatabaseOperation("UPDATE DEVICE AFTER PING", "Device ID: {$deviceId}", false);
                    }
                    
                    // Re-fetch the device to get updated info
                    $stmt = $db->prepare("SELECT * FROM devices WHERE id = :id");
                    $stmt->execute([':id' => $deviceId]);
                    $device = $stmt->fetch(PDO::FETCH_ASSOC);
                    $lastContact = $device['last_contact'] ?? null;
                } else {
                    logTR069Data("Ping to {$ipAddress} failed", "WARNING");
                }
            }
            
            // Return the results
            $response = [
                'success' => true,
                'connection_status' => [
                    'success' => $deviceShouldBeOnline || $routerResponse,
                    'last_contact' => $lastContact,
                    'current_status' => $currentStatus,
                    'router_response' => $routerResponse,
                    'database_updated' => $databaseUpdated,
                    'minutes_since_contact' => isset($minutesDiff) ? $minutesDiff : null
                ]
            ];
            
            logTR069Data("Connection check response: " . print_r($response, true));
            echo json_encode($response);
            break;
            
        // Add other actions as needed
        
        default:
            logTR069Data("Unknown action: {$action}", "WARNING");
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
            break;
    }
    
} catch (Exception $e) {
    logTR069Data("Error: " . $e->getMessage(), "ERROR");
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

logTR069Data("===== Completed device_configure request =====");
