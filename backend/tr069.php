<?php
// Enable error reporting with maximum verbosity
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/tr069_error.log');

// Tracking variables
$GLOBALS['knownDevices'] = []; // Store known device IPs to reduce duplicate logging
$GLOBALS['discoveredParameters'] = []; // Track parameters we've already discovered
$GLOBALS['errorCounts'] = []; // Track error counts by type
$GLOBALS['lastErrorTime'] = []; // Track last time an error was logged for throttling
$GLOBALS['verifiedParameters'] = []; // Track parameters that were successfully retrieved
$GLOBALS['hostCount'] = 0; // Track number of hosts discovered
$GLOBALS['currentHostIndex'] = 1; // Track which host index we're currently fetching

// Function to log with timestamp and priority level
function logWithTimestamp($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logFile = __DIR__ . '/wifi_discovery.log';
    file_put_contents($logFile, "[{$timestamp}] [{$level}] {$message}\n", FILE_APPEND);
    
    // Only log errors to error_log
    if ($level === 'ERROR') {
        error_log("[{$timestamp}] {$message}");
    }
}

// Function to log important router data without duplication
function logRouterData($paramName, $paramValue) {
    // Skip if we've already discovered this parameter
    if (in_array("{$paramName}={$paramValue}", $GLOBALS['discoveredParameters'])) {
        return;
    }
    
    $logFile = __DIR__ . '/wifi_discovery.log';
    $timestamp = date('Y-m-d H:i:s');
    
    // Skip duplicate entries for the same parameter
    $existingContent = file_exists(__DIR__ . '/router_ssids.txt') ? file_get_contents(__DIR__ . '/router_ssids.txt') : '';
    $parameterLine = "{$paramName} = {$paramValue}";
    if (strpos($existingContent, $parameterLine) !== false) {
        // Parameter already exists in file, skip logging it again
        return;
    }
    
    // Check if this is the host count parameter
    if (stripos($paramName, 'HostNumberOfEntries') !== false) {
        $GLOBALS['hostCount'] = intval($paramValue);
        logWithTimestamp("Found {$GLOBALS['hostCount']} hosts to retrieve", "INFO");
    }
    
    // Add to discovered parameters
    $GLOBALS['discoveredParameters'][] = "{$paramName}={$paramValue}";
    
    // Add to verified parameters list (successful retrievals)
    $GLOBALS['verifiedParameters'][] = $paramName;
    
    // Log in a formatted way that's easy to spot
    file_put_contents($logFile, "[{$timestamp}] ********************************\n", FILE_APPEND);
    file_put_contents($logFile, "[{$timestamp}] [ROUTER DATA FOUND]\n", FILE_APPEND);
    file_put_contents($logFile, "[{$timestamp}] {$paramName} = {$paramValue}\n", FILE_APPEND);
    file_put_contents($logFile, "[{$timestamp}] ********************************\n", FILE_APPEND);
    
    // Save to the dedicated router_ssids.txt file
    file_put_contents(__DIR__ . '/router_ssids.txt', "{$paramName} = {$paramValue}\n", FILE_APPEND);
}

// Function to store discovered parameters in the database
function storeParametersInDatabase() {
    if (empty($GLOBALS['discoveredParameters'])) {
        logWithTimestamp("No parameters to store in database", "WARNING");
        return false;
    }
    
    logWithTimestamp("Storing discovered parameters in database", "INFO");
    
    // Make a request to the store_tr069_data.php API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost/backend/api/store_tr069_data.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Log curl errors if any
    if (curl_errno($ch)) {
        logWithTimestamp("Curl error when calling store_tr069_data.php: " . curl_error($ch), "ERROR");
    }
    
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        logWithTimestamp("Parameters stored successfully in database: " . $result, "INFO");
        return true;
    } else {
        logWithTimestamp("Failed to store parameters in database: HTTP $httpCode - Response: $result", "ERROR");
        return false;
    }
}

