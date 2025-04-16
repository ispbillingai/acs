
<?php
// Disable all error logging
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 0);

// Tracking variables
$GLOBALS['knownDevices'] = []; // Store known device IPs to reduce duplicate logging
$GLOBALS['discoveredParameters'] = []; // Track parameters we've already discovered
$GLOBALS['errorCounts'] = []; // Track error counts by type
$GLOBALS['lastErrorTime'] = []; // Track last time an error was logged for throttling
$GLOBALS['verifiedParameters'] = []; // Track parameters that were successfully retrieved
$GLOBALS['hostCount'] = 0; // Track number of hosts discovered
$GLOBALS['currentHostIndex'] = 1; // Track which host index we're currently fetching

// Create a debug log file
$debugLogFile = __DIR__ . '/tr069_debug.log';
file_put_contents($debugLogFile, date('Y-m-d H:i:s') . " [DEBUG] === TR069 Debug Log Initialized ===\n", FILE_APPEND);

// Helper function to log debug information
function writeDebugLog($message) {
    global $debugLogFile;
    file_put_contents($debugLogFile, date('Y-m-d H:i:s') . " [DEBUG] " . $message . "\n", FILE_APPEND);
}

// Function to log router data without duplication
function logRouterData($paramName, $paramValue) {
    writeDebugLog("Logging parameter: {$paramName} = {$paramValue}");
    
    // Skip if we've already discovered this parameter
    if (in_array("{$paramName}={$paramValue}", $GLOBALS['discoveredParameters'])) {
        writeDebugLog("Skipping duplicate parameter: {$paramName}");
        return;
    }
    
    // Check if this is the host count parameter
    if (stripos($paramName, 'HostNumberOfEntries') !== false) {
        $GLOBALS['hostCount'] = intval($paramValue);
        writeDebugLog("Set host count to: " . $GLOBALS['hostCount']);
    }
    
    // Add to discovered parameters
    $GLOBALS['discoveredParameters'][] = "{$paramName}={$paramValue}";
    
    // Add to verified parameters list (successful retrievals)
    $GLOBALS['verifiedParameters'][] = $paramName;
    
    // Save to the dedicated router_ssids.txt file
    file_put_contents(__DIR__ . '/router_ssids.txt', "{$paramName} = {$paramValue}\n", FILE_APPEND);
    writeDebugLog("Parameter written to router_ssids.txt: {$paramName}");
}

// Set unlimited execution time for long-running sessions
set_time_limit(0);

// Basic connection information
$clientIP = $_SERVER['REMOTE_ADDR'];
$isNewSession = !isset($GLOBALS['knownDevices'][$clientIP]);
writeDebugLog("Connection from IP: {$clientIP}, isNewSession: " . ($isNewSession ? 'true' : 'false'));

// Enhanced Huawei device detection based on User-Agent
$isHuawei = false;
$isMikroTik = false;
$modelDetected = '';

if (isset($_SERVER['HTTP_USER_AGENT'])) {
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    writeDebugLog("User-Agent: {$userAgent}");
    
    // Detect MikroTik devices
    if (stripos($userAgent, 'mikrotik') !== false) {
        $isMikroTik = true;
        writeDebugLog("Detected MikroTik device");
    }
    
    // Detect Huawei devices (these are the ones we're interested in)
    if (stripos($userAgent, 'huawei') !== false || 
        stripos($userAgent, 'hw_') !== false ||
        stripos($userAgent, 'hg8') !== false) {
        $isHuawei = true;
        writeDebugLog("Detected Huawei device");
        
        // Try to detect specific model
        if (stripos($userAgent, 'hg8546') !== false) {
            $modelDetected = 'HG8546M';
            writeDebugLog("Detected Huawei model: HG8546M");
        }
    }
}

