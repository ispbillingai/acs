
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

// Include device manager if available
if (file_exists(__DIR__ . '/../../tr069/device_manager.php')) {
    require_once __DIR__ . '/../../tr069/device_manager.php';
    $deviceManager = new DeviceManager($db);
} else {
    // Simple function for storing parameters if DeviceManager isn't available
    function storeDeviceParameter($db, $deviceId, $paramName, $paramValue, $paramType = 'string') {
        try {
            // Check if parameter exists
            $checkSql = "SELECT id FROM device_parameters WHERE device_id = :device_id AND param_name = :param_name";
            $checkStmt = $db->prepare($checkSql);
            $checkStmt->execute([
                ':device_id' => $deviceId,
                ':param_name' => $paramName
            ]);
            $existingParam = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingParam) {
                // Update existing parameter
                $sql = "UPDATE device_parameters SET 
                    param_value = :param_value,
                    param_type = :param_type,
                    updated_at = NOW()
                    WHERE id = :id";
                $stmt = $db->prepare($sql);
                return $stmt->execute([
                    ':param_value' => $paramValue,
                    ':param_type' => $paramType,
                    ':id' => $existingParam['id']
                ]);
            } else {
                // Insert new parameter
                $sql = "INSERT INTO device_parameters (
                    device_id, 
                    param_name, 
                    param_value, 
                    param_type
                ) VALUES (
                    :device_id,
                    :param_name,
                    :param_value,
                    :param_type
                )";
                $stmt = $db->prepare($sql);
                return $stmt->execute([
                    ':device_id' => $deviceId,
                    ':param_name' => $paramName,
                    ':param_value' => $paramValue,
                    ':param_type' => $paramType
                ]);
            }
        } catch (PDOException $e) {
            error_log("Database error in storeDeviceParameter: " . $e->getMessage());
            return false;
        }
    }
}

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
writeLog("Headers: " . print_r(getallheaders(), true));

// Parse JSON input for POST/PUT requests
$inputData = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
    $inputJSON = file_get_contents('php://input');
    writeLog("Raw input: " . $inputJSON);
    
    if (!empty($inputJSON)) {
        $inputData = json_decode($inputJSON, true);
        
        if ($inputData === null && json_last_error() !== JSON_ERROR_NONE) {
            writeLog("JSON Parse Error: " . json_last_error_msg());
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data provided', 'details' => json_last_error_msg()]);
            exit;
        }
        
        writeLog("Input data: " . print_r($inputData, true));
    } else {
        // If no JSON was provided, use normal POST data
        $inputData = $_POST;
        writeLog("Using POST data: " . print_r($inputData, true));
    }
}

