
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Define log files to monitor
$logFiles = [
    'tr069_data' => __DIR__ . '/../../tr069_data.log',
    'device' => __DIR__ . '/../../device.log',
    'router_debug' => __DIR__ . '/../../router_debug.log',
    'tr069_session' => __DIR__ . '/../tr069_session.log',
    'tr069_debug' => __DIR__ . '/../tr069_debug.log'
];

// Try to locate Apache error log if possible
$possibleApacheLogLocations = [
    '/var/log/apache2/error.log',
    '/var/log/httpd/error_log',
    '/var/log/apache2/error_log',
    '/usr/local/apache/logs/error_log',
    '/usr/local/apache2/logs/error_log',
    '/opt/apache2/logs/error_log'
];

// Check if we can access any of the Apache logs
foreach ($possibleApacheLogLocations as $logPath) {
    if (file_exists($logPath) && is_readable($logPath)) {
        $logFiles['apache_error'] = $logPath;
        break;
    }
}

function getLastLines($filePath, $lines = 100) {
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return [
            'exists' => false,
            'readable' => file_exists($filePath) ? false : null,
            'lines' => []
        ];
    }
    
    try {
        $file = new SplFileObject($filePath, 'r');
        $file->seek(PHP_INT_MAX); // Seek to end of file
        $totalLines = $file->key(); // Get total line count
        
        $result = [
            'exists' => true,
            'readable' => true,
            'total_lines' => $totalLines,
            'file_size' => filesize($filePath),
            'last_modified' => date("Y-m-d H:i:s", filemtime($filePath)),
            'lines' => []
        ];
        
        // Calculate starting line
        $startLine = max(0, $totalLines - $lines);
        
        // Reset pointer to beginning of file
        $file->rewind();
        
        // Skip to starting line
        $currentLine = 0;
        while ($currentLine < $startLine && !$file->eof()) {
            $file->fgets();
            $currentLine++;
        }
        
        // Read the last specified number of lines
        while (!$file->eof() && count($result['lines']) < $lines) {
            $line = $file->fgets();
            if ($line !== false) {
                $result['lines'][] = rtrim($line);
            }
        }
        
        return $result;
    } catch (Exception $e) {
        return [
            'exists' => file_exists($filePath),
            'readable' => false,
            'error' => $e->getMessage(),
            'lines' => []
        ];
    }
}

// Main function to gather log data
function gatherLogData() {
    global $logFiles;
    $results = [];
    
    foreach ($logFiles as $name => $path) {
        $results[$name] = getLastLines($path);
    }
    
    return $results;
}

// Process the request
try {
    $lines = isset($_GET['lines']) ? intval($_GET['lines']) : 100;
    $lines = min(1000, max(10, $lines)); // Limit between 10 and 1000 lines
    
    $filter = $_GET['filter'] ?? null;
    if ($filter) {
        // Only get logs for the specified type
        if (isset($logFiles[$filter])) {
            $result = [
                'success' => true,
                'logs' => [
                    $filter => getLastLines($logFiles[$filter], $lines)
                ]
            ];
        } else {
            $result = [
                'success' => false,
                'error' => 'Invalid log filter specified'
            ];
        }
    } else {
        // Get all logs
        $result = [
            'success' => true,
            'logs' => gatherLogData()
        ];
    }
    
    // Log this API call to Apache error log for debugging
    error_log("[LOG_MONITOR] Log monitor accessed, filter: " . ($filter ?? 'all') . ", lines: $lines");
    
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("[LOG_MONITOR] Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