// Additional check in raw POST data for Huawei identifiers
if (!$isHuawei && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    if (!empty($raw_post)) {
        writeDebugLog("Raw POST data length: " . strlen($raw_post));
        
        if (stripos($raw_post, 'huawei') !== false || 
            stripos($raw_post, 'hg8') !== false) {
            $isHuawei = true;
            writeDebugLog("Detected Huawei device from POST data");
            
            // Check for HG8546M model in XML
            if (stripos($raw_post, 'HG8546M') !== false) {
                $modelDetected = 'HG8546M';
                writeDebugLog("Detected Huawei model from POST data: HG8546M");
            }
        }
    }
}

// Track which parameters have been attempted to avoid loops
$attemptedParameters = [];
session_start();
if (!isset($_SESSION['attempted_parameters'])) {
    $_SESSION['attempted_parameters'] = [];
    writeDebugLog("Initialized empty attempted_parameters array");
}

if (!isset($_SESSION['successful_parameters'])) {
    $_SESSION['successful_parameters'] = [];
    writeDebugLog("Initialized empty successful_parameters array");
}

if (!isset($_SESSION['host_count'])) {
    $_SESSION['host_count'] = 0;
    writeDebugLog("Initialized host_count to 0");
}

if (!isset($_SESSION['current_host_index'])) {
    $_SESSION['current_host_index'] = 1;
    writeDebugLog("Initialized current_host_index to 1");
}

// Core parameters that are likely to work across most devices
$coreParameters = [
    // Basic SSID for most routers - most likely to succeed
    ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID'],
    // Add DeviceInfo parameters that were specifically requested
    ['InternetGatewayDevice.DeviceInfo.UpTime'],
    ['InternetGatewayDevice.DeviceInfo.SoftwareVersion'],
    ['InternetGatewayDevice.DeviceInfo.HardwareVersion'],
    // Try to get Manufacturer information explicitly
    ['InternetGatewayDevice.DeviceInfo.Manufacturer']
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
    writeDebugLog("Generating host parameters for {$hostCount} hosts");
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
    writeDebugLog("Generated " . count($hostParams) . " host parameters");
    return $hostParams;
}

