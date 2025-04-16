
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
        $data = json_decode(file_get_contents('php://input'), true);
        if ($data) {
            updateDeviceParameters($db, $data);
        } else {
            http_response_code(400);
            echo json_encode(['message' => 'Invalid input data']);
        }
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
        // First get the device basic info
        $deviceSql = "SELECT 
                d.*,
                d.serial_number as serialNumber,
                d.model_name as model,
                d.ip_address as ipAddress,
                d.software_version as softwareVersion,
                d.hardware_version as hardwareVersion,
                d.last_contact as lastContact
                FROM devices d
                WHERE d.id = :id";
        
        $deviceStmt = $db->prepare($deviceSql);
        $deviceStmt->execute([':id' => $id]);
        $device = $deviceStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$device) {
            http_response_code(404);
            echo json_encode(['error' => 'Device not found']);
            return;
        }
        
        // Format the device data
        $result = [
            'id' => $device['id'],
            'serialNumber' => $device['serialNumber'],
            'manufacturer' => $device['manufacturer'],
            'model' => $device['model'],
            'status' => $device['status'],
            'lastContact' => date('Y-m-d H:i:s', strtotime($device['lastContact'])),
            'ipAddress' => $device['ipAddress'],
            'softwareVersion' => $device['softwareVersion'],
            'hardwareVersion' => $device['hardwareVersion'],
            'parameters' => [],
            'connectedHosts' => []
        ];
        
        // Get parameters
        $paramsSql = "SELECT 
                param_name as name,
                param_value as value,
                param_type as type
                FROM parameters 
                WHERE device_id = :id";
        
        $paramsStmt = $db->prepare($paramsSql);
        $paramsStmt->execute([':id' => $id]);
        $result['parameters'] = $paramsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get connected hosts
        $hostsSql = "SELECT 
                id,
                mac_address as macAddress,
                ip_address as ipAddress,
                hostname,
                last_seen as lastSeen,
                is_active as isActive
                FROM connected_clients 
                WHERE device_id = :id";
        
        $hostsStmt = $db->prepare($hostsSql);
        $hostsStmt->execute([':id' => $id]);
        $result['connectedHosts'] = $hostsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Update status based on last contact time
        $lastContact = strtotime($result['lastContact']);
        $fiveMinutesAgo = strtotime('-5 minutes');
        $result['status'] = $lastContact >= $fiveMinutesAgo ? 'online' : 'offline';
        
        echo json_encode($result);
    } catch (PDOException $e) {
        error_log("Database error in getDevice: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch device details']);
    }
}

