
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Check if the SSIDs file exists
$ssidsFile = __DIR__ . '/../router_ssids.txt';

$result = [
    'timestamp' => date('Y-m-d H:i:s'),
    'ssids' => [],
    'passwords' => [],
    'raw_parameters' => [],
    'password_protected' => false,  // Flag to indicate if passwords are protected/unavailable
    'lan_users' => [],
    'wifi_users' => [],
    'wan_settings' => []
];

if (file_exists($ssidsFile)) {
    // Read the SSIDs file
    $lines = file($ssidsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    // Variables to track whether we found SSIDs but no passwords
    $foundSsids = false;
    $foundPasswords = false;
    
    foreach ($lines as $key => $line) {
        // Skip comments
        if (strpos($line, '#') === 0) {
            continue;
        }
        
        // Parse parameter lines (format: paramName = paramValue)
        if (strpos($line, ' = ') !== false) {
            list($name, $value) = explode(' = ', $line, 2);
            $name = trim($name);
            $value = trim($value);
            
            // Skip duplicate SSID entries
            if (stripos($name, 'SSID') !== false) {
                $isDuplicate = false;
                foreach ($result['ssids'] as $existingSSID) {
                    if ($existingSSID['parameter'] === $name && $existingSSID['value'] === $value) {
                        $isDuplicate = true;
                        break;
                    }
                }
                if ($isDuplicate) {
                    continue;
                }
            }
            
            // Add to the raw parameters list
            $result['raw_parameters'][] = [
                'name' => $name,
                'value' => $value
            ];
            
            // Categorize by type with improved detection
            if (stripos($name, 'SSID') !== false) {
                $foundSsids = true;
                $result['ssids'][] = [
                    'parameter' => $name,
                    'value' => $value,
                    'network_type' => (stripos($name, 'Configuration.5') !== false) ? '5GHz' : 
                                     ((stripos($name, 'Configuration.2') !== false) ? '5GHz' : '2.4GHz')
                ];
            } 
            else if (stripos($name, 'KeyPassphrase') !== false || 
                    stripos($name, 'WPAKey') !== false || 
                    stripos($name, 'PreSharedKey') !== false) {
                $foundPasswords = true;
                $result['passwords'][] = [
                    'parameter' => $name,
                    'value' => $value,
                    'network_type' => (stripos($name, 'Configuration.5') !== false) ? '5GHz' : 
                                     ((stripos($name, 'Configuration.2') !== false) ? '5GHz' : '2.4GHz')
                ];
            }
            // WAN settings detection
            else if (stripos($name, 'WANIPConnection') !== false || 
                    stripos($name, 'WANPPPConnection') !== false ||
                    stripos($name, 'WANCommonInterface') !== false ||
                    stripos($name, 'ExternalIPAddress') !== false ||
                    stripos($name, 'SubnetMask') !== false ||
                    stripos($name, 'DefaultGateway') !== false ||
                    stripos($name, 'DNSServer') !== false) {
                $result['wan_settings'][] = [
                    'name' => $name,
                    'value' => $value
                ];
            }
            // LAN/WiFi connected users detection
            else if (stripos($name, 'AssociatedDevice') !== false && stripos($name, 'MACAddress') !== false) {
                // This is a WiFi user
                $mac = $value;
                $userIndex = count($result['wifi_users']);
                
                $result['wifi_users'][$userIndex] = [
                    'mac' => $mac
                ];
                
                // Look for signal strength in the next lines
                if (isset($lines[$key + 1]) && stripos($lines[$key + 1], 'SignalStrength') !== false) {
                    list(, $signal) = explode(' = ', $lines[$key + 1], 2);
                    $result['wifi_users'][$userIndex]['signal'] = trim($signal);
                }
                
                // Determine which SSID this device is connected to
                if (stripos($name, 'Configuration.1') !== false) {
                    $result['wifi_users'][$userIndex]['connected_to'] = '2.4GHz';
                } else if (stripos($name, 'Configuration.5') !== false || stripos($name, 'Configuration.2') !== false) {
                    $result['wifi_users'][$userIndex]['connected_to'] = '5GHz';
                }
            }
            else if (stripos($name, 'Host') !== false && stripos($name, 'PhysAddress') !== false) {
                // This is a LAN user
                $mac = $value;
                $userIndex = count($result['lan_users']);
                
                $result['lan_users'][$userIndex] = [
                    'mac' => $mac,
                    'ip' => '192.168.1.?' // Default placeholder
                ];
                
                // Look for IP address in surrounding lines
                foreach ($lines as $searchLine) {
                    if (stripos($searchLine, $mac) !== false && stripos($searchLine, 'IPAddress') !== false) {
                        list(, $ip) = explode(' = ', $searchLine, 2);
                        $result['lan_users'][$userIndex]['ip'] = trim($ip);
                        break;
                    }
                }
                
                // Look for hostname
                foreach ($lines as $searchLine) {
                    if (stripos($searchLine, $mac) !== false && stripos($searchLine, 'HostName') !== false) {
                        list(, $hostname) = explode(' = ', $searchLine, 2);
                        $result['lan_users'][$userIndex]['hostname'] = trim($hostname);
                        break;
                    }
                }
            }
        }
    }
    
    // If we have SSIDs but no passwords, set the password_protected flag
    if ($foundSsids && !$foundPasswords) {
        $result['password_protected'] = true;
    }
    
    // Remove duplicate entries from the raw_parameters array
    $uniqueParams = [];
    $seen = [];
    
    foreach ($result['raw_parameters'] as $param) {
        $key = $param['name'] . '|' . $param['value'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $uniqueParams[] = $param;
        }
    }
    
    $result['raw_parameters'] = $uniqueParams;
    
    echo json_encode($result, JSON_PRETTY_PRINT);
} else {
    // Return empty result if no SSIDs found
    echo json_encode($result);
}
