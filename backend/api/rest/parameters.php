
<?php
// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set headers for REST API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include database connection
require_once __DIR__ . '/../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Log function for debugging
function writeLog($message) {
    $logFile = __DIR__ . '/../../../acs.log';
    $timestamp = date('Y-m-d H:i:s');
    
    // Make sure the log is writable
    if (!file_exists($logFile)) {
        touch($logFile);
        chmod($logFile, 0666);
    }
    
    // Log to file
    file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

writeLog("REST API Request: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);
writeLog("Request parameters: " . print_r($_REQUEST, true));

// Process GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // List all devices if specified
    if (isset($_GET['list_all_devices'])) {
        try {
            $stmt = $db->prepare("
                SELECT 
                    id,
                    serial_number,
                    manufacturer,
                    model_name,
                    mac_address,
                    ip_address,
                    last_contact,
                    status,
                    software_version,
                    hardware_version,
                    ssid,
                    connected_clients,
                    tr069_last_transaction,
                    tr069_last_attempt,
                    ssid_password
                FROM devices
                ORDER BY last_contact DESC
            ");
            $stmt->execute();
            $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'devices' => $devices
            ]);
            exit;
        } catch (PDOException $e) {
            writeLog("Database error listing devices: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Database error', 
                'details' => $e->getMessage()
            ]);
            exit;
        }
    }

    // Check if device ID or serial is provided
    $deviceIdentifier = $_GET['device_id'] ?? ($_GET['serial'] ?? null);
    
    if (empty($deviceIdentifier)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Device identifier (device_id or serial) is required',
            'hint' => 'Use device_id, serial, or list_all_devices=1'
        ]);
        exit;
    }

    // Determine if we're looking up by ID or serial
    $isSerial = isset($_GET['serial']);
    $whereClause = $isSerial ? "serial_number = :identifier" : "id = :identifier";
    
    try {
        // First, get the device details to make sure it exists
        $stmt = $db->prepare("
            SELECT 
                id,
                serial_number,
                manufacturer,
                model_name,
                mac_address,
                ip_address,
                last_contact,
                status,
                software_version,
                hardware_version,
                ssid,
                connected_clients
            FROM devices
            WHERE {$whereClause}
            LIMIT 1
        ");
        $stmt->bindParam(':identifier', $deviceIdentifier);
        $stmt->execute();
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$device) {
            http_response_code(404);
            echo json_encode([
                'error' => 'Device not found',
                'details' => "No device found with " . ($isSerial ? "serial" : "ID") . ": {$deviceIdentifier}"
            ]);
            exit;
        }
        
        // Check if a specific parameter is requested
        if (isset($_GET['param'])) {
            $paramName = $_GET['param'];
            
            // Check if parameter exists in parameters table
            try {
                $paramStmt = $db->prepare("
                    SELECT param_name, param_value, param_type
                    FROM parameters
                    WHERE device_id = :device_id AND param_name = :param_name
                    LIMIT 1
                ");
                $paramStmt->bindParam(':device_id', $device['id']);
                $paramStmt->bindParam(':param_name', $paramName);
                $paramStmt->execute();
                $parameter = $paramStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($parameter) {
                    echo json_encode([
                        'success' => true,
                        'device_id' => $device['id'],
                        'parameter' => $parameter
                    ]);
                } else {
                    // Parameter not found in parameters, check if it's a device property
                    if (array_key_exists($paramName, $device)) {
                        echo json_encode([
                            'success' => true,
                            'device_id' => $device['id'],
                            'parameter' => [
                                'param_name' => $paramName,
                                'param_value' => $device[$paramName],
                                'param_type' => 'string'
                            ]
                        ]);
                    } else {
                        http_response_code(404);
                        echo json_encode([
                            'error' => 'Parameter not found',
                            'details' => "Parameter '{$paramName}' not found for device ID: {$device['id']}"
                        ]);
                    }
                }
                exit;
            } catch (PDOException $e) {
                // If table doesn't exist, return empty parameters
                writeLog("Error fetching parameter: " . $e->getMessage());
                http_response_code(404);
                echo json_encode([
                    'error' => 'Parameter lookup error',
                    'details' => $e->getMessage()
                ]);
                exit;
            }
        }
        
        // Return all parameters for the device
        try {
            $params = [];
            
            // Try to get parameters from parameters table
            try {
                $paramsStmt = $db->prepare("
                    SELECT param_name, param_value, param_type
                    FROM parameters
                    WHERE device_id = :device_id
                ");
                $paramsStmt->bindParam(':device_id', $device['id']);
                $paramsStmt->execute();
                $params = $paramsStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // If table doesn't exist, continue with empty params
                writeLog("Error fetching parameters: " . $e->getMessage());
            }
            
            // Return the device with its parameters
            echo json_encode([
                'success' => true,
                'device' => $device,
                'parameters' => $params
            ]);
            exit;
        } catch (PDOException $e) {
            writeLog("Database error fetching parameters: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Database error',
                'details' => $e->getMessage()
            ]);
            exit;
        }
    } catch (PDOException $e) {
        writeLog("Database error fetching device: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Database error', 
            'details' => $e->getMessage()
        ]);
        exit;
    }
}