// Error logging with throttling for repetitive errors - with minimal logging
function logError($errorCode, $errorMessage, $deviceType = 'Unknown') {
    // Only log certain error codes or first occurrence of others
    if ($errorCode != '9005' && $errorCode != '9002') {
        $timestamp = date('Y-m-d H:i:s');
        $errorKey = "{$deviceType}-{$errorCode}";
        $currentTime = time();
        
        // Initialize counters if not set
        if (!isset($GLOBALS['errorCounts'][$errorKey])) {
            $GLOBALS['errorCounts'][$errorKey] = 0;
            $GLOBALS['lastErrorTime'][$errorKey] = 0;
        }
        
        // Increment error count
        $GLOBALS['errorCounts'][$errorKey]++;
        
        // Only log first occurrence or every 20th occurrence
        if ($GLOBALS['errorCounts'][$errorKey] === 1 || 
            ($GLOBALS['errorCounts'][$errorKey] % 20 === 0)) {
            
            logWithTimestamp("FAULT CODE DETECTED: {$errorCode} - {$errorMessage} (Device: {$deviceType})", "ERROR");
            $GLOBALS['lastErrorTime'][$errorKey] = $currentTime;
        }
    }
}

// Set unlimited execution time for long-running sessions
set_time_limit(0);

// Basic connection information
$clientIP = $_SERVER['REMOTE_ADDR'];
$isNewSession = !isset($GLOBALS['knownDevices'][$clientIP]);

// Log new connections only once per IP
if ($isNewSession) {
    logWithTimestamp("=== NEW TR-069 REQUEST ===");
    logWithTimestamp("Client IP: " . $clientIP);
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
        logWithTimestamp("Device User-Agent: " . $_SERVER['HTTP_USER_AGENT']);
    }
    $GLOBALS['knownDevices'][$clientIP] = true;
}

// Enhanced Huawei device detection based on User-Agent
$isHuawei = false;
$isMikroTik = false;
$modelDetected = '';

if (isset($_SERVER['HTTP_USER_AGENT'])) {
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    
    // Detect MikroTik devices
    if (stripos($userAgent, 'mikrotik') !== false) {
        $isMikroTik = true;
        // Don't log every MikroTik device to reduce noise
    }
    
    // Detect Huawei devices (these are the ones we're interested in)
    if (stripos($userAgent, 'huawei') !== false || 
        stripos($userAgent, 'hw_') !== false ||
        stripos($userAgent, 'hg8') !== false) {
        $isHuawei = true;
        
        // Only log the first time we see this device, not for every request
        if ($isNewSession) {
            logWithTimestamp("DETECTED HUAWEI DEVICE: " . $userAgent);
            
            // Try to detect specific model
            if (stripos($userAgent, 'hg8546') !== false) {
                $modelDetected = 'HG8546M';
                logWithTimestamp("DETECTED HG8546M MODEL", "INFO");
            }
        }
    }
}

// Additional check in raw POST data for Huawei identifiers
if (!$isHuawei && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    if (!empty($raw_post)) {
        if (stripos($raw_post, 'huawei') !== false || 
            stripos($raw_post, 'hg8') !== false) {
            $isHuawei = true;
            
            if ($isNewSession) {
                logWithTimestamp("DETECTED HUAWEI DEVICE FROM XML CONTENT");
                
                // Check for HG8546M model in XML
                if (stripos($raw_post, 'HG8546M') !== false) {
                    $modelDetected = 'HG8546M';
                    logWithTimestamp("DETECTED HG8546M MODEL FROM XML", "INFO");
                }
            }
        }
    }
}

// Track which parameters have been attempted to avoid loops
$attemptedParameters = [];
session_start();
if (!isset($_SESSION['attempted_parameters'])) {
    $_SESSION['attempted_parameters'] = [];
}

if (!isset($_SESSION['successful_parameters'])) {
    $_SESSION['successful_parameters'] = [];
}

if (!isset($_SESSION['host_count'])) {
    $_SESSION['host_count'] = 0;
}

if (!isset($_SESSION['current_host_index'])) {
    $_SESSION['current_host_index'] = 1;
}

// Core parameters that are likely to work across most devices
$coreParameters = [
    // Basic SSID for most routers - most likely to succeed
    ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID']
];

