<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Simplified router_ssids.php with logging removed
$ssidsFile = __DIR__ . '/../router_ssids.txt';

$result = [
    'timestamp' => date('Y-m-d H:i:s'),
    'ssids' => [],
    'passwords' => [],
    'raw_parameters' => [],
    'password_protected' => false,
    'lan_users' => [],
    'wifi_users' => [],
    'wan_settings' => [],
    'errors' => [],
    'device_info' => [], 
    'mikrotik_errors' => 0, // Counter for MikroTik errors
    'huawei_errors' => 0,   // Counter for Huawei errors
    'discovery_status' => 'unknown'
];

// Parse and count errors from the log file - but only count unique errors
$errorLogFile = __DIR__ . '/../wifi_discovery.log';
if (file_exists($errorLogFile)) {
    try {
        $logLines = file($errorLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $mikrotikErrorCount = 0;
        $huaweiErrorCount = 0;
        $detectedDeviceType = '';
        $detectedModel = '';
        $processedErrors = []; // To prevent duplicate error counting
        
        foreach ($logLines as $line) {
            // Extract device info
            if (strpos($line, 'Device Model:') !== false) {
                $modelInfo = trim(str_replace(['[INFO] Device Model:', '[INFO] Device info - Model:'], '', $line));
                $result['device_info']['model'] = $modelInfo;
            }
            
            if (strpos($line, 'Device info - Model:') !== false && strpos($line, 'Serial:') !== false) {
                preg_match('/Device info - Model: (.*?), Serial: (.*?)$/', $line, $matches);
                if (isset($matches[1]) && isset($matches[2])) {
                    $result['device_info']['model'] = trim($matches[1]);
                    $result['device_info']['serial'] = trim($matches[2]);
                }
            }
            
            // Detect device type
            if (strpos($line, 'DETECTED HUAWEI DEVICE') !== false) {
                $detectedDeviceType = 'Huawei';
                if (strpos($line, 'HG8546M') !== false) {
                    $detectedModel = 'HG8546M';
                }
            } else if (strpos($line, 'Device User-Agent: MikroTik') !== false) {
                $detectedDeviceType = 'MikroTik';
            }
            
            // Detect host count information
            if (strpos($line, 'Found') !== false && strpos($line, 'hosts to retrieve') !== false) {
                preg_match('/Found (\d+) hosts to retrieve/', $line, $matches);
                if (isset($matches[1])) {
                    $result['device_info']['host_count'] = intval($matches[1]);
                }
            }
            
            // Count errors by device type - but only unique errors
            if (strpos($line, 'FAULT CODE DETECTED') !== false) {
                if (preg_match('/FAULT CODE DETECTED: (\d+)/', $line, $matches)) {
                    $errorCode = $matches[1];
                    
                    // Skip 9005 (Invalid parameter) errors as they're expected during discovery
                    if ($errorCode == '9005') {
                        continue;
                    }
                    
                    // Create a unique key for this error
                    $errorKey = '';
                    
                    if (strpos($line, 'Device: MikroTik') !== false || 
                        (strpos($line, 'MikroTik') !== false && strpos($line, 'Method not supported') !== false)) {
                        // Only count once per error code for MikroTik
                        $errorKey = "MikroTik-{$errorCode}";
                        if (!in_array($errorKey, $processedErrors)) {
                            $mikrotikErrorCount++;
                            $processedErrors[] = $errorKey;
                        }
                    } else if (strpos($line, 'Device: Huawei') !== false || 
                           (strpos($line, 'HW_WAP_CWMP') !== false && strpos($line, 'FAULT CODE') !== false)) {
                        // Only count once per error code for Huawei
                        $errorKey = "Huawei-{$errorCode}";
                        if (!in_array($errorKey, $processedErrors)) {
                            $huaweiErrorCount++;
                            $processedErrors[] = $errorKey;
                        }
                    }
                }
            }
            
            // Check for network discovery status
            if (strpos($line, 'NETWORK DISCOVERY COMPLETED') !== false) {
                $result['discovery_status'] = 'completed';
            }
        }
        
        $result['mikrotik_errors'] = $mikrotikErrorCount;
        $result['huawei_errors'] = $huaweiErrorCount;
        
        if (!empty($detectedDeviceType)) {
            $result['device_info']['type'] = $detectedDeviceType;
        }
        
        if (!empty($detectedModel)) {
            $result['device_info']['model'] = $detectedModel;
        }
        
    } catch (Exception $e) {
        $result['errors'][] = [
            'type' => 'log_parsing_error',
            'message' => $e->getMessage()
        ];
    }
}

if (file_exists($ssidsFile)) {
    try {
        // Read the SSIDs file
        $lines = file($ssidsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        // Variables to track whether we found SSIDs but no passwords
        $foundSsids = false;
        $foundPasswords = false;
        
        // Arrays to track hosts by their index number
        $hostsByIndex = [];
        $totalHostCount = 0;
        
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
                
                // Categorize by type with improved detection for WLAN1 only
                if (stripos($name, 'SSID') !== false && stripos($name, 'WLANConfiguration.1') !== false) {
                    $foundSsids = true;
                    $result['ssids'][] = [
                        'parameter' => $name,
                        'value' => $value,
                        'network_type' => '2.4GHz'
                    ];
                } 
                else if ((stripos($name, 'KeyPassphrase') !== false || 
                        stripos($name, 'WPAKey') !== false || 
                        stripos($name, 'PreSharedKey') !== false) && 
                        stripos($name, 'WLANConfiguration.1') !== false) {
                    $foundPasswords = true;
                    $result['passwords'][] = [
                        'parameter' => $name,
                        'value' => $value,
                        'network_type' => '2.4GHz'
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
                // WiFi connected users detection
                else if (stripos($name, 'AssociatedDevice') !== false && 
                        stripos($name, 'MACAddress') !== false && 
                        stripos($name, 'WLANConfiguration.1') !== false) {
                    // This is a WiFi user
                    $mac = $value;
                    $userIndex = count($result['wifi_users']);
                    
                    $result['wifi_users'][$userIndex] = [
                        'mac' => $mac,
                        'connected_to' => '2.4GHz'
                    ];
                    
                    // Look for signal strength in the next lines
                    if (isset($lines[$key + 1]) && stripos($lines[$key + 1], 'SignalStrength') !== false) {
                        list(, $signal) = explode(' = ', $lines[$key + 1], 2);
                        $result['wifi_users'][$userIndex]['signal'] = trim($signal);
                    }
                }
                // Better host entry detection - matches Host.X.IPAddress pattern
                else if (preg_match('/InternetGatewayDevice\.LANDevice\.1\.Hosts\.Host\.(\d+)\.([a-zA-Z]+)/', $name, $matches)) {
                    $hostIndex = $matches[1];
                    $paramType = $matches[2];
                    
                    // Make sure we have an entry for this host index
                    if (!isset($hostsByIndex[$hostIndex])) {
                        $hostsByIndex[$hostIndex] = [
                            'index' => $hostIndex,
                            'ip' => '',
                            'mac' => '',
                            'hostname' => ''
                        ];
                    }
                    
                    // Fill in the appropriate field
                    if ($paramType === 'IPAddress') {
                        $hostsByIndex[$hostIndex]['ip'] = $value;
                    }
                    else if ($paramType === 'HostName') {
                        $hostsByIndex[$hostIndex]['hostname'] = $value;
                    }
                    else if ($paramType === 'PhysAddress') {
                        $hostsByIndex[$hostIndex]['mac'] = $value;
                    }
                    else if ($paramType === 'Active') {
                        $hostsByIndex[$hostIndex]['active'] = ($value === '1' || strtolower($value) === 'true');
                    }
                }
                // Host number of entries
                else if (stripos($name, 'HostNumberOfEntries') !== false) {
                    // Store the total number of hosts
                    $totalHostCount = intval($value);
                    $result['device_info']['connected_hosts'] = $totalHostCount;
                }
                else if (stripos($name, 'Fault') !== false) {
                    // Log faults as errors only if they're not 9005 (Invalid parameter name)
                    if (stripos($name, '9005') === false) {
                        $result['errors'][] = [
                            'type' => 'fault',
                            'parameter' => $name,
                            'value' => $value
                        ];
                    }
                }
            }
        }
        
        // Now process and add all the hosts we discovered to the lan_users array
        if (!empty($hostsByIndex)) {
            foreach ($hostsByIndex as $hostIndex => $hostData) {
                // Only add if we have at least IP or hostname
                if (!empty($hostData['ip']) || !empty($hostData['hostname'])) {
                    $result['lan_users'][] = $hostData;
                }
            }
        }
        
        // Update the device info with the actual number of hosts found
        if (count($result['lan_users']) > 0) {
            $result['device_info']['hosts_found'] = count($result['lan_users']);
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
    } catch (Exception $e) {
        // Log any exceptions
        $result['errors'][] = [
            'type' => 'exception',
            'message' => $e->getMessage()
        ];
        echo json_encode($result, JSON_PRETTY_PRINT);
    }
} else {
    // Return empty result if no SSIDs found
    $result['errors'][] = [
        'type' => 'file_not_found',
        'message' => 'router_ssids.txt file not found'
    ];
    echo json_encode($result);
}
