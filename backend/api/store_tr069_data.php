
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
    $logFile = dirname(dirname(__DIR__)) . '/database.log';
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    // Also log to PHP error log for backup
    error_log("TR069 DATA: {$message}");
}

// Log function start
logError("Starting store_tr069_data.php execution");

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
    
    // Path to the router_ssids.txt file
    $filePath = dirname(dirname(__DIR__)) . '/router_ssids.txt';
    logError("Looking for router_ssids.txt file at: {$filePath}");
    
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
    
    // If no serial number was found, generate a random one
    if (empty($serialNumber)) {
        $serialNumber = 'UNKNOWN-' . time();
        logError("No SerialNumber found, generated: {$serialNumber}");
    }
    
    // Prepare the data to be sent to the devices API
    $data = [
        'serialNumber' => $serialNumber,
        'manufacturer' => $manufacturer ?? 'Unknown',
        'modelName' => $modelName ?? 'Unknown',
        'ipAddress' => $ipAddress ?? '0.0.0.0',
        'parameters' => $parameters,
        'connectedHosts' => array_values($hosts)
    ];
    
    logError("Prepared data for API call", [
        'serialNumber' => $serialNumber,
        'manufacturer' => $manufacturer ?? 'Unknown',
        'modelName' => $modelName ?? 'Unknown',
        'ipAddress' => $ipAddress ?? '0.0.0.0',
        'parameters_count' => count($parameters),
        'hosts_count' => count($hosts)
    ]);
    
    // Log the host data specifically
    foreach ($hosts as $index => $host) {
        logError("Host {$index} details", $host);
    }
    
    // Call the devices API to store the data
    logError("Calling devices API");
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost/backend/api/devices.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Log curl errors if any
    if (curl_errno($ch)) {
        logError("Curl error: " . curl_error($ch));
    }
    
    curl_close($ch);
    
    logError("API call result (HTTP {$httpCode})", $result);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        echo json_encode([
            'success' => true,
            'message' => 'Router data stored successfully',
            'parameters' => count($parameters),
            'hosts' => count($hosts)
        ]);
        logError("SUCCESS: Router data stored successfully");
    } else {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to store router data',
            'httpCode' => $httpCode,
            'response' => $result
        ]);
        logError("ERROR: Failed to store router data. HTTP Code: {$httpCode}, Response: {$result}");
    }
    
} catch (Exception $e) {
    logError("Critical error: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
    error_log("Error storing TR-069 data: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to store router data: ' . $e->getMessage()]);
}

logError("Finished store_tr069_data.php execution");