// If no device ID is specified but we just need a list of all devices
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['list_all_devices'])) {
    try {
        $stmt = $db->prepare("
            SELECT 
                id,
                serial_number as serialNumber,
                manufacturer,
                model_name as model,
                status,
                last_contact as lastContact,
                ip_address as ipAddress
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
        writeLog("Database error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error', 'details' => $e->getMessage()]);
        exit;
    }
}

// Process GET request - retrieve parameters
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Check if device ID or serial number is provided
    $deviceIdentifier = $_GET['device_id'] ?? ($_GET['serial'] ?? null);
    $paramName = $_GET['param'] ?? null;
    
    if (empty($deviceIdentifier)) {
        http_response_code(400);
        echo json_encode(['error' => 'Device identifier (device_id or serial) is required']);
        exit;
    }
    
    try {
        // Determine if we're using device ID or serial number
        $isDeviceId = is_numeric($deviceIdentifier);
        
        // Query to get device ID if serial number was provided
        if (!$isDeviceId) {
            $stmt = $db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
            $stmt->execute([':serial' => $deviceIdentifier]);
            $deviceId = $stmt->fetchColumn();
            
            if (!$deviceId) {
                http_response_code(404);
                echo json_encode(['error' => 'Device not found with serial number: ' . $deviceIdentifier]);
                exit;
            }
        } else {
            $deviceId = $deviceIdentifier;
        }
        
        // If parameter name is specified, get only that parameter
        if ($paramName) {
            $stmt = $db->prepare("
                SELECT param_name, param_value, param_type, updated_at
                FROM device_parameters
                WHERE device_id = :device_id AND param_name = :param_name
            ");
            $stmt->execute([
                ':device_id' => $deviceId,
                ':param_name' => $paramName
            ]);
            $parameter = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$parameter) {
                http_response_code(404);
                echo json_encode(['error' => 'Parameter not found: ' . $paramName]);
                exit;
            }
            
            echo json_encode([
                'success' => true,
                'parameter' => [
                    'name' => $parameter['param_name'],
                    'value' => $parameter['param_value'],
                    'type' => $parameter['param_type'],
                    'updated_at' => $parameter['updated_at']
                ]
            ]);
            exit;
        }
        
        // Get all parameters for the device
        $stmt = $db->prepare("
            SELECT param_name, param_value, param_type, updated_at
            FROM device_parameters
            WHERE device_id = :device_id
            ORDER BY param_name
        ");
        $stmt->execute([':device_id' => $deviceId]);
        $parameters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get basic device info too
        $deviceStmt = $db->prepare("
            SELECT 
                id,
                serial_number as serialNumber,
                manufacturer,
                model_name as model,
                status,
                last_contact as lastContact,
                ip_address as ipAddress,
                software_version as softwareVersion,
                hardware_version as hardwareVersion,
                ssid,
                tr069_password as tr069Password,
                local_admin_password as localAdminPassword
            FROM devices
            WHERE id = :device_id
        ");
        $deviceStmt->execute([':device_id' => $deviceId]);
        $device = $deviceStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$device) {
            http_response_code(404);
            echo json_encode(['error' => 'Device not found with ID: ' . $deviceId]);
            exit;
        }
        
        // Format parameters for response
        $formattedParams = [];
        foreach ($parameters as $param) {
            $formattedParams[] = [
                'name' => $param['param_name'],
                'value' => $param['param_value'],
                'type' => $param['param_type'],
                'updated_at' => $param['updated_at']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'device' => $device,
            'parameters' => $formattedParams
        ]);
    } catch (PDOException $e) {
        writeLog("Database error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error', 'details' => $e->getMessage()]);
    }
}
// Process POST request - set parameters or create tasks
else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($inputData)) {
        http_response_code(400);
        echo json_encode(['error' => 'No data provided']);
        exit;
    }
    
    // Check if we have action and device identifier
    $action = $inputData['action'] ?? null;
    $deviceIdentifier = $inputData['device_id'] ?? ($inputData['serial'] ?? null);
    
    if (empty($deviceIdentifier)) {
        http_response_code(400);
        echo json_encode(['error' => 'Device identifier (device_id or serial) is required']);
        exit;
    }
    
    try {
        // Determine if we're using device ID or serial number
        $isDeviceId = is_numeric($deviceIdentifier);
        
        // Query to get device ID if serial number was provided
        if (!$isDeviceId) {
            $stmt = $db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
            $stmt->execute([':serial' => $deviceIdentifier]);
            $deviceId = $stmt->fetchColumn();
            
            if (!$deviceId) {
                http_response_code(404);
                echo json_encode(['error' => 'Device not found with serial number: ' . $deviceIdentifier]);
                exit;
            }
        } else {
            $deviceId = $deviceIdentifier;
        }
        
        // Handle different actions
        switch ($action) {
            case 'set_parameter':
                // Directly set a parameter in the database
                if (!isset($inputData['param_name']) || !isset($inputData['param_value'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Parameter name and value are required']);
                    exit;
                }
                
                $paramName = $inputData['param_name'];
                $paramValue = $inputData['param_value'];
                $paramType = $inputData['param_type'] ?? 'string';
                
                // Store parameter using device manager or local function
                if (isset($deviceManager)) {
                    $success = $deviceManager->storeDeviceParameter($deviceId, $paramName, $paramValue, $paramType);
                } else {
                    $success = storeDeviceParameter($db, $deviceId, $paramName, $paramValue, $paramType);
                }
                
                if ($success) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Parameter set successfully',
                        'parameter' => [
                            'name' => $paramName,
                            'value' => $paramValue,
                            'type' => $paramType
                        ]
                    ]);
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to set parameter']);
                }
                break;
                
            case 'update_device':
                // Update device basic information
                $updateFields = [];
                $params = [];
                
                // Build query dynamically based on provided fields
                if (isset($inputData['manufacturer'])) {
                    $updateFields[] = "manufacturer = :manufacturer";
                    $params[':manufacturer'] = $inputData['manufacturer'];
                }
                
                if (isset($inputData['model'])) {
                    $updateFields[] = "model_name = :model";
                    $params[':model'] = $inputData['model'];
                }
                
                if (isset($inputData['software_version'])) {
                    $updateFields[] = "software_version = :software_version";
                    $params[':software_version'] = $inputData['software_version'];
                }
                
                if (isset($inputData['hardware_version'])) {
                    $updateFields[] = "hardware_version = :hardware_version";
                    $params[':hardware_version'] = $inputData['hardware_version'];
                }
                
                if (isset($inputData['ip_address'])) {
                    $updateFields[] = "ip_address = :ip_address";
                    $params[':ip_address'] = $inputData['ip_address'];
                }
                
                if (isset($inputData['ssid'])) {
                    $updateFields[] = "ssid = :ssid";
                    $params[':ssid'] = $inputData['ssid'];
                }
                
                if (isset($inputData['ssid_password'])) {
                    $updateFields[] = "ssid_password = :ssid_password";
                    $params[':ssid_password'] = $inputData['ssid_password'];
                }
                
                if (isset($inputData['tr069_password'])) {
                    $updateFields[] = "tr069_password = :tr069_password";
                    $params[':tr069_password'] = $inputData['tr069_password'];
                }
                
                if (isset($inputData['local_admin_password'])) {
                    $updateFields[] = "local_admin_password = :local_admin_password";
                    $params[':local_admin_password'] = $inputData['local_admin_password'];
                }
                
                // Only proceed if we have fields to update
                if (empty($updateFields)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'No device fields provided for update']);
                    exit;
                }
                
                // Add device ID to params
                $params[':device_id'] = $deviceId;
                
                // Construct and execute query
                $sql = "UPDATE devices SET " . implode(", ", $updateFields) . " WHERE id = :device_id";
                $stmt = $db->prepare($sql);
                $result = $stmt->execute($params);
                
                if ($result) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Device updated successfully',
                        'device_id' => $deviceId
                    ]);
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to update device']);
                }
                break;
                
            case 'configure_wifi':
                // Create a WiFi configuration task
                if (!isset($inputData['ssid'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'SSID is required for WiFi configuration']);
                    exit;
                }
                
                $ssid = $inputData['ssid'];
                $password = $inputData['password'] ?? '';
                
                // Create task data
                $taskData = json_encode([
                    'ssid' => $ssid,
                    'password' => $password
                ]);
                
                // First check if the device_tasks table exists
                try {
                    $checkTable = $db->query("SHOW TABLES LIKE 'device_tasks'");
                    $tableExists = $checkTable->rowCount() > 0;
                    
                    if (!$tableExists) {
                        // Create the device_tasks table
                        $createTableSql = "CREATE TABLE IF NOT EXISTS device_tasks (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            device_id INT NOT NULL,
                            task_type VARCHAR(50) NOT NULL COMMENT 'wifi, wan, reboot, etc.',
                            task_data TEXT NOT NULL COMMENT 'JSON encoded parameters',
                            status ENUM('pending', 'in_progress', 'completed', 'failed', 'canceled') NOT NULL DEFAULT 'pending',
                            message TEXT,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
                        ) ENGINE=InnoDB";
                        
                        $db->exec($createTableSql);
                        writeLog("Created device_tasks table");
                    }
                    
                    // Cancel any existing pending WiFi tasks
                    $cancelStmt = $db->prepare("
                        UPDATE device_tasks 
                        SET status = 'canceled', message = 'Superseded by newer task', updated_at = NOW()
                        WHERE device_id = :device_id AND task_type = 'wifi' AND status = 'pending'
                    ");
                    $cancelStmt->execute([':device_id' => $deviceId]);
                    
                    // Insert task
                    $stmt = $db->prepare("
                        INSERT INTO device_tasks (device_id, task_type, task_data, status, created_at)
                        VALUES (:device_id, 'wifi', :task_data, 'pending', NOW())
                    ");
                    
                    $stmt->execute([
                        ':device_id' => $deviceId,
                        ':task_data' => $taskData
                    ]);
                    
                    $taskId = $db->lastInsertId();
                    
                    // Also update the device record directly
                    $updateDeviceStmt = $db->prepare("
                        UPDATE devices SET ssid = :ssid, ssid_password = :password WHERE id = :device_id
                    ");
                    $updateDeviceStmt->execute([
                        ':ssid' => $ssid,
                        ':password' => $password,
                        ':device_id' => $deviceId
                    ]);
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'WiFi configuration task created successfully',
                        'task_id' => $taskId
                    ]);
                } catch (PDOException $e) {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to create WiFi configuration task', 'details' => $e->getMessage()]);
                }
                break;
                
            case 'configure_wan':
                // Create a WAN configuration task
                $connectionType = $inputData['connection_type'] ?? 'DHCP';
                
                // Prepare task data based on connection type
                $taskData = [
                    'connection_type' => $connectionType
                ];
                
                // Add connection-type specific parameters
                if ($connectionType === 'Static') {
                    if (!isset($inputData['ip_address']) || !isset($inputData['subnet_mask'])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'IP address and subnet mask are required for Static IP configuration']);
                        exit;
                    }
                    
                    $taskData['ip_address'] = $inputData['ip_address'];
                    $taskData['subnet_mask'] = $inputData['subnet_mask'];
                    $taskData['gateway'] = $inputData['gateway'] ?? '';
                    $taskData['dns_server1'] = $inputData['dns_server1'] ?? '';
                    $taskData['dns_server2'] = $inputData['dns_server2'] ?? '';
                } 
                elseif ($connectionType === 'PPPoE') {
                    if (!isset($inputData['pppoe_username'])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Username is required for PPPoE configuration']);
                        exit;
                    }
                    
                    $taskData['pppoe_username'] = $inputData['pppoe_username'];
                    $taskData['pppoe_password'] = $inputData['pppoe_password'] ?? '';
                }
                
                try {
                    // Check if device_tasks table exists
                    $checkTable = $db->query("SHOW TABLES LIKE 'device_tasks'");
                    $tableExists = $checkTable->rowCount() > 0;
                    
                    if (!$tableExists) {
                        // Create the device_tasks table
                        $createTableSql = "CREATE TABLE IF NOT EXISTS device_tasks (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            device_id INT NOT NULL,
                            task_type VARCHAR(50) NOT NULL COMMENT 'wifi, wan, reboot, etc.',
                            task_data TEXT NOT NULL COMMENT 'JSON encoded parameters',
                            status ENUM('pending', 'in_progress', 'completed', 'failed', 'canceled') NOT NULL DEFAULT 'pending',
                            message TEXT,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
                        ) ENGINE=InnoDB";
                        
                        $db->exec($createTableSql);
                        writeLog("Created device_tasks table");
                    }
                    
                    // Cancel any existing pending WAN tasks
                    $cancelStmt = $db->prepare("
                        UPDATE device_tasks 
                        SET status = 'canceled', message = 'Superseded by newer task', updated_at = NOW()
                        WHERE device_id = :device_id AND task_type = 'wan' AND status = 'pending'
                    ");
                    $cancelStmt->execute([':device_id' => $deviceId]);
                    
                    // Insert task
                    $stmt = $db->prepare("
                        INSERT INTO device_tasks (device_id, task_type, task_data, status, created_at)
                        VALUES (:device_id, 'wan', :task_data, 'pending', NOW())
                    ");
                    
                    $stmt->execute([
                        ':device_id' => $deviceId,
                        ':task_data' => json_encode($taskData)
                    ]);
                    
                    $taskId = $db->lastInsertId();
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'WAN configuration task created successfully',
                        'task_id' => $taskId
                    ]);
                } catch (PDOException $e) {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to create WAN configuration task', 'details' => $e->getMessage()]);
                }
                break;
                
            case 'reboot':
                // Create a reboot task
                $reason = $inputData['reason'] ?? 'API initiated reboot';
                
                // Create task data
                $taskData = json_encode([
                    'reboot_reason' => $reason
                ]);
                
                try {
                    // Check if device_tasks table exists
                    $checkTable = $db->query("SHOW TABLES LIKE 'device_tasks'");
                    $tableExists = $checkTable->rowCount() > 0;
                    
                    if (!$tableExists) {
                        // Create the device_tasks table
                        $createTableSql = "CREATE TABLE IF NOT EXISTS device_tasks (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            device_id INT NOT NULL,
                            task_type VARCHAR(50) NOT NULL COMMENT 'wifi, wan, reboot, etc.',
                            task_data TEXT NOT NULL COMMENT 'JSON encoded parameters',
                            status ENUM('pending', 'in_progress', 'completed', 'failed', 'canceled') NOT NULL DEFAULT 'pending',
                            message TEXT,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
                        ) ENGINE=InnoDB";
                        
                        $db->exec($createTableSql);
                        writeLog("Created device_tasks table");
                    }
                
                    // Insert task
                    $stmt = $db->prepare("
                        INSERT INTO device_tasks (device_id, task_type, task_data, status, created_at)
                        VALUES (:device_id, 'reboot', :task_data, 'pending', NOW())
                    ");
                    
                    $stmt->execute([
                        ':device_id' => $deviceId,
                        ':task_data' => $taskData
                    ]);
                    
                    $taskId = $db->lastInsertId();
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Reboot task created successfully',
                        'task_id' => $taskId
                    ]);
                } catch (PDOException $e) {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to create reboot task', 'details' => $e->getMessage()]);
                }
                break;
                
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unsupported action: ' . $action]);
                break;
        }
    } catch (PDOException $e) {
        writeLog("Database error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error', 'details' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
