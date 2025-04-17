
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
                    tr069_last_transaction
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

    // Add more detailed device retrieval logic here if needed
}

// Add other request method handlers as needed
