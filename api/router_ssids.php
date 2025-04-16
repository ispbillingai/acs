
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Check if the SSIDs file exists
$ssidsFile = __DIR__ . '/../router_ssids.txt';

$result = [
    'timestamp' => date('Y-m-d H:i:s'),
    'ssids' => [],
    'passwords' => [],
    'raw_parameters' => []
];

if (file_exists($ssidsFile)) {
    // Read the SSIDs file
    $lines = file($ssidsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Skip comments
        if (strpos($line, '#') === 0) {
            continue;
        }
        
        // Parse parameter lines (format: paramName = paramValue)
        if (strpos($line, ' = ') !== false) {
            list($name, $value) = explode(' = ', $line, 2);
            
            // Add to the raw parameters list
            $result['raw_parameters'][] = [
                'name' => trim($name),
                'value' => trim($value)
            ];
            
            // Categorize by type with improved detection
            if (stripos($name, 'SSID') !== false) {
                $result['ssids'][] = [
                    'parameter' => trim($name),
                    'value' => trim($value),
                    'network_type' => (stripos($name, 'Configuration.5') !== false) ? '5GHz' : 
                                     ((stripos($name, 'Configuration.2') !== false) ? '5GHz' : '2.4GHz')
                ];
            } 
            else if (stripos($name, 'KeyPassphrase') !== false || 
                    stripos($name, 'WPAKey') !== false || 
                    stripos($name, 'PreSharedKey') !== false) {
                $result['passwords'][] = [
                    'parameter' => trim($name),
                    'value' => trim($value),
                    'network_type' => (stripos($name, 'Configuration.5') !== false) ? '5GHz' : 
                                     ((stripos($name, 'Configuration.2') !== false) ? '5GHz' : '2.4GHz')
                ];
            }
        }
    }
    
    echo json_encode($result, JSON_PRETTY_PRINT);
} else {
    // Return empty result if no SSIDs found
    echo json_encode($result);
}