function updateDeviceParameters($db, $data) {
    try {
        // Check if required fields are present
        if (!isset($data['serialNumber'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Serial number is required']);
            return;
        }
        
        // Check if device exists
        $checkSql = "SELECT id FROM devices WHERE serial_number = :serialNumber";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([':serialNumber' => $data['serialNumber']]);
        $device = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        $deviceId = null;
        
        // If device doesn't exist, create it
        if (!$device) {
            $insertSql = "INSERT INTO devices (
                serial_number, 
                manufacturer, 
                model_name, 
                ip_address, 
                status, 
                last_contact
            ) VALUES (
                :serialNumber, 
                :manufacturer, 
                :modelName, 
                :ipAddress, 
                'online', 
                NOW()
            )";
            
            $insertStmt = $db->prepare($insertSql);
            $insertStmt->execute([
                ':serialNumber' => $data['serialNumber'],
                ':manufacturer' => $data['manufacturer'] ?? 'Unknown',
                ':modelName' => $data['modelName'] ?? 'Unknown',
                ':ipAddress' => $data['ipAddress'] ?? '0.0.0.0'
            ]);
            
            $deviceId = $db->lastInsertId();
        } else {
            $deviceId = $device['id'];
            
            // Update device basic info
            $updateSql = "UPDATE devices SET 
                manufacturer = :manufacturer,
                model_name = :modelName,
                ip_address = :ipAddress,
                status = 'online',
                last_contact = NOW()
                WHERE id = :id";
            
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute([
                ':manufacturer' => $data['manufacturer'] ?? 'Unknown',
                ':modelName' => $data['modelName'] ?? 'Unknown',
                ':ipAddress' => $data['ipAddress'] ?? '0.0.0.0',
                ':id' => $deviceId
            ]);
        }
        
        // Store parameters if available
        if (isset($data['parameters']) && is_array($data['parameters'])) {
            foreach ($data['parameters'] as $param) {
                if (isset($param['name']) && isset($param['value'])) {
                    // Check if parameter exists
                    $checkParamSql = "SELECT id FROM parameters WHERE device_id = :deviceId AND param_name = :name";
                    $checkParamStmt = $db->prepare($checkParamSql);
                    $checkParamStmt->execute([
                        ':deviceId' => $deviceId,
                        ':name' => $param['name']
                    ]);
                    $existingParam = $checkParamStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existingParam) {
                        // Update existing parameter
                        $updateParamSql = "UPDATE parameters SET 
                            param_value = :value,
                            param_type = :type,
                            updated_at = NOW()
                            WHERE id = :id";
                        
                        $updateParamStmt = $db->prepare($updateParamSql);
                        $updateParamStmt->execute([
                            ':value' => $param['value'],
                            ':type' => $param['type'] ?? 'string',
                            ':id' => $existingParam['id']
                        ]);
                    } else {
                        // Insert new parameter
                        $insertParamSql = "INSERT INTO parameters (
                            device_id,
                            param_name,
                            param_value,
                            param_type
                        ) VALUES (
                            :deviceId,
                            :name,
                            :value,
                            :type
                        )";
                        
                        $insertParamStmt = $db->prepare($insertParamSql);
                        $insertParamStmt->execute([
                            ':deviceId' => $deviceId,
                            ':name' => $param['name'],
                            ':value' => $param['value'],
                            ':type' => $param['type'] ?? 'string'
                        ]);
                    }
                }
            }
        }
        
        // Store connected hosts if available
        if (isset($data['connectedHosts']) && is_array($data['connectedHosts'])) {
            // First, mark all existing hosts as inactive
            $markInactiveSql = "UPDATE connected_clients SET is_active = 0 WHERE device_id = :deviceId";
            $markInactiveStmt = $db->prepare($markInactiveSql);
            $markInactiveStmt->execute([':deviceId' => $deviceId]);
            
            foreach ($data['connectedHosts'] as $host) {
                if (isset($host['ipAddress'])) {
                    // Check if host exists
                    $checkHostSql = "SELECT id FROM connected_clients 
                                    WHERE device_id = :deviceId AND ip_address = :ipAddress";
                    $checkHostStmt = $db->prepare($checkHostSql);
                    $checkHostStmt->execute([
                        ':deviceId' => $deviceId,
                        ':ipAddress' => $host['ipAddress']
                    ]);
                    $existingHost = $checkHostStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existingHost) {
                        // Update existing host
                        $updateHostSql = "UPDATE connected_clients SET 
                            hostname = :hostname,
                            mac_address = :macAddress,
                            is_active = 1,
                            last_seen = NOW()
                            WHERE id = :id";
                        
                        $updateHostStmt = $db->prepare($updateHostSql);
                        $updateHostStmt->execute([
                            ':hostname' => $host['hostname'] ?? '',
                            ':macAddress' => $host['macAddress'] ?? '',
                            ':id' => $existingHost['id']
                        ]);
                    } else {
                        // Insert new host
                        $insertHostSql = "INSERT INTO connected_clients (
                            device_id,
                            ip_address,
                            hostname,
                            mac_address,
                            is_active,
                            last_seen
                        ) VALUES (
                            :deviceId,
                            :ipAddress,
                            :hostname,
                            :macAddress,
                            1,
                            NOW()
                        )";
                        
                        $insertHostStmt = $db->prepare($insertHostSql);
                        $insertHostStmt->execute([
                            ':deviceId' => $deviceId,
                            ':ipAddress' => $host['ipAddress'],
                            ':hostname' => $host['hostname'] ?? '',
                            ':macAddress' => $host['macAddress'] ?? ''
                        ]);
                    }
                }
            }
        }
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Device parameters updated successfully',
            'deviceId' => $deviceId
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in updateDeviceParameters: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update device parameters: ' . $e->getMessage()]);
    }
}