// Function to generate a parameter request XML
function generateParameterRequestXML($soapId, $parameters) {
    $arraySize = count($parameters);
    $parameterStrings = '';
    
    foreach ($parameters as $param) {
        $parameterStrings .= "        <string>" . htmlspecialchars($param) . "</string>\n";
    }
    
    writeDebugLog("Generating XML request for parameters: " . implode(", ", $parameters));
    
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

// Function to call API endpoint to save data
function storeDataInDatabase() {
    writeDebugLog("Attempting to store data in database...");
    if (file_exists(__DIR__ . '/router_ssids.txt')) {
        $url = 'http://' . $_SERVER['HTTP_HOST'] . '/backend/api/store_tr069_data.php';
        writeDebugLog("Calling API endpoint: {$url}");
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        writeDebugLog("API call result: HTTP {$httpCode}, Response: {$result}");
        return true;
    }
    writeDebugLog("No router_ssids.txt file found to store in database");
    return false;
}

// Skip processing for MikroTik devices entirely - they consistently fail
if ($isMikroTik) {
    writeDebugLog("Skipping processing for MikroTik device");
    // Silent exit - don't even attempt TR-069 requests for MikroTik routers
    header('Content-Length: 0');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    writeDebugLog("Processing POST request with " . strlen($raw_post) . " bytes of data");
    
    // Start with a clean router_ssids.txt file for new connections
    if (stripos($raw_post, '<cwmp:Inform>') !== false && $isHuawei) {
        writeDebugLog("Received Inform message from Huawei device");
        // Only delete the file if it's a new session
        if (file_exists(__DIR__ . '/router_ssids.txt')) {
            writeDebugLog("Resetting router_ssids.txt file");
            unlink(__DIR__ . '/router_ssids.txt');
            file_put_contents(__DIR__ . '/router_ssids.txt', "# TR-069 WiFi Parameters Discovered " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
        }
        
        // Reset attempted parameters for new sessions
        writeDebugLog("Resetting session variables for new session");
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
            writeDebugLog("Processing Inform message");
            // Extract the SOAP ID
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            writeDebugLog("Extracted SOAP ID: {$soapId}");
            
            // Extract model information
            preg_match('/<ProductClass>(.*?)<\/ProductClass>/s', $raw_post, $modelMatches);
            if (isset($modelMatches[1])) {
                $model = trim($modelMatches[1]);
                writeDebugLog("Extracted model: {$model}");
                
                // Check for HG8546M model
                if (stripos($model, 'HG8546M') !== false) {
                    $modelDetected = 'HG8546M';
                    writeDebugLog("Detected HG8546M model from Inform message");
                }
            }
            
            // Log device model if possible
            preg_match('/<ProductClass>(.*?)<\/ProductClass>.*?<SerialNumber>(.*?)<\/SerialNumber>/s', $raw_post, $deviceMatches);
            if (isset($deviceMatches[1]) && isset($deviceMatches[2])) {
                $model = $deviceMatches[1];
                $serial = $deviceMatches[2];
                writeDebugLog("Extracted product class: {$model}, serial: {$serial}");
                // Save to the router_ssids.txt file
                file_put_contents(__DIR__ . '/router_ssids.txt', "InternetGatewayDevice.DeviceInfo.ProductClass = {$model}\n", FILE_APPEND);
                file_put_contents(__DIR__ . '/router_ssids.txt', "InternetGatewayDevice.DeviceInfo.SerialNumber = {$serial}\n", FILE_APPEND);
            }
            
            // Log manufacturer if present in the Inform message
            if (preg_match('/<Manufacturer>(.*?)<\/Manufacturer>/s', $raw_post, $mfrMatches)) {
                $manufacturer = trim($mfrMatches[1]);
                writeDebugLog("Extracted manufacturer: {$manufacturer}");
                file_put_contents(__DIR__ . '/router_ssids.txt', "InternetGatewayDevice.DeviceInfo.Manufacturer = {$manufacturer}\n", FILE_APPEND);
            } else {
                writeDebugLog("No manufacturer found in Inform message");
            }
            
            // If this is an Inform request, respond with InformResponse
            require_once __DIR__ . '/backend/tr069/responses/InformResponseGenerator.php';
            $responseGenerator = new InformResponseGenerator();
            $response = $responseGenerator->createResponse($soapId);
            
            writeDebugLog("Sending InformResponse");
            header('Content-Type: text/xml');
            echo $response;
            exit;
        }
        
        // Check if this is a GetParameterValuesResponse (contains network data)
        if (stripos($raw_post, 'GetParameterValuesResponse') !== false) {
            writeDebugLog("Processing GetParameterValuesResponse");
            // Extract information using regex
            preg_match_all('/<Name>(.*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/si', $raw_post, $matches, PREG_SET_ORDER);
            
            $foundSSIDs = false;
            $foundWANSettings = false;
            $foundConnectedDevices = false;
            $foundHostNumberOfEntries = false;
            $foundDeviceInfoParams = false;
            
            if (!empty($matches)) {
                writeDebugLog("Found " . count($matches) . " parameter values in response");
                foreach ($matches as $match) {
                    $paramName = $match[1];
                    $paramValue = $match[2];
                    
                    writeDebugLog("Extracted parameter: {$paramName} = {$paramValue}");
                    
                    // Skip empty values
                    if (empty($paramValue) || $paramValue === '(null)') {
                        writeDebugLog("Skipping empty parameter value: {$paramName}");
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
                        writeDebugLog("Found host count: {$paramValue}");
                    }
                    
                    // Check for device info parameters
                    if (stripos($paramName, 'DeviceInfo.UpTime') !== false ||
                        stripos($paramName, 'DeviceInfo.SoftwareVersion') !== false ||
                        stripos($paramName, 'DeviceInfo.HardwareVersion') !== false ||
                        stripos($paramName, 'DeviceInfo.Manufacturer') !== false) {
                        $foundDeviceInfoParams = true;
                        writeDebugLog("Found device info parameter: {$paramName}");
                    }
                    
                    // Categorize the parameter by its name
                    if (stripos($paramName, 'SSID') !== false && stripos($paramName, 'WLANConfiguration.1') !== false) {
                        $foundSSIDs = true;
                        writeDebugLog("Found SSID parameter");
                    } else if (
                        stripos($paramName, 'ExternalIPAddress') !== false || 
                        stripos($paramName, 'SubnetMask') !== false || 
                        stripos($paramName, 'DefaultGateway') !== false || 
                        stripos($paramName, 'DNSServer') !== false
                    ) {
                        $foundWANSettings = true;
                        writeDebugLog("Found WAN setting parameter");
                    } else if (
                        stripos($paramName, 'Host') !== false
                    ) {
                        $foundConnectedDevices = true;
                        writeDebugLog("Found connected device parameter");
                    }
                    
                    // Log the parameter
                    logRouterData($paramName, $paramValue);
                }
            } else {
                writeDebugLog("No parameter values found in response");
            }
            
            // Extract the SOAP ID for the next request
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            writeDebugLog("Extracted SOAP ID for next request: {$soapId}");
            
            // Decide which parameter set to try next based on discovery progress
            $nextParam = null;
            
            // If we just got the host count, prioritize getting all host details
            if ($foundHostNumberOfEntries && $_SESSION['host_count'] > 0) {
                writeDebugLog("Prioritizing host details based on host count");
                // Generate all host parameters
                $dynamicHostParameters = generateHostParameters($_SESSION['host_count']);
                
                // Check which host index we're currently on
                if ($_SESSION['current_host_index'] <= $_SESSION['host_count']) {
                    $nextParam = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$_SESSION['current_host_index']}.IPAddress"];
                    $_SESSION['current_host_index']++;
                    writeDebugLog("Next parameter will be for host " . ($_SESSION['current_host_index'] - 1));
                }
            }
            
            // If we haven't found device info parameters yet, prioritize them
            if ($nextParam === null && !$foundDeviceInfoParams) {
                writeDebugLog("Prioritizing device info parameters");
                foreach ($coreParameters as $param) {
                    if (
                        (stripos($param[0], 'DeviceInfo.UpTime') !== false ||
                        stripos($param[0], 'DeviceInfo.SoftwareVersion') !== false ||
                        stripos($param[0], 'DeviceInfo.HardwareVersion') !== false ||
                        stripos($param[0], 'DeviceInfo.Manufacturer') !== false) &&
                        !in_array(implode(',', $param), $_SESSION['attempted_parameters'])
                    ) {
                        $nextParam = $param;
                        writeDebugLog("Next parameter will be device info: {$param[0]}");
                        break;
                    }
                }
            }
            
            // If no special parameters to fetch, try core parameters
            if ($nextParam === null) {
                writeDebugLog("Checking for core parameters to try");
                foreach ($coreParameters as $param) {
                    $paramKey = implode(',', $param);
                    if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                        $nextParam = $param;
                        writeDebugLog("Next parameter will be core parameter: {$param[0]}");
                        break;
                    }
                }
            }
            
            // If all core parameters have been tried, move to extended parameters
            if ($nextParam === null) {
                writeDebugLog("Checking for extended parameters to try");
                foreach ($extendedParameters as $param) {
                    $paramKey = implode(',', $param);
                    if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                        $nextParam = $param;
                        writeDebugLog("Next parameter will be extended parameter: {$param[0]}");
                        break;
                    }
                }
            }
            
            // Check if we need to try host parameters after finding host count
            if ($nextParam === null && $_SESSION['host_count'] > 0) {
                writeDebugLog("Checking for host parameters to try");
                // Generate host parameters if not already done
                if (empty($dynamicHostParameters)) {
                    $dynamicHostParameters = generateHostParameters($_SESSION['host_count']);
                }
                
                // Find next untried host parameter
                foreach ($dynamicHostParameters as $param) {
                    $paramKey = implode(',', $param);
                    if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                        $nextParam = $param;
                        writeDebugLog("Next parameter will be host parameter: {$param[0]}");
                        break;
                    }
                }
            }
            
            // If all extended parameters have been tried, try optional ones
            if ($nextParam === null) {
                writeDebugLog("Checking for optional parameters to try");
                foreach ($optionalParameters as $param) {
                    $paramKey = implode(',', $param);
                    if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                        $nextParam = $param;
                        writeDebugLog("Next parameter will be optional parameter: {$param[0]}");
                        break;
                    }
                }
            }
            
            // If we've tried everything, just complete the session
            if ($nextParam === null) {
                writeDebugLog("All parameters have been tried, completing session");
                // Store the data in the database before completing
                storeDataInDatabase();
                
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
                
                exit;
            }
            
            // Mark this parameter set as attempted
            $_SESSION['attempted_parameters'][] = implode(',', $nextParam);
            writeDebugLog("Marked parameter as attempted: " . implode(',', $nextParam));
            
            $nextRequest = generateParameterRequestXML($soapId, $nextParam);
            
            header('Content-Type: text/xml');
            echo $nextRequest;
            exit;
        }
        
        // Check for fault messages - now with smarter handling
        if (stripos($raw_post, '<SOAP-ENV:Fault>') !== false || stripos($raw_post, '<cwmp:Fault>') !== false) {
            writeDebugLog("Processing fault message");
            preg_match('/<FaultCode>(.*?)<\/FaultCode>.*?<FaultString>(.*?)<\/FaultString>/s', $raw_post, $faultMatches);
            
            if (!empty($faultMatches)) {
                $faultCode = $faultMatches[1];
                $faultString = $faultMatches[2];
                writeDebugLog("Fault detected: Code {$faultCode}, String: {$faultString}");
                
                // Extract SOAP ID
                preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
                $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
                writeDebugLog("Extracted SOAP ID: {$soapId}");
                
                // Extract what parameter caused the fault - for future reference
                if (preg_match('/<cwmp:GetParameterValues>.*?<string>(.*?)<\/string>/s', $raw_post, $paramMatch)) {
                    $faultParam = $paramMatch[1];
                    writeDebugLog("Parameter that caused fault: {$faultParam}");
                    
                    // Add to a session list of parameters that cause faults - to avoid in future
                    if (!isset($_SESSION['fault_parameters'])) {
                        $_SESSION['fault_parameters'] = [];
                    }
                    
                    $_SESSION['fault_parameters'][] = $faultParam;
                }
                
                // Check if we need to continue with host parameters
                if ($_SESSION['host_count'] > 0 && $_SESSION['current_host_index'] <= $_SESSION['host_count']) {
                    writeDebugLog("Continuing with next host parameters");
                    // Continue with the next host
                    $nextParam = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$_SESSION['current_host_index']}.IPAddress"];
                    $_SESSION['attempted_parameters'][] = implode(',', $nextParam);
                    $_SESSION['current_host_index']++;
                    
                    $nextRequest = generateParameterRequestXML($soapId, $nextParam);
                    
                    header('Content-Type: text/xml');
                    echo $nextRequest;
                    exit;
                }
                
                // Try one of the new parameters that were requested
                foreach ($coreParameters as $param) {
                    if (
                        (stripos($param[0], 'DeviceInfo.UpTime') !== false ||
                        stripos($param[0], 'DeviceInfo.SoftwareVersion') !== false ||
                        stripos($param[0], 'DeviceInfo.HardwareVersion') !== false ||
                        stripos($param[0], 'DeviceInfo.Manufacturer') !== false) &&
                        !in_array(implode(',', $param), $_SESSION['attempted_parameters'])
                    ) {
                        $nextParam = $param;
                        writeDebugLog("Trying device info parameter after fault: {$param[0]}");
                        $_SESSION['attempted_parameters'][] = implode(',', $nextParam);
                        $nextRequest = generateParameterRequestXML($soapId, $nextParam);
                        
                        header('Content-Type: text/xml');
                        echo $nextRequest;
                        exit;
                    }
                }
                
                // Now find the next parameter to try, skipping any parameters that have caused faults
                $nextParam = null;
                
                // First try all core parameters
                foreach ($coreParameters as $param) {
                    $paramKey = implode(',', $param);
                    if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                        $nextParam = $param;
                        writeDebugLog("Next parameter after fault will be core parameter: {$param[0]}");
                        break;
                    }
                }
                
                // If all core parameters have been tried, move to extended parameters
                if ($nextParam === null) {
                    writeDebugLog("Checking extended parameters after fault");
                    foreach ($extendedParameters as $param) {
                        $paramKey = implode(',', $param);
                        if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                            $nextParam = $param;
                            writeDebugLog("Next parameter after fault will be extended parameter: {$param[0]}");
                            break;
                        }
                    }
                }
                
                // If all extended parameters have been tried, try optional ones
                if ($nextParam === null) {
                    writeDebugLog("Checking optional parameters after fault");
                    foreach ($optionalParameters as $param) {
                        $paramKey = implode(',', $param);
                        if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                            $nextParam = $param;
                            writeDebugLog("Next parameter after fault will be optional parameter: {$param[0]}");
                            break;
                        }
                    }
                }
                
                // If we've tried everything, just complete the session
                if ($nextParam === null) {
                    writeDebugLog("All parameters have been tried after fault, completing session");
                    // Store the data in the database before completing
                    storeDataInDatabase();
                    
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
                    
                    exit;
                }
                
                // Mark this parameter set as attempted
                $_SESSION['attempted_parameters'][] = implode(',', $nextParam);
                
                $nextRequest = generateParameterRequestXML($soapId, $nextParam);
                
                header('Content-Type: text/xml');
                echo $nextRequest;
                exit;
            }
            
            // For other fault codes, just acknowledge and complete
            writeDebugLog("Unhandled fault, completing session");
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
            
            exit;
        }
    } else {
        writeDebugLog("Empty POST data, starting parameter discovery");
        // Empty POST - start parameter discovery
        // Generate a session ID
        $sessionId = md5(uniqid(rand(), true));
        
        // Start with a request for SSID - the most likely parameter to succeed
        $initialParameters = $coreParameters[0];
        $initialRequest = generateParameterRequestXML($sessionId, $initialParameters);
        
        header('Content-Type: text/xml');
        echo $initialRequest;
        
        exit;
    }
}

// If we haven't sent a parameter request yet, use backend TR-069 server
try {
    writeDebugLog("Using backend TR-069 server");
    require_once __DIR__ . '/backend/config/database.php';
    require_once __DIR__ . '/backend/tr069/server.php';
    $server = new TR069Server();
    
    // Pass the Huawei detection flag to the server
    $server->setHuaweiDetection($isHuawei);
    writeDebugLog("Set Huawei detection flag: " . ($isHuawei ? 'true' : 'false'));
    
    // Pass model information if detected
    if (!empty($modelDetected)) {
        $server->setModelHint($modelDetected);
        writeDebugLog("Set model hint: {$modelDetected}");
    }
    
    // Handle the request
    writeDebugLog("Handling request with TR-069 server");
    $server->handleRequest();
} catch (Exception $e) {
    writeDebugLog("ERROR: Exception in TR-069 server: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo "Internal Server Error";
}
