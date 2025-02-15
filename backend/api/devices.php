
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
    $sql = "SELECT * FROM devices ORDER BY last_contact DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($devices);
}

function getDevice($db, $id) {
    $sql = "SELECT d.*, p.param_name, p.param_value, p.param_type 
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
                'lastContact' => $row['last_contact'],
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
    
    echo json_encode($result);
}
