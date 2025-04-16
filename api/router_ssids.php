
<?php
header('Content-Type: application/json');

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
            
            // Categorize by type
            if (strpos($name, 'SSID') !== false && strpos($name, '.SSID') !== false) {
                $result['ssids'][] = [
                    'parameter' => trim($name),
                    'value' => trim($value)
                ];
            } 
            else if (strpos($name, 'KeyPassphrase') !== false || strpos($name, 'WPAKey') !== false) {
                $result['passwords'][] = [
                    'parameter' => trim($name),
                    'value' => trim($value)
                ];
            }
        }
    }
    
    echo json_encode($result, JSON_PRETTY_PRINT);
} else {
    // Return empty result if no SSIDs found
    echo json_encode($result);
}