// Extended parameters to try after core parameters succeed
$extendedParameters = [
    // WAN IP Connection details
    ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress'],
    ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.SubnetMask'],
    ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DefaultGateway'],
    ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DNSServers'],
    
    // LAN Connected Devices - basic information
    ['InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries']
];

// Dynamic parameters that will be populated based on host count
$dynamicHostParameters = [];

// Optional parameters that may fail on many devices - try these last and don't retry if they fail
$optionalParameters = [
    // WAN PPP Connection details (for PPPoE connections)
    ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress'],
    
    // WiFi Connected Devices - Only for WLAN 1
    ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDeviceNumberOfEntries'],
    ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDevice.1.MACAddress'],
    ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDevice.1.SignalStrength']
];

// Function to dynamically generate host parameters based on the host count
function generateHostParameters($hostCount) {
    $hostParams = [];
    // Iterate through each host index
    for ($i = 1; $i <= $hostCount; $i++) {
        // Add IP Address parameter
        $hostParams[] = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.IPAddress"];
        // Add HostName parameter
        $hostParams[] = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.HostName"];
        // Add MAC Address (PhysAddress) parameter
        $hostParams[] = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.PhysAddress"];
        // Add Active parameter (if it exists)
        $hostParams[] = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.Active"];
    }
    return $hostParams;
}

// Function to generate a parameter request XML
function generateParameterRequestXML($soapId, $parameters) {
    $arraySize = count($parameters);
    $parameterStrings = '';
    
    foreach ($parameters as $param) {
        $parameterStrings .= "        <string>" . htmlspecialchars($param) . "</string>\n";
    }
    
    $response = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:GetParameterValues>
      <ParameterNames SOAP-ENC:arrayType="xsd:string[' . $arraySize . ']">
' . $parameterStrings . '      </ParameterNames>
    </cwmp:GetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

    return $response;
}

