
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            getDevice($db, $_GET['id']);
        } else {
            getDevices($db);
        }
        break;
    
    case 'POST':
        // Handle device updates
        break;
    
    default:
        http_response_code(405);
        echo json_encode(['message' => 'Method not allowed']);
        break;
}

function getDevices($db) {
    try {
        // Get devices with their latest connection time
        $sql = "SELECT 
                id,
                serial_number as serialNumber,
                manufacturer,
                model_name as model,
                status,
                last_contact as lastContact,
                ip_address as ipAddress,
                software_version as softwareVersion,
                hardware_version as hardwareVersion
                FROM devices 
                WHERE last_contact >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                ORDER BY last_contact DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format timestamps
        foreach ($devices as &$device) {
            $device['lastContact'] = date('Y-m-d H:i:s', strtotime($device['lastContact']));
            // Set status based on last contact time
            $lastContact = strtotime($device['lastContact']);
            $fiveMinutesAgo = strtotime('-5 minutes');
            $device['status'] = $lastContact >= $fiveMinutesAgo ? 'online' : 'offline';
        }
        
        echo json_encode($devices);
    } catch (PDOException $e) {
        error_log("Database error in getDevices: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch devices']);
    }
}

function getDevice($db, $id) {
    try {
        $sql = "SELECT 
                d.*,
                p.param_name,
                p.param_value,
                p.param_type 
                FROM devices d 
                LEFT JOIN parameters p ON d.id = p.device_id 
                WHERE d.id = :id";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (empty($result)) {
                $result = [
                    'id' => $row['id'],
                    'serialNumber' => $row['serial_number'],
                    'manufacturer' => $row['manufacturer'],
                    'model' => $row['model_name'],
                    'status' => $row['status'],
                    'lastContact' => date('Y-m-d H:i:s', strtotime($row['last_contact'])),
                    'ipAddress' => $row['ip_address'],
                    'softwareVersion' => $row['software_version'],
                    'hardwareVersion' => $row['hardware_version'],
                    'parameters' => []
                ];
            }
            
            if ($row['param_name']) {
                $result['parameters'][] = [
                    'name' => $row['param_name'],
                    'value' => $row['param_value'],
                    'type' => $row['param_type']
                ];
            }
        }
        
        // Update status based on last contact time
        if (!empty($result)) {
            $lastContact = strtotime($result['lastContact']);
            $fiveMinutesAgo = strtotime('-5 minutes');
            $result['status'] = $lastContact >= $fiveMinutesAgo ? 'online' : 'offline';
        }
        
        echo json_encode($result);
    } catch (PDOException $e) {
        error_log("Database error in getDevice: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch device details']);
    }
}
