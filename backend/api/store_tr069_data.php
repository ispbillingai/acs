
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

function writeLog($message, $isImportant = false) {
    // Only log essential operations or when explicitly marked as important
    if ($isImportant) {
        $logFile = __DIR__ . '/../../device.log';
        file_put_contents($logFile, date('Y-m-d H:i:s') . " [INFO] " . $message . "\n", FILE_APPEND);
    }
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    writeLog("Method not allowed: " . $_SERVER['REQUEST_METHOD'], true);
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    writeLog("Processing TR-069 data", true);
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        writeLog("Database connection failed", true);
        throw new Exception("Database connection failed");
    }
    
    // Check if we're processing a file input or direct POST data
    if (isset($_FILES['router_ssids']) && $_FILES['router_ssids']['error'] === UPLOAD_ERR_OK) {
        // Processing file upload
        $filePath = $_FILES['router_ssids']['tmp_name'];
    } else {
        // Path to the router_ssids.txt file
        $filePath = $_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt';
    }
    
    if (!file_exists($filePath)) {
        writeLog("Router data file not found: " . $filePath, true);
        http_response_code(404);
        echo json_encode(['error' => 'Router data file not found']);
        exit;
    }
    
    // Read the file
    $fileContents = file_get_contents($filePath);
    $lines = explode("\n", $fileContents);
    
    // Remove comment lines and empty lines
    $lines = array_filter($lines, function($line) {
        return !empty(trim($line)) && !preg_match('/^#/', trim($line));
    });
    
    // Parse the file and extract parameters
    $parameters = [];
    $serialNumber = null;
    $manufacturer = null;
    $modelName = null;
    $ipAddress = null;
    $hosts = [];
    $hostCount = 0;
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Split the line into parameter name and value
        $parts = explode(' = ', $line, 2);
        if (count($parts) !== 2) continue;
        
        $name = trim($parts[0]);
        $value = trim($parts[1]);
        
        // Store the parameter
        $parameters[] = [
            'name' => $name,
            'value' => $value,
            'type' => 'string'
        ];
        
        // Extract key information
        if (strpos($name, 'SerialNumber') !== false) {
            $serialNumber = $value;
        } else if (strpos($name, 'Manufacturer') !== false) {
            $manufacturer = $value;
        } else if (strpos($name, 'ModelName') !== false || strpos($name, 'ProductClass') !== false) {
            $modelName = $value;
        } else if (strpos($name, 'ExternalIPAddress') !== false) {
            $ipAddress = $value;
        } else if (strpos($name, 'HostNumberOfEntries') !== false) {
            $hostCount = intval($value);
        } else if (preg_match('/Hosts\.Host\.(\d+)\./', $name, $matches)) {
            $hostIndex = $matches[1];
            $hostParts = explode('.', $name);
            $hostProperty = end($hostParts);
            
            if (!isset($hosts[$hostIndex])) {
                $hosts[$hostIndex] = [
                    'ipAddress' => '',
                    'hostname' => '',
                    'macAddress' => '',
                    'isActive' => false
                ];
            }
            
            if ($hostProperty === 'IPAddress') {
                $hosts[$hostIndex]['ipAddress'] = $value;
            } else if ($hostProperty === 'HostName') {
                $hosts[$hostIndex]['hostname'] = $value;
            } else if ($hostProperty === 'PhysAddress' || $hostProperty === 'MACAddress') {
                $hosts[$hostIndex]['macAddress'] = $value;
            } else if ($hostProperty === 'Active') {
                $hosts[$hostIndex]['isActive'] = ($value === '1' || strtolower($value) === 'true');
            }
        }
    }
    
    // If no serial number was found, try to extract it from inform message
    if (empty($serialNumber)) {
        // Look for SerialNumber in various parts of the parameters collection
        foreach ($parameters as $param) {
            if (stripos($param['name'], 'SerialNumber') !== false && !empty($param['value'])) {
                $serialNumber = $param['value'];
                break;
            }
        }
        
        // If still not found, generate a random one
        if (empty($serialNumber)) {
            $serialNumber = 'UNKNOWN-' . time();
        }
    }
    
    // Prepare the data to be sent to the devices API
    $data = [
        'serialNumber' => $serialNumber,
        'manufacturer' => $manufacturer ?? 'Unknown',
        'modelName' => $modelName ?? 'Unknown',
        'ipAddress' => $ipAddress ?? $_SERVER['REMOTE_ADDR'],
        'parameters' => $parameters,
        'connectedHosts' => array_values($hosts)
    ];
    
    writeLog("Processing device data for: " . $serialNumber, true);
    
    // Direct database update - critical section
    try {
        // Begin transaction for data consistency
        $db->beginTransaction();
        
        // Check if device exists
        $checkSql = "SELECT id FROM devices WHERE serial_number = :serialNumber";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([':serialNumber' => $serialNumber]);
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
            $insertResult = $insertStmt->execute([
                ':serialNumber' => $data['serialNumber'],
                ':manufacturer' => $data['manufacturer'],
                ':modelName' => $data['modelName'],
                ':ipAddress' => $data['ipAddress']
            ]);
            
            if (!$insertResult) {
                writeLog("Failed to insert device: " . $serialNumber, true);
                throw new Exception("Failed to insert device");
            }
            
            $deviceId = $db->lastInsertId();
            writeLog("New device created: " . $serialNumber, true);
        } else {
            $deviceId = $device['id'];
            
            // Update device basic info - CRITICAL UPDATE
            $updateSql = "UPDATE devices SET 
                manufacturer = :manufacturer,
                model_name = :modelName,
                ip_address = :ipAddress,
                status = 'online',
                last_contact = NOW()
                WHERE id = :id";
            
            $updateStmt = $db->prepare($updateSql);
            $updateResult = $updateStmt->execute([
                ':manufacturer' => $data['manufacturer'],
                ':modelName' => $data['modelName'],
                ':ipAddress' => $data['ipAddress'],
                ':id' => $deviceId
            ]);
            
            if (!$updateResult) {
                writeLog("Failed to update device: " . $serialNumber, true);
                throw new Exception("Failed to update device");
            }
        }
        
        // Store parameters if available
        if (isset($data['parameters']) && is_array($data['parameters'])) {
            $paramSuccess = 0;
            $paramErrors = 0;
            
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
                        $updateParamResult = $updateParamStmt->execute([
                            ':value' => $param['value'],
                            ':type' => $param['type'] ?? 'string',
                            ':id' => $existingParam['id']
                        ]);
                        
                        if (!$updateParamResult) {
                            $paramErrors++;
                        } else {
                            $paramSuccess++;
                        }
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
                        $insertParamResult = $insertParamStmt->execute([
                            ':deviceId' => $deviceId,
                            ':name' => $param['name'],
                            ':value' => $param['value'],
                            ':type' => $param['type'] ?? 'string'
                        ]);
                        
                        if (!$insertParamResult) {
                            $paramErrors++;
                        } else {
                            $paramSuccess++;
                        }
                    }
                }
            }
            
            writeLog("Updated parameters for device " . $serialNumber . ": " . $paramSuccess . " successful", true);
        }
        
        // Store connected hosts if available
        if (isset($data['connectedHosts']) && is_array($data['connectedHosts'])) {
            // First, mark all existing hosts as inactive
            $markInactiveSql = "UPDATE connected_clients SET is_active = 0 WHERE device_id = :deviceId";
            $markInactiveStmt = $db->prepare($markInactiveSql);
            $markInactiveStmt->execute([':deviceId' => $deviceId]);
            
            $hostSuccess = 0;
            
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
                            is_active = :isActive,
                            last_seen = NOW()
                            WHERE id = :id";
                        
                        $updateHostStmt = $db->prepare($updateHostSql);
                        $updateHostResult = $updateHostStmt->execute([
                            ':hostname' => $host['hostname'] ?? '',
                            ':macAddress' => $host['macAddress'] ?? '',
                            ':isActive' => isset($host['isActive']) ? ($host['isActive'] ? 1 : 0) : 1,
                            ':id' => $existingHost['id']
                        ]);
                        
                        if ($updateHostResult) {
                            $hostSuccess++;
                        }
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
                            :isActive,
                            NOW()
                        )";
                        
                        $insertHostStmt = $db->prepare($insertHostSql);
                        $insertHostResult = $insertHostStmt->execute([
                            ':deviceId' => $deviceId,
                            ':ipAddress' => $host['ipAddress'],
                            ':hostname' => $host['hostname'] ?? '',
                            ':macAddress' => $host['macAddress'] ?? '',
                            ':isActive' => isset($host['isActive']) ? ($host['isActive'] ? 1 : 0) : 1
                        ]);
                        
                        if ($insertHostResult) {
                            $hostSuccess++;
                        }
                    }
                }
            }
            
            writeLog("Updated " . $hostSuccess . " connected hosts for device " . $serialNumber, true);
        }
        
        // Update the connected clients count in the devices table
        $updateClientCountSql = "UPDATE devices SET 
                                connected_clients = (
                                    SELECT COUNT(*) FROM connected_clients 
                                    WHERE device_id = :deviceId AND is_active = 1
                                )
                                WHERE id = :deviceId";
        $updateClientCountStmt = $db->prepare($updateClientCountSql);
        $updateClientCountStmt->execute([':deviceId' => $deviceId]);
        
        // Commit the transaction
        $db->commit();
        writeLog("Successfully processed TR-069 data for device " . $serialNumber, true);
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Device parameters updated successfully',
            'deviceId' => $deviceId,
            'parameters' => $paramSuccess ?? 0,
            'hosts' => $hostSuccess ?? 0
        ]);
        
    } catch (Exception $e) {
        // Roll back the transaction if something failed
        writeLog("ERROR: " . $e->getMessage(), true);
        $db->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    writeLog("DATABASE ERROR: " . $e->getMessage(), true);
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    writeLog("ERROR: " . $e->getMessage(), true);
    http_response_code(500);
    echo json_encode(['error' => 'Failed to store router data: ' . $e->getMessage()]);
}