// Skip processing for MikroTik devices entirely - they consistently fail
if ($isMikroTik) {
    // Silent exit - don't even attempt TR-069 requests for MikroTik routers
    header('Content-Length: 0');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    
    // Start with a clean router_ssids.txt file for new connections
    if (stripos($raw_post, '<cwmp:Inform>') !== false && $isHuawei) {
        // Only delete the file if it's a new session
        if (file_exists(__DIR__ . '/router_ssids.txt')) {
            unlink(__DIR__ . '/router_ssids.txt');
            file_put_contents(__DIR__ . '/router_ssids.txt', "# TR-069 WiFi Parameters Discovered " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
        }
        
        // Reset attempted parameters for new sessions
        if (isset($_SESSION['attempted_parameters'])) {
            $_SESSION['attempted_parameters'] = [];
        }
        
        if (isset($_SESSION['successful_parameters'])) {
            $_SESSION['successful_parameters'] = [];
        }
        
        // Reset host tracking variables
        $_SESSION['host_count'] = 0;
        $_SESSION['current_host_index'] = 1;
        $GLOBALS['hostCount'] = 0;
        $GLOBALS['currentHostIndex'] = 1;
    }
    
    if (!empty($raw_post)) {
        // Check if this is an Inform message
        if (stripos($raw_post, '<cwmp:Inform>') !== false) {
            logWithTimestamp("Processing: Inform", "INFO");
            
            // Extract the SOAP ID
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            
            // Extract model information
            preg_match('/<ProductClass>(.*?)<\/ProductClass>/s', $raw_post, $modelMatches);
            if (isset($modelMatches[1])) {
                $model = trim($modelMatches[1]);
                logWithTimestamp("Device Model: {$model}", "INFO");
                
                // Check for HG8546M model
                if (stripos($model, 'HG8546M') !== false) {
                    $modelDetected = 'HG8546M';
                    logWithTimestamp("CONFIRMED HG8546M MODEL", "INFO");
                }
            }
            
            // Log device model if possible
            preg_match('/<ProductClass>(.*?)<\/ProductClass>.*?<SerialNumber>(.*?)<\/SerialNumber>/s', $raw_post, $deviceMatches);
            if (isset($deviceMatches[1]) && isset($deviceMatches[2])) {
                $model = $deviceMatches[1];
                $serial = $deviceMatches[2];
                logWithTimestamp("Device info - Model: {$model}, Serial: {$serial}", "INFO");
            }
            
            // If this is an Inform request, respond with InformResponse
            require_once __DIR__ . '/backend/tr069/responses/InformResponseGenerator.php';
            $responseGenerator = new InformResponseGenerator();
            $response = $responseGenerator->createResponse($soapId);
            
            header('Content-Type: text/xml');
            echo $response;
            logWithTimestamp("=== REQUEST COMPLETED ===", "INFO");
            exit;
        }
        
        // Check if this is a GetParameterValuesResponse (contains network data)
        if (stripos($raw_post, 'GetParameterValuesResponse') !== false) {
            logWithTimestamp("=== DEVICE INFORMATION RESPONSE RECEIVED ===", "INFO");
            
            // Extract information using regex
            preg_match_all('/<Name>(.*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/si', $raw_post, $matches, PREG_SET_ORDER);
            
            $foundSSIDs = false;
            $foundWANSettings = false;
            $foundConnectedDevices = false;
            $foundHostNumberOfEntries = false;
            
            if (!empty($matches)) {
                foreach ($matches as $match) {
                    $paramName = $match[1];
                    $paramValue = $match[2];
                    
                    // Skip empty values
                    if (empty($paramValue) || $paramValue === '(null)') {
                        continue;
                    }
                    
                    // Track this as a successful parameter retrieval
                    if (!in_array($paramName, $_SESSION['successful_parameters'])) {
                        $_SESSION['successful_parameters'][] = $paramName;
                    }
                    
                    // Check if this is the host count parameter
                    if (stripos($paramName, 'HostNumberOfEntries') !== false) {
                        $foundHostNumberOfEntries = true;
                        $_SESSION['host_count'] = intval($paramValue);
                        $GLOBALS['hostCount'] = intval($paramValue);
                        logWithTimestamp("Found {$GLOBALS['hostCount']} hosts to retrieve", "INFO");
                    }
                    
                    // Categorize the parameter by its name
                    if (stripos($paramName, 'SSID') !== false && stripos($paramName, 'WLANConfiguration.1') !== false) {
                        $foundSSIDs = true;
                        logWithTimestamp("Found SSID: {$paramValue}", "INFO");
                    } else if (
                        stripos($paramName, 'ExternalIPAddress') !== false || 
                        stripos($paramName, 'SubnetMask') !== false || 
                        stripos($paramName, 'DefaultGateway') !== false || 
                        stripos($paramName, 'DNSServer') !== false
                    ) {
                        $foundWANSettings = true;
                        logWithTimestamp("Found WAN Setting: {$paramName} = {$paramValue}", "INFO");
                    } else if (
                        stripos($paramName, 'Host') !== false
                    ) {
                        $foundConnectedDevices = true;
                        logWithTimestamp("Found Connected Device Info: {$paramName} = {$paramValue}", "INFO");
                    }
                    
                    // Log the parameter
                    logRouterData($paramName, $paramValue);
                }
            }
            
            if (!$foundSSIDs && !$foundWANSettings && !$foundConnectedDevices) {
                logWithTimestamp("No network information found in the response", "WARNING");
            }
            
            // Extract the SOAP ID for the next request
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            
            // Decide which parameter set to try next based on discovery progress
            $nextParam = null;
            
            // If we just got the host count, prioritize getting all host details
            if ($foundHostNumberOfEntries && $_SESSION['host_count'] > 0) {
                // Generate all host parameters
                $dynamicHostParameters = generateHostParameters($_SESSION['host_count']);
                
                // Check which host index we're currently on
                if ($_SESSION['current_host_index'] <= $_SESSION['host_count']) {
                    $nextParam = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$_SESSION['current_host_index']}.IPAddress"];
                    logWithTimestamp("Fetching details for host {$_SESSION['current_host_index']} of {$_SESSION['host_count']}", "INFO");
                    $_SESSION['current_host_index']++;
                }
            }
            
            // If no host parameters to fetch, try core parameters
            if ($nextParam === null) {
                foreach ($coreParameters as $param) {
                    $paramKey = implode(',', $param);
                    if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                        $nextParam = $param;
                        break;
                    }
                }
            }
            
            // If all core parameters have been tried, move to extended parameters
            if ($nextParam === null) {
                foreach ($extendedParameters as $param) {
                    $paramKey = implode(',', $param);
                    if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                        $nextParam = $param;
                        break;
                    }
                }
            }
            
            // Check if we need to try host parameters after finding host count
            if ($nextParam === null && $_SESSION['host_count'] > 0) {
                // Generate host parameters if not already done
                if (empty($dynamicHostParameters)) {
                    $dynamicHostParameters = generateHostParameters($_SESSION['host_count']);
                }
                
                // Find next untried host parameter
                foreach ($dynamicHostParameters as $param) {
                    $paramKey = implode(',', $param);
                    if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                        $nextParam = $param;
                        break;
                    }
                }
            }
            
            // If all extended parameters have been tried, try optional ones
            if ($nextParam === null) {
                foreach ($optionalParameters as $param) {
                    $paramKey = implode(',', $param);
                    if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                        $nextParam = $param;
                        break;
                    }
                }
            }
            
            // If we've tried everything, just complete the session
            if ($nextParam === null) {
                // Store parameters in database before completing the session
                storeParametersInDatabase();
                
                header('Content-Type: text/xml');
                echo '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:SetParameterValuesResponse>
      <Status>0</Status>
    </cwmp:SetParameterValuesResponse>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
                
                logWithTimestamp("=== NETWORK DISCOVERY COMPLETED ===", "INFO");
                exit;
            }
            
            // Mark this parameter set as attempted
            $_SESSION['attempted_parameters'][] = implode(',', $nextParam);
            
            $nextRequest = generateParameterRequestXML($soapId, $nextParam);
            
            logWithTimestamp("Trying next parameter set: " . implode(", ", $nextParam), "INFO");
            
            header('Content-Type: text/xml');
            echo $nextRequest;
            exit;
        }
        
        // Check for fault messages - now with smarter handling
        if (stripos($raw_post, '<SOAP-ENV:Fault>') !== false || stripos($raw_post, '<cwmp:Fault>') !== false) {
            preg_match('/<FaultCode>(.*?)<\/FaultCode>.*?<FaultString>(.*?)<\/FaultString>/s', $raw_post, $faultMatches);
            
            if (!empty($faultMatches)) {
                $faultCode = $faultMatches[1];
                $faultString = $faultMatches[2];
                
                // Use the enhanced error logging for device-specific errors
                $deviceType = $isMikroTik ? "MikroTik" : ($isHuawei ? "Huawei" : "Unknown");
                
                // Extract SOAP ID
                preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
                $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
                
                // Extract what parameter caused the fault - for future reference
                if (preg_match('/<cwmp:GetParameterValues>.*?<string>(.*?)<\/string>/s', $raw_post, $paramMatch)) {
                    $faultParam = $paramMatch[1];
                    
                    // Add to a session list of parameters that cause faults - to avoid in future
                    if (!isset($_SESSION['fault_parameters'])) {
                        $_SESSION['fault_parameters'] = [];
                    }
                    
                    $_SESSION['fault_parameters'][] = $faultParam;
                    
                    // Only log if this is not a common "Invalid parameter" error
                    if ($faultCode != '9005') {
                        logWithTimestamp("Parameter that caused fault: {$faultParam}", "INFO");
                    }
                }
                
                // Log the error - but with minimal output for common errors
                logError($faultCode, $faultString, $deviceType);
                
                // Check if we need to continue with host parameters
                if ($_SESSION['host_count'] > 0 && $_SESSION['current_host_index'] <= $_SESSION['host_count']) {
                    // Continue with the next host
                    $nextParam = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$_SESSION['current_host_index']}.IPAddress"];
                    logWithTimestamp("Continuing with host {$_SESSION['current_host_index']} of {$_SESSION['host_count']}", "INFO");
                    $_SESSION['attempted_parameters'][] = implode(',', $nextParam);
                    $_SESSION['current_host_index']++;
                    
                    $nextRequest = generateParameterRequestXML($soapId, $nextParam);
                    
                    header('Content-Type: text/xml');
                    echo $nextRequest;
                    exit;
                }
                
                // Now find the next parameter to try, skipping any parameters that have caused faults
                $nextParam = null;
                
                // First try all core parameters
                foreach ($coreParameters as $param) {
                    $paramKey = implode(',', $param);
                    if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                        $nextParam = $param;
                        break;
                    }
                }
                
                // If all core parameters have been tried, move to extended parameters
                if ($nextParam === null) {
                    foreach ($extendedParameters as $param) {
                        $paramKey = implode(',', $param);
                        if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                            $nextParam = $param;
                            break;
                        }
                    }
                }
                
                // If all extended parameters have been tried, try optional ones
                if ($nextParam === null) {
                    foreach ($optionalParameters as $param) {
                        $paramKey = implode(',', $param);
                        if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                            $nextParam = $param;
                            break;
                        }
                    }
                }
                
                // If we've tried everything, just complete the session
                if ($nextParam === null) {
                    // Store parameters in database before completing the session
                    storeParametersInDatabase();
                    
                    header('Content-Type: text/xml');
                    echo '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:SetParameterValuesResponse>
      <Status>0</Status>
    </cwmp:SetParameterValuesResponse>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
                    
                    logWithTimestamp("=== NETWORK DISCOVERY COMPLETED (all parameters attempted) ===", "INFO");
                    exit;
                }
                
                // Mark this parameter set as attempted
                $_SESSION['attempted_parameters'][] = implode(',', $nextParam);
                
                $nextRequest = generateParameterRequestXML($soapId, $nextParam);
                
                logWithTimestamp("Fault received, trying next parameter set: " . implode(", ", $nextParam), "INFO");
                
                header('Content-Type: text/xml');
                echo $nextRequest;
                exit;
            } else {
                // Couldn't parse the fault details
                logWithTimestamp("FAULT DETECTED but couldn't parse details", "ERROR");
            }
            
            // For other fault codes, just acknowledge and complete
            header('Content-Type: text/xml');
            echo '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:SetParameterValuesResponse>
      <Status>0</Status>
    </cwmp:SetParameterValuesResponse>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
            
            logWithTimestamp("=== REQUEST COMPLETED ===", "INFO");
            exit;
        }
    } else {
        // Empty POST - start parameter discovery
        logWithTimestamp("EMPTY POST RECEIVED - This should trigger network discovery", "INFO");
        
        // Generate a session ID
        $sessionId = md5(uniqid(rand(), true));
        
        // Start with a request for SSID - the most likely parameter to succeed
        $initialParameters = $coreParameters[0];
        $initialRequest = generateParameterRequestXML($sessionId, $initialParameters);
        
        if ($isHuawei) {
            logWithTimestamp("Starting network discovery with session ID: " . $sessionId, "INFO");
            logWithTimestamp("Sending simplified SSID request for: " . implode(", ", $initialParameters), "INFO");
        }
        
        header('Content-Type: text/xml');
        echo $initialRequest;
        
        logWithTimestamp("=== REQUEST COMPLETED ===", "INFO");
        exit;
    }
}

// If we haven't sent a parameter request yet, use backend TR-069 server
try {
    require_once __DIR__ . '/backend/config/database.php';
    require_once __DIR__ . '/backend/tr069/server.php';
    $server = new TR069Server();
    
    // Pass the Huawei detection flag to the server
    $server->setHuaweiDetection($isHuawei);
    
    // Pass model information if detected
    if (!empty($modelDetected)) {
        $server->setModelHint($modelDetected);
    }
    
    // Handle the request
    $server->handleRequest();
} catch (Exception $e) {
    logWithTimestamp("ERROR: " . $e->getMessage(), "ERROR");
    header('HTTP/1.1 500 Internal Server Error');
    echo "Internal Server Error";
}

logWithTimestamp("=== REQUEST COMPLETED ===", "INFO");
