
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

// Add error logging function
function logError($message, $data = null) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}";
    
    if ($data !== null) {
        $logMessage .= "\nData: " . print_r($data, true);
    }
    
    $logMessage .= "\n--------------------------------------------------\n";
    
    // Use an absolute path to the log file in the root directory
    $logFile = $_SERVER['DOCUMENT_ROOT'] . '/database.log';
    
    // Log the file path being used
    error_log("Writing to log file: {$logFile}");
    
    // Ensure directory exists
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    // Write to log file
    if (file_put_contents($logFile, $logMessage, FILE_APPEND) === false) {
        error_log("Failed to write to log file: {$logFile}");
    }
    
    // Also log to PHP error log for backup
    error_log("TR069 DATA: {$message}");
}

// Log function start
logError("Starting store_tr069_data.php execution");
logError("Document root: " . $_SERVER['DOCUMENT_ROOT']);

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    logError("Error: Method not allowed (expected POST, got " . $_SERVER['REQUEST_METHOD'] . ")");
    exit;
}

try {
    logError("Initializing database connection");
    $database = new Database();
    $db = $database->getConnection();
    logError("Database connection successful");
    
    // Check if we're processing a file input or direct POST data
    if (isset($_FILES['router_ssids']) && $_FILES['router_ssids']['error'] === UPLOAD_ERR_OK) {
        // Processing file upload
        $filePath = $_FILES['router_ssids']['tmp_name'];
        logError("Processing uploaded file: {$filePath}");
    } else {
        // Path to the router_ssids.txt file
        $filePath = $_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt';
        logError("Looking for router_ssids.txt file at: {$filePath}");
    }
    
    if (!file_exists($filePath)) {
        http_response_code(404);
        echo json_encode(['error' => 'Router data file not found']);
        logError("Error: Router data file not found at {$filePath}");
        exit;
    }
    
    logError("Reading file: {$filePath}");
    
    // Read the file
    $fileContents = file_get_contents($filePath);
    $lines = explode("\n", $fileContents);
    
    // Log file content summary
    logError("File read successfully. Found " . count($lines) . " lines");
    
    // Remove comment lines and empty lines
    $lines = array_filter($lines, function($line) {
        return !empty(trim($line)) && !preg_match('/^#/', trim($line));
    });
    
    logError("After filtering comments and empty lines: " . count($lines) . " lines remain");
    
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
            logError("Invalid line format: {$line}");
            continue;
        }
        
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
            logError("Found SerialNumber: {$value}");
        } else if (strpos($name, 'Manufacturer') !== false) {
            $manufacturer = $value;
            logError("Found Manufacturer: {$value}");
        } else if (strpos($name, 'ModelName') !== false || strpos($name, 'ProductClass') !== false) {
            $modelName = $value;
            logError("Found ModelName: {$value}");
        } else if (strpos($name, 'ExternalIPAddress') !== false) {
            $ipAddress = $value;
            logError("Found ExternalIPAddress: {$value}");
        } else if (strpos($name, 'HostNumberOfEntries') !== false) {
            $hostCount = intval($value);
            logError("Found HostNumberOfEntries: {$value}");
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
                logError("Initialized host entry for host index: {$hostIndex}");
            }
            
            if ($hostProperty === 'IPAddress') {
                $hosts[$hostIndex]['ipAddress'] = $value;
                logError("Host {$hostIndex} IPAddress: {$value}");
            } else if ($hostProperty === 'HostName') {
                $hosts[$hostIndex]['hostname'] = $value;
                logError("Host {$hostIndex} HostName: {$value}");
            } else if ($hostProperty === 'PhysAddress' || $hostProperty === 'MACAddress') {
                $hosts[$hostIndex]['macAddress'] = $value;
                logError("Host {$hostIndex} MACAddress: {$value}");
            } else if ($hostProperty === 'Active') {
                $hosts[$hostIndex]['isActive'] = ($value === '1' || strtolower($value) === 'true');
                logError("Host {$hostIndex} Active: {$value}");
            }
        }
    }
    
    // If no serial number was found, try to extract it from inform message
    if (empty($serialNumber)) {
        // Look for SerialNumber in various parts of the parameters collection
        foreach ($parameters as $param) {
            if (stripos($param['name'], 'SerialNumber') !== false && !empty($param['value'])) {
                $serialNumber = $param['value'];
                logError("Found SerialNumber in parameters: {$serialNumber}");
                break;
            }
        }
        
        // If still not found, generate a random one
        if (empty($serialNumber)) {
            $serialNumber = 'UNKNOWN-' . time();
            logError("No SerialNumber found, generated: {$serialNumber}");
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
    
    logError("Prepared data for API call", [
        'serialNumber' => $serialNumber,
        'manufacturer' => $manufacturer ?? 'Unknown',
        'modelName' => $modelName ?? 'Unknown',
        'ipAddress' => $ipAddress ?? $_SERVER['REMOTE_ADDR'],
        'parameters_count' => count($parameters),
        'hosts_count' => count($hosts)
    ]);
    
    // Log the host data specifically
    foreach ($hosts as $index => $host) {
        logError("Host {$index} details", $host);
    }
    
    // Direct database update instead of API call
    // Check if device exists
    $checkSql = "SELECT id FROM devices WHERE serial_number = :serialNumber";
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->execute([':serialNumber' => $serialNumber]);
    $device = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    $deviceId = null;
    
    // If device doesn't exist, create it
    if (!$device) {
        logError("Device does not exist, creating new device record");
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
            ':manufacturer' => $data['manufacturer'],
            ':modelName' => $data['modelName'],
            ':ipAddress' => $data['ipAddress']
        ]);
        
        $deviceId = $db->lastInsertId();
        logError("Created new device with ID: {$deviceId}");
    } else {
        $deviceId = $device['id'];
        logError("Found existing device with ID: {$deviceId}");
        
        // Update device basic info
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
        
        logError("Updated device basic info: " . ($updateResult ? "success" : "failed"));
    }
    
    // Store parameters if available
    if (isset($data['parameters']) && is_array($data['parameters'])) {
        $paramCount = 0;
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
                    $paramCount++;
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
                    $paramCount++;
                }
            }
        }
        logError("Stored {$paramCount} parameters");
    }
    
    // Store connected hosts if available
    if (isset($data['connectedHosts']) && is_array($data['connectedHosts'])) {
        // First, mark all existing hosts as inactive
        $markInactiveSql = "UPDATE connected_clients SET is_active = 0 WHERE device_id = :deviceId";
        $markInactiveStmt = $db->prepare($markInactiveSql);
        $markInactiveStmt->execute([':deviceId' => $deviceId]);
        logError("Marked all existing hosts as inactive");
        
        $hostCount = 0;
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
                    $hostCount++;
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
                    $hostCount++;
                }
            }
        }
        logError("Stored {$hostCount} connected hosts");
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
    logError("Updated connected clients count for device ID: {$deviceId}");
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Device parameters updated successfully',
        'deviceId' => $deviceId,
        'parameters' => count($parameters),
        'hosts' => count($hosts)
    ]);
    
    logError("SUCCESS: Router data stored successfully for device ID: {$deviceId}");
    
} catch (PDOException $e) {
    logError("Database error: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    logError("Critical error: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to store router data: ' . $e->getMessage()]);
}

logError("Finished store_tr069_data.php execution");
