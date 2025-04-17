
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

// Include device manager
require_once __DIR__ . '/../../tr069/device_manager.php';
$deviceManager = new DeviceManager($db);

// Log function for debugging
function writeLog($message) {
    $logFile = $_SERVER['DOCUMENT_ROOT'] . '/rest_api.log';
    $timestamp = date('Y-m-d H:i:s');
    
    // Make sure the log is writable
    if (!file_exists($logFile)) {
        touch($logFile);
        chmod($logFile, 0666);
    }
    
    // Log to file
    file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

writeLog("REST API Devices Request: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);

// Parse JSON input for POST/PUT requests
$inputData = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
    $inputJSON = file_get_contents('php://input');
    $inputData = json_decode($inputJSON, true);
    
    if ($inputData === null && json_last_error() !== JSON_ERROR_NONE) {
        writeLog("JSON Parse Error: " . json_last_error_msg());
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON data provided', 'details' => json_last_error_msg()]);
        exit;
    }
    
    writeLog("Input data: " . print_r($inputData, true));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get device list or specific device
    $deviceId = $_GET['id'] ?? null;
    $serialNumber = $_GET['serial'] ?? null;
    
    try {
        if ($deviceId) {
            // Get specific device by ID
            $stmt = $db->prepare("
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
                    ssid_password as ssidPassword,
                    tr069_password as tr069Password,
                    local_admin_password as localAdminPassword,
                    connected_clients as connectedClients,
                    uptime
                FROM devices
                WHERE id = :id
            ");
            $stmt->execute([':id' => $deviceId]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$device) {
                http_response_code(404);
                echo json_encode(['error' => 'Device not found']);
                exit;
            }
            
            // Get connected clients
            $clientsStmt = $db->prepare("
                SELECT 
                    id,
                    ip_address as ipAddress,
                    mac_address as macAddress,
                    hostname,
                    last_seen as lastSeen,
                    is_active as isActive
                FROM connected_clients
                WHERE device_id = :device_id
                ORDER BY last_seen DESC
            ");
            $clientsStmt->execute([':device_id' => $deviceId]);
            $clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $device['connectedHosts'] = $clients;
            
            // Get device parameters
            $paramsStmt = $db->prepare("
                SELECT 
                    param_name as name,
                    param_value as value,
                    param_type as type,
                    updated_at as updatedAt
                FROM device_parameters
                WHERE device_id = :device_id
                ORDER BY param_name
            ");
            $paramsStmt->execute([':device_id' => $deviceId]);
            $parameters = $paramsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $device['parameters'] = $parameters;
            
            echo json_encode([
                'success' => true,
                'device' => $device
            ]);
        } 
        else if ($serialNumber) {
            // Get specific device by serial number
            $stmt = $db->prepare("
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
                    ssid_password as ssidPassword,
                    tr069_password as tr069Password,
                    local_admin_password as localAdminPassword,
                    connected_clients as connectedClients,
                    uptime
                FROM devices
                WHERE serial_number = :serial
            ");
            $stmt->execute([':serial' => $serialNumber]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$device) {
                http_response_code(404);
                echo json_encode(['error' => 'Device not found']);
                exit;
            }
            
            // Get connected clients
            $clientsStmt = $db->prepare("
                SELECT 
                    id,
                    ip_address as ipAddress,
                    mac_address as macAddress,
                    hostname,
                    last_seen as lastSeen,
                    is_active as isActive
                FROM connected_clients
                WHERE device_id = :device_id
                ORDER BY last_seen DESC
            ");
            $clientsStmt->execute([':device_id' => $device['id']]);
            $clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $device['connectedHosts'] = $clients;
            
            // Get device parameters
            $paramsStmt = $db->prepare("
                SELECT 
                    param_name as name,
                    param_value as value,
                    param_type as type,
                    updated_at as updatedAt
                FROM device_parameters
                WHERE device_id = :device_id
                ORDER BY param_name
            ");
            $paramsStmt->execute([':device_id' => $device['id']]);
            $parameters = $paramsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $device['parameters'] = $parameters;
            
            echo json_encode([
                'success' => true,
                'device' => $device
            ]);
        } 
        else {
            // Get all devices with pagination
            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
            $offset = ($page - 1) * $limit;
            
            $query = "
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
                    connected_clients as connectedClients
                FROM devices
                ORDER BY last_contact DESC
                LIMIT :limit OFFSET :offset
            ";
            
            $stmt = $db->prepare($query);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count for pagination
            $countStmt = $db->prepare("SELECT COUNT(*) FROM devices");
            $countStmt->execute();
            $totalCount = $countStmt->fetchColumn();
            $totalPages = ceil($totalCount / $limit);
            
            // Update online/offline status based on last contact time
            $fiveMinutesAgo = date('Y-m-d H:i:s', strtotime('-5 minutes'));
            foreach ($devices as &$device) {
                $device['status'] = strtotime($device['lastContact']) > strtotime($fiveMinutesAgo) ? 'online' : 'offline';
            }
            
            echo json_encode([
                'success' => true,
                'devices' => $devices,
                'pagination' => [
                    'total' => $totalCount,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => $totalPages
                ]
            ]);
        }
    } catch (PDOException $e) {
        writeLog("Database error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error', 'details' => $e->getMessage()]);
    }
} 
else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create or update device
    if (empty($inputData)) {
        http_response_code(400);
        echo json_encode(['error' => 'No data provided']);
        exit;
    }
    
    try {
        // Check if device exists by serial number
        $serialNumber = $inputData['serialNumber'] ?? null;
        
        if (!$serialNumber) {
            http_response_code(400);
            echo json_encode(['error' => 'Serial number is required']);
            exit;
        }
        
        // Create device info from input data
        $deviceInfo = [
            'serialNumber' => $serialNumber,
            'manufacturer' => $inputData['manufacturer'] ?? null,
            'modelName' => $inputData['model'] ?? null,
            'macAddress' => $inputData['macAddress'] ?? null,
            'status' => $inputData['status'] ?? 'online',
            'ipAddress' => $inputData['ipAddress'] ?? $_SERVER['REMOTE_ADDR'],
            'softwareVersion' => $inputData['softwareVersion'] ?? null,
            'hardwareVersion' => $inputData['hardwareVersion'] ?? null,
            'ssid' => $inputData['ssid'] ?? null,
            'ssidPassword' => $inputData['ssidPassword'] ?? null,
            'uptime' => $inputData['uptime'] ?? 0,
            'localAdminPassword' => $inputData['localAdminPassword'] ?? null,
            'tr069Password' => $inputData['tr069Password'] ?? null,
            'connectedClients' => $inputData['connectedClients'] ?? 0
        ];
        
        // Update or create device
        $deviceId = $deviceManager->updateDevice($deviceInfo);
        
        // If parameters are provided, store them
        if (isset($inputData['parameters']) && is_array($inputData['parameters'])) {
            foreach ($inputData['parameters'] as $param) {
                if (isset($param['name']) && isset($param['value'])) {
                    $deviceManager->storeDeviceParameter(
                        $deviceId, 
                        $param['name'], 
                        $param['value']
                    );
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Device updated successfully',
            'device_id' => $deviceId
        ]);
        
    } catch (Exception $e) {
        writeLog("Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Error updating device', 'details' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