// Process POST requests - for setting parameters, running actions, etc.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the raw POST data
    $input = file_get_contents('php://input');
    $postData = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        // If not JSON, try standard POST data
        $postData = $_POST;
    }
    
    writeLog("POST data: " . print_r($postData, true));
    
    // Check for required action
    if (empty($postData['action'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Missing required field',
            'details' => 'The "action" field is required'
        ]);
        exit;
    }
    
    $action = $postData['action'];
    
    switch ($action) {
        case 'set_parameter':
            if (empty($postData['device_id']) || empty($postData['param_name']) || !isset($postData['param_value'])) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Missing required fields',
                    'details' => 'The fields "device_id", "param_name", and "param_value" are required'
                ]);
                exit;
            }
            
            $deviceId = $postData['device_id'];
            $paramName = $postData['param_name'];
            $paramValue = $postData['param_value'];
            $paramType = $postData['param_type'] ?? 'string';
            
            try {
                // First check if device exists
                $deviceStmt = $db->prepare("SELECT id FROM devices WHERE id = :device_id LIMIT 1");
                $deviceStmt->bindParam(':device_id', $deviceId);
                $deviceStmt->execute();
                
                if ($deviceStmt->rowCount() === 0) {
                    http_response_code(404);
                    echo json_encode([
                        'error' => 'Device not found',
                        'details' => "No device found with ID: {$deviceId}"
                    ]);
                    exit;
                }
                
                // Try to insert or update the parameter
                $stmt = $db->prepare("
                    INSERT INTO parameters (device_id, param_name, param_value, param_type)
                    VALUES (:device_id, :param_name, :param_value, :param_type)
                    ON DUPLICATE KEY UPDATE param_value = :param_value, param_type = :param_type
                ");
                
                $stmt->bindParam(':device_id', $deviceId);
                $stmt->bindParam(':param_name', $paramName);
                $stmt->bindParam(':param_value', $paramValue);
                $stmt->bindParam(':param_type', $paramType);
                $stmt->execute();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Parameter set successfully',
                    'parameter' => [
                        'device_id' => $deviceId,
                        'param_name' => $paramName,
                        'param_value' => $paramValue,
                        'param_type' => $paramType
                    ]
                ]);
                exit;
                
            } catch (PDOException $e) {
                writeLog("Database error setting parameter: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'error' => 'Database error',
                    'details' => $e->getMessage()
                ]);
                exit;
            }
            break;
            
        case 'configure_wifi':
            // Handle WiFi configuration
            if (empty($postData['device_id'])) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Missing required field',
                    'details' => 'The "device_id" field is required'
                ]);
                exit;
            }
            
            $deviceId = $postData['device_id'];
            $ssid = $postData['ssid'] ?? null;
            $password = $postData['password'] ?? null;
            
            // Create a task for the device
            try {
                $stmt = $db->prepare("
                    INSERT INTO device_tasks (device_id, task_type, task_data, status)
                    VALUES (:device_id, 'wifi', :task_data, 'pending')
                ");
                
                $taskData = json_encode([
                    'ssid' => $ssid,
                    'password' => $password
                ]);
                
                $stmt->bindParam(':device_id', $deviceId);
                $stmt->bindParam(':task_data', $taskData);
                $stmt->execute();
                
                $taskId = $db->lastInsertId();
                
                // Also update the devices table with the new SSID and password for reference
                try {
                    $updateStmt = $db->prepare("
                        UPDATE devices 
                        SET ssid = :ssid, wifi_password = :password
                        WHERE id = :device_id
                    ");
                    $updateStmt->bindParam(':ssid', $ssid);
                    $updateStmt->bindParam(':password', $password);
                    $updateStmt->bindParam(':device_id', $deviceId);
                    $updateStmt->execute();
                    
                    writeLog("Updated device record with new SSID: $ssid");
                } catch (PDOException $e) {
                    writeLog("Database error updating device record: " . $e->getMessage());
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'WiFi configuration task created',
                    'task_id' => $taskId
                ]);
                exit;
                
            } catch (PDOException $e) {
                writeLog("Database error creating WiFi task: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'error' => 'Database error',
                    'details' => $e->getMessage()
                ]);
                exit;
            }
            break;
            
        case 'configure_wan':
            // Handle WAN configuration
            if (empty($postData['device_id']) || empty($postData['connection_type'])) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Missing required fields',
                    'details' => 'The fields "device_id" and "connection_type" are required'
                ]);
                exit;
            }
            
            $deviceId = $postData['device_id'];
            $connectionType = $postData['connection_type'];
            
            // Create a task for the device
            try {
                $stmt = $db->prepare("
                    INSERT INTO device_tasks (device_id, task_type, task_data, status)
                    VALUES (:device_id, 'wan', :task_data, 'pending')
                ");
                
                $taskData = json_encode($postData);
                
                $stmt->bindParam(':device_id', $deviceId);
                $stmt->bindParam(':task_data', $taskData);
                $stmt->execute();
                
                $taskId = $db->lastInsertId();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'WAN configuration task created',
                    'task_id' => $taskId
                ]);
                exit;
                
            } catch (PDOException $e) {
                writeLog("Database error creating WAN task: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'error' => 'Database error',
                    'details' => $e->getMessage()
                ]);
                exit;
            }
            break;
            
        case 'reboot':
            // Handle device reboot
            if (empty($postData['device_id'])) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Missing required field',
                    'details' => 'The "device_id" field is required'
                ]);
                exit;
            }
            
            $deviceId = $postData['device_id'];
            $reason = $postData['reason'] ?? 'API requested reboot';
            
            // Create a task for the device
            try {
                $stmt = $db->prepare("
                    INSERT INTO device_tasks (device_id, task_type, task_data, status)
                    VALUES (:device_id, 'reboot', :task_data, 'pending')
                ");
                
                $taskData = json_encode([
                    'reason' => $reason
                ]);
                
                $stmt->bindParam(':device_id', $deviceId);
                $stmt->bindParam(':task_data', $taskData);
                $stmt->execute();
                
                $taskId = $db->lastInsertId();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Reboot task created',
                    'task_id' => $taskId
                ]);
                exit;
                
            } catch (PDOException $e) {
                writeLog("Database error creating reboot task: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'error' => 'Database error',
                    'details' => $e->getMessage()
                ]);
                exit;
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'error' => 'Invalid action',
                'details' => "The action '{$action}' is not supported"
            ]);
            exit;
    }
}

// Unsupported HTTP method
http_response_code(405);
echo json_encode([
    'error' => 'Method not allowed',
    'details' => "The HTTP method '{$_SERVER['REQUEST_METHOD']}' is not supported"
]);
