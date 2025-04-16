
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Path to the router_ssids.txt file
    $filePath = __DIR__ . '/../../router_ssids.txt';
    
    if (!file_exists($filePath)) {
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
    
    // If no serial number was found, generate a random one
    if (empty($serialNumber)) {
        $serialNumber = 'UNKNOWN-' . time();
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
    
    // Call the devices API to store the data
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost/backend/api/devices.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        echo json_encode([
            'success' => true,
            'message' => 'Router data stored successfully',
            'parameters' => count($parameters),
            'hosts' => count($hosts)
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to store router data',
            'httpCode' => $httpCode,
            'response' => $result
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error storing TR-069 data: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to store router data: ' . $e->getMessage()]);
}
