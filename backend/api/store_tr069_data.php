
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

// Create log file if it doesn't exist
$logFile = __DIR__ . '/../../acs.log';
if (!file_exists($logFile)) {
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [INFO] === ACS Log Initialized ===\n");
}

function writeLog($message) {
    global $logFile;
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [INFO] " . $message . "\n", FILE_APPEND);
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    writeLog("Method not allowed: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    writeLog("=== store_tr069_data.php started ===");
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        writeLog("Database connection failed");
        throw new Exception("Database connection failed");
    }
    writeLog("Database connection established");
    
    // Check database tables
    $tablesQuery = "SHOW TABLES";
    $tablesStmt = $db->prepare($tablesQuery);
    $tablesStmt->execute();
    $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
    writeLog("Database tables found: " . implode(", ", $tables));
    
    // Check devices table structure
    $columnsQuery = "DESCRIBE devices";
    $columnsStmt = $db->prepare($columnsQuery);
    $columnsStmt->execute();
    $columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
    writeLog("Devices table columns: " . implode(", ", $columns));
    
    // Check parameters table structure
    $paramColumnsQuery = "DESCRIBE parameters";
    $paramColumnsStmt = $db->prepare($paramColumnsQuery);
    $paramColumnsStmt->execute();
    $paramColumns = $paramColumnsStmt->fetchAll(PDO::FETCH_COLUMN);
    writeLog("Parameters table columns: " . implode(", ", $paramColumns));
    
    // Check connected_clients table structure
    $clientsColumnsQuery = "DESCRIBE connected_clients";
    $clientsColumnsStmt = $db->prepare($clientsColumnsQuery);
    $clientsColumnsStmt->execute();
    $clientsColumns = $clientsColumnsStmt->fetchAll(PDO::FETCH_COLUMN);
    writeLog("Connected_clients table columns: " . implode(", ", $clientsColumns));
    
    // Check if we're processing a file input or direct POST data
    if (isset($_FILES['router_ssids']) && $_FILES['router_ssids']['error'] === UPLOAD_ERR_OK) {
        // Processing file upload
        $filePath = $_FILES['router_ssids']['tmp_name'];
        writeLog("Processing uploaded file: " . $filePath);
    } else {
        // Path to the router_ssids.txt file
        $filePath = $_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt';
        writeLog("Processing router_ssids.txt file: " . $filePath);
    }
    
    if (!file_exists($filePath)) {
        writeLog("Router data file not found: " . $filePath);
        http_response_code(404);
        echo json_encode(['error' => 'Router data file not found']);
        exit;
    }
    
    // Read the file
    $fileContents = file_get_contents($filePath);
    writeLog("File content length: " . strlen($fileContents) . " bytes");
    $lines = explode("\n", $fileContents);
    writeLog("Number of lines in file: " . count($lines));
    
    // Remove comment lines and empty lines
    $lines = array_filter($lines, function($line) {
        return !empty(trim($line)) && !preg_match('/^#/', trim($line));
    });
    writeLog("Number of non-comment lines: " . count($lines));
    
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
        if (count($parts) !== 2) {
            writeLog("Invalid line format: " . $line);
            continue;
        }
        
        $name = trim($parts[0]);
        $value = trim($parts[1]);
        
        writeLog("Parsed parameter: " . $name . " = " . $value);
        
        // Store the parameter
        $parameters[] = [
            'name' => $name,
            'value' => $value,
            'type' => 'string'
        ];
        
        // Extract key information
        if (strpos($name, 'SerialNumber') !== false) {
            $serialNumber = $value;
            writeLog("Found serial number: " . $serialNumber);
        } else if (strpos($name, 'Manufacturer') !== false) {
            $manufacturer = $value;
            writeLog("Found manufacturer: " . $manufacturer);
        } else if (strpos($name, 'ModelName') !== false || strpos($name, 'ProductClass') !== false) {
            $modelName = $value;
            writeLog("Found model name: " . $modelName);
        } else if (strpos($name, 'ExternalIPAddress') !== false) {
            $ipAddress = $value;
            writeLog("Found IP address: " . $ipAddress);
        } else if (strpos($name, 'HostNumberOfEntries') !== false) {
            $hostCount = intval($value);
            writeLog("Found host count: " . $hostCount);
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
                writeLog("Initialized host entry for index: " . $hostIndex);
            }
            
            if ($hostProperty === 'IPAddress') {
                $hosts[$hostIndex]['ipAddress'] = $value;
                writeLog("Host " . $hostIndex . " IP: " . $value);
            } else if ($hostProperty === 'HostName') {
                $hosts[$hostIndex]['hostname'] = $value;
                writeLog("Host " . $hostIndex . " hostname: " . $value);
            } else if ($hostProperty === 'PhysAddress' || $hostProperty === 'MACAddress') {
                $hosts[$hostIndex]['macAddress'] = $value;
                writeLog("Host " . $hostIndex . " MAC: " . $value);
            } else if ($hostProperty === 'Active') {
                $hosts[$hostIndex]['isActive'] = ($value === '1' || strtolower($value) === 'true');
                writeLog("Host " . $hostIndex . " active: " . $value);
            }
        }
    }
    
    // If no serial number was found, try to extract it from inform message
    if (empty($serialNumber)) {
        writeLog("No serial number found in primary parameters, searching all parameters");
        // Look for SerialNumber in various parts of the parameters collection
        foreach ($parameters as $param) {
            if (stripos($param['name'], 'SerialNumber') !== false && !empty($param['value'])) {
                $serialNumber = $param['value'];
                writeLog("Found serial number in parameters: " . $serialNumber);
                break;
            }
        }
        
        // If still not found, generate a random one
        if (empty($serialNumber)) {
            $serialNumber = 'UNKNOWN-' . time();
            writeLog("Generated random serial number: " . $serialNumber);
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
    
    writeLog("Prepared data for database: " . json_encode([
        'serialNumber' => $data['serialNumber'],
        'manufacturer' => $data['manufacturer'],
        'modelName' => $data['modelName'],
        'ipAddress' => $data['ipAddress'],
        'parameterCount' => count($data['parameters']),
        'hostsCount' => count($data['connectedHosts'])
    ]));
    
    // Direct database update - critical section
    try {
        // Begin transaction for data consistency
        writeLog("Beginning database transaction");
        $db->beginTransaction();
        
        // Check if device exists
        $checkSql = "SELECT id FROM devices WHERE serial_number = :serialNumber";
        writeLog("Checking if device exists: " . $checkSql . " (serialNumber=" . $serialNumber . ")");
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([':serialNumber' => $serialNumber]);
        $device = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        $deviceId = null;
        
        // If device doesn't exist, create it
        if (!$device) {
            writeLog("Device not found, creating new device");
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
            
            writeLog("Insert SQL: " . $insertSql);
            $insertStmt = $db->prepare($insertSql);
            $insertResult = $insertStmt->execute([
                ':serialNumber' => $data['serialNumber'],
                ':manufacturer' => $data['manufacturer'],
                ':modelName' => $data['modelName'],
                ':ipAddress' => $data['ipAddress']
            ]);
            
            if (!$insertResult) {
                writeLog("Insert failed: " . print_r($insertStmt->errorInfo(), true));
                throw new Exception("Failed to insert device");
            }
            
            $deviceId = $db->lastInsertId();
            writeLog("New device created with ID: " . $deviceId);
        } else {
            $deviceId = $device['id'];
            writeLog("Device found with ID: " . $deviceId);
            
            // Update device basic info - CRITICAL UPDATE
            $updateSql = "UPDATE devices SET 
                manufacturer = :manufacturer,
                model_name = :modelName,
                ip_address = :ipAddress,
                status = 'online',
                last_contact = NOW()
                WHERE id = :id";
            
            writeLog("Update SQL: " . $updateSql);
            $updateStmt = $db->prepare($updateSql);
            $updateResult = $updateStmt->execute([
                ':manufacturer' => $data['manufacturer'],
                ':modelName' => $data['modelName'],
                ':ipAddress' => $data['ipAddress'],
                ':id' => $deviceId
            ]);
            
            if (!$updateResult) {
                writeLog("Update failed: " . print_r($updateStmt->errorInfo(), true));
                throw new Exception("Failed to update device");
            } else {
                writeLog("Device updated successfully");
            }
        }
        
        // Store parameters if available
        if (isset($data['parameters']) && is_array($data['parameters'])) {
            writeLog("Processing " . count($data['parameters']) . " parameters for device ID: " . $deviceId);
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
                            writeLog("Failed to update parameter: " . $param['name'] . " - " . print_r($updateParamStmt->errorInfo(), true));
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
                            writeLog("Failed to insert parameter: " . $param['name'] . " - " . print_r($insertParamStmt->errorInfo(), true));
                            $paramErrors++;
                        } else {
                            $paramSuccess++;
                            writeLog("Parameter inserted: " . $param['name'] . " = " . $param['value']);
                        }
                    }
                }
            }
            
            writeLog("Parameters processed - Success: " . $paramSuccess . ", Errors: " . $paramErrors);
        }
        
        // Store connected hosts if available
        if (isset($data['connectedHosts']) && is_array($data['connectedHosts'])) {
            writeLog("Processing " . count($data['connectedHosts']) . " connected hosts for device ID: " . $deviceId);
            
            // First, mark all existing hosts as inactive
            $markInactiveSql = "UPDATE connected_clients SET is_active = 0 WHERE device_id = :deviceId";
            $markInactiveStmt = $db->prepare($markInactiveSql);
            $markInactiveResult = $markInactiveStmt->execute([':deviceId' => $deviceId]);
            
            if (!$markInactiveResult) {
                writeLog("Failed to mark hosts as inactive: " . print_r($markInactiveStmt->errorInfo(), true));
            } else {
                writeLog("Marked all existing hosts as inactive");
            }
            
            $hostSuccess = 0;
            $hostErrors = 0;
            
            foreach ($data['connectedHosts'] as $host) {
                if (isset($host['ipAddress'])) {
                    writeLog("Processing host with IP: " . $host['ipAddress']);
                    
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
                        $updateHostResult = $updateHostStmt->execute([
                            ':hostname' => $host['hostname'] ?? '',
                            ':macAddress' => $host['macAddress'] ?? '',
                            ':id' => $existingHost['id']
                        ]);
                        
                        if (!$updateHostResult) {
                            writeLog("Failed to update host: " . $host['ipAddress'] . " - " . print_r($updateHostStmt->errorInfo(), true));
                            $hostErrors++;
                        } else {
                            $hostSuccess++;
                            writeLog("Host updated: " . $host['ipAddress']);
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
                            1,
                            NOW()
                        )";
                        
                        $insertHostStmt = $db->prepare($insertHostSql);
                        $insertHostResult = $insertHostStmt->execute([
                            ':deviceId' => $deviceId,
                            ':ipAddress' => $host['ipAddress'],
                            ':hostname' => $host['hostname'] ?? '',
                            ':macAddress' => $host['macAddress'] ?? ''
                        ]);
                        
                        if (!$insertHostResult) {
                            writeLog("Failed to insert host: " . $host['ipAddress'] . " - " . print_r($insertHostStmt->errorInfo(), true));
                            $hostErrors++;
                        } else {
                            $hostSuccess++;
                            writeLog("Host inserted: " . $host['ipAddress']);
                        }
                    }
                }
            }
            
            writeLog("Hosts processed - Success: " . $hostSuccess . ", Errors: " . $hostErrors);
        }
        
        // Update the connected clients count in the devices table
        $updateClientCountSql = "UPDATE devices SET 
                                connected_clients = (
                                    SELECT COUNT(*) FROM connected_clients 
                                    WHERE device_id = :deviceId AND is_active = 1
                                )
                                WHERE id = :deviceId";
        $updateClientCountStmt = $db->prepare($updateClientCountSql);
        $updateClientCountResult = $updateClientCountStmt->execute([':deviceId' => $deviceId]);
        
        if (!$updateClientCountResult) {
            writeLog("Failed to update client count: " . print_r($updateClientCountStmt->errorInfo(), true));
        } else {
            writeLog("Updated connected client count for device");
        }
        
        // Verify that the data was stored correctly
        $verifyDeviceSql = "SELECT * FROM devices WHERE id = :deviceId";
        $verifyDeviceStmt = $db->prepare($verifyDeviceSql);
        $verifyDeviceStmt->execute([':deviceId' => $deviceId]);
        $verifiedDevice = $verifyDeviceStmt->fetch(PDO::FETCH_ASSOC);
        writeLog("Verification - Device data in database: " . print_r($verifiedDevice, true));
        
        $verifyParamsSql = "SELECT COUNT(*) FROM parameters WHERE device_id = :deviceId";
        $verifyParamsStmt = $db->prepare($verifyParamsSql);
        $verifyParamsStmt->execute([':deviceId' => $deviceId]);
        $paramCount = $verifyParamsStmt->fetchColumn();
        writeLog("Verification - Parameter count in database: " . $paramCount);
        
        $verifyHostsSql = "SELECT COUNT(*) FROM connected_clients WHERE device_id = :deviceId AND is_active = 1";
        $verifyHostsStmt = $db->prepare($verifyHostsSql);
        $verifyHostsStmt->execute([':deviceId' => $deviceId]);
        $hostCount = $verifyHostsStmt->fetchColumn();
        writeLog("Verification - Active host count in database: " . $hostCount);
        
        // Commit the transaction
        writeLog("Committing database transaction");
        $db->commit();
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Device parameters updated successfully',
            'deviceId' => $deviceId
        ]);
        
        writeLog("=== store_tr069_data.php completed successfully ===");
    } catch (Exception $e) {
        // Roll back the transaction if something failed
        writeLog("ERROR: Rolling back transaction due to error: " . $e->getMessage());
        $db->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    writeLog("CRITICAL PDO ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    writeLog("CRITICAL ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to store router data: ' . $e->getMessage()]);
}
