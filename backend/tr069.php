
<?php
// Disable all error logging
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 0);

// Create debug log file
$debugLogFile = __DIR__ . '/../tr069_debug.log';
file_put_contents($debugLogFile, date('Y-m-d H:i:s') . " [DEBUG] === TR069 Backend Debug Log Initialized ===\n", FILE_APPEND);

function writeDebugLog($message) {
    global $debugLogFile;
    file_put_contents($debugLogFile, date('Y-m-d H:i:s') . " [DEBUG] " . $message . "\n", FILE_APPEND);
}

// Tracking variables
$GLOBALS['knownDevices'] = [];
$GLOBALS['discoveredParameters'] = [];
$GLOBALS['hostCount'] = 0;
$GLOBALS['currentHostIndex'] = 1;

// Function to log router data without logging - just store in file
function logRouterData($paramName, $paramValue) {
    writeDebugLog("Logging parameter: {$paramName} = {$paramValue}");
    
    if (in_array("{$paramName}={$paramValue}", $GLOBALS['discoveredParameters'])) {
        writeDebugLog("Skipping duplicate parameter");
        return;
    }
    
    if (stripos($paramName, 'HostNumberOfEntries') !== false) {
        $GLOBALS['hostCount'] = intval($paramValue);
        writeDebugLog("Found host count: {$paramValue}");
    }
    
    $GLOBALS['discoveredParameters'][] = "{$paramName}={$paramValue}";
    
    file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt', "{$paramName} = {$paramValue}\n", FILE_APPEND);
}

// Function to store data in database
function storeParametersInDatabase() {
    writeDebugLog("Attempting to store TR069 data in database");
    if (empty($GLOBALS['discoveredParameters'])) {
        writeDebugLog("No discovered parameters to store");
        return false;
    }
    
    // Direct API call to store data
    $scriptPath = __DIR__ . '/api/store_tr069_data.php';
    
    if (!file_exists($scriptPath)) {
        writeDebugLog("API script not found: {$scriptPath}");
        return false;
    }
    
    try {
        writeDebugLog("Including API script directly");
        include_once $scriptPath;
        return true;
    } catch (Exception $e) {
        writeDebugLog("Error including API script: " . $e->getMessage());
        // Fallback to HTTP request if direct include fails
        $ch = curl_init();
        $apiUrl = 'http://' . $_SERVER['HTTP_HOST'] . '/backend/api/store_tr069_data.php';
        
        writeDebugLog("Making HTTP request to API: {$apiUrl}");
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        writeDebugLog("API response: HTTP {$httpCode}, Response: {$result}");
        return ($result !== false);
    }
}

// Set unlimited execution time for long-running sessions
set_time_limit(0);

// Basic connection information
$clientIP = $_SERVER['REMOTE_ADDR'];
$isNewSession = !isset($GLOBALS['knownDevices'][$clientIP]);
writeDebugLog("Connection from IP: {$clientIP}, new session: " . ($isNewSession ? 'yes' : 'no'));

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
    
    // Detect Huawei devices
    if (stripos($userAgent, 'huawei') !== false || 
        stripos($userAgent, 'hw_') !== false ||
        stripos($userAgent, 'hg8') !== false) {
        $isHuawei = true;
        writeDebugLog("Detected Huawei device");
        
        if (stripos($userAgent, 'hg8546') !== false) {
            $modelDetected = 'HG8546M';
            writeDebugLog("Detected model: HG8546M");
        }
    }
}

// Additional check in raw POST data for Huawei identifiers
if (!$isHuawei && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    if (!empty($raw_post)) {
        writeDebugLog("Checking POST data for device identification");
        if (stripos($raw_post, 'huawei') !== false || 
            stripos($raw_post, 'hg8') !== false) {
            $isHuawei = true;
            writeDebugLog("Detected Huawei device from POST data");
            
            if (stripos($raw_post, 'HG8546M') !== false) {
                $modelDetected = 'HG8546M';
                writeDebugLog("Detected model from POST data: HG8546M");
            }
        }
    }
}

// Track which parameters have been attempted to avoid loops
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
    ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID'],
    // Added the requested parameters with high priority
    ['InternetGatewayDevice.DeviceInfo.UpTime'],
    ['InternetGatewayDevice.DeviceInfo.SoftwareVersion'],
    ['InternetGatewayDevice.DeviceInfo.HardwareVersion'],
    ['InternetGatewayDevice.DeviceInfo.Manufacturer']
];

// Extended parameters to try after core parameters succeed
$extendedParameters = [
    ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress'],
    ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.SubnetMask'],
    ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DefaultGateway'],
    ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.DNSServers'],
    ['InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries']
];

// Dynamic parameters that will be populated based on host count
$dynamicHostParameters = [];

// Optional parameters that may fail on many devices - try these last and don't retry if they fail
$optionalParameters = [
    ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress'],
    ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDeviceNumberOfEntries'],
    ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDevice.1.MACAddress'],
    ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDevice.1.SignalStrength']
];

// Function to dynamically generate host parameters based on the host count
function generateHostParameters($hostCount) {
    writeDebugLog("Generating parameters for {$hostCount} hosts");
    $hostParams = [];
    for ($i = 1; $i <= $hostCount; $i++) {
        $hostParams[] = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.IPAddress"];
        $hostParams[] = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.HostName"];
        $hostParams[] = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.PhysAddress"];
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

// Skip processing for MikroTik devices entirely - they consistently fail
if ($isMikroTik) {
    writeDebugLog("Skipping processing for MikroTik device");
    header('Content-Length: 0');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    
    // Start with a clean router_ssids.txt file for new connections
    if (stripos($raw_post, '<cwmp:Inform>') !== false && $isHuawei) {
        writeDebugLog("Processing Inform message from Huawei device");
        if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt')) {
            writeDebugLog("Resetting router_ssids.txt file");
            unlink($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt');
            file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt', "# TR-069 WiFi Parameters Discovered " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
        }
        
        // Reset session variables
        $_SESSION['attempted_parameters'] = [];
        $_SESSION['successful_parameters'] = [];
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
                
                if (stripos($model, 'HG8546M') !== false) {
                    $modelDetected = 'HG8546M';
                }
            }
            
            // Extract manufacturer if available
            preg_match('/<Manufacturer>(.*?)<\/Manufacturer>/s', $raw_post, $mfrMatches);
            if (isset($mfrMatches[1])) {
                $manufacturer = trim($mfrMatches[1]);
                writeDebugLog("Extracted manufacturer: {$manufacturer}");
                file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt', "InternetGatewayDevice.DeviceInfo.Manufacturer = {$manufacturer}\n", FILE_APPEND);
            } else {
                writeDebugLog("Manufacturer not found in Inform message");
            }
            
            // Log device model if possible
            preg_match('/<ProductClass>(.*?)<\/ProductClass>.*?<SerialNumber>(.*?)<\/SerialNumber>/s', $raw_post, $deviceMatches);
            if (isset($deviceMatches[1]) && isset($deviceMatches[2])) {
                $model = $deviceMatches[1];
                $serial = $deviceMatches[2];
                writeDebugLog("Extracted product class: {$model}, serial: {$serial}");
                // Save to the router_ssids.txt file
                file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt', "InternetGatewayDevice.DeviceInfo.ProductClass = {$model}\n", FILE_APPEND);
                file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt', "InternetGatewayDevice.DeviceInfo.SerialNumber = {$serial}\n", FILE_APPEND);
            }
            
            // If this is an Inform request, respond with InformResponse
            require_once __DIR__ . '/tr069/responses/InformResponseGenerator.php';
            $responseGenerator = new InformResponseGenerator();
            $response = $responseGenerator->createResponse($soapId);
            
            header('Content-Type: text/xml');
            echo $response;
            exit;
        }
        
        // Check if this is a GetParameterValuesResponse (contains network data)
        if (stripos($raw_post, 'GetParameterValuesResponse') !== false) {
            writeDebugLog("Processing GetParameterValuesResponse");
            // Extract information using regex
            preg_match_all('/<Name>(.*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/si', $raw_post, $matches, PREG_SET_ORDER);
            
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
                        writeDebugLog("Skipping empty value for {$paramName}");
                        continue;
                    }
                    
                    // Track this as a successful parameter retrieval
                    if (!in_array($paramName, $_SESSION['successful_parameters'])) {
                        $_SESSION['successful_parameters'][] = $paramName;
                    }
                    
                    // Check if this is one of the requested parameters
                    if (stripos($paramName, 'DeviceInfo.UpTime') !== false || 
                        stripos($paramName, 'DeviceInfo.SoftwareVersion') !== false || 
                        stripos($paramName, 'DeviceInfo.HardwareVersion') !== false ||
                        stripos($paramName, 'DeviceInfo.Manufacturer') !== false) {
                        $foundDeviceInfoParams = true;
                        writeDebugLog("Found requested device info parameter: {$paramName}");
                    }
                    
                    // Check if this is the host count parameter
                    if (stripos($paramName, 'HostNumberOfEntries') !== false) {
                        $foundHostNumberOfEntries = true;
                        $_SESSION['host_count'] = intval($paramValue);
                        $GLOBALS['hostCount'] = intval($paramValue);
                        writeDebugLog("Found host count: {$paramValue}");
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
                    writeDebugLog("Next parameter will be for host index: " . ($_SESSION['current_host_index'] - 1));
                }
            }
            
            // If we haven't found the requested device info parameters, prioritize them
            if ($nextParam === null && !$foundDeviceInfoParams) {
                writeDebugLog("Checking for device info parameters to try");
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
            
            // If no host parameters to fetch, try core parameters
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
        
        // Check for fault messages
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
                    writeDebugLog("Continuing with next host after fault");
                    // Continue with the next host
                    $nextParam = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$_SESSION['current_host_index']}.IPAddress"];
                    $_SESSION['attempted_parameters'][] = implode(',', $nextParam);
                    $_SESSION['current_host_index']++;
                    
                    $nextRequest = generateParameterRequestXML($soapId, $nextParam);
                    
                    header('Content-Type: text/xml');
                    echo $nextRequest;
                    exit;
                }
                
                // Find the next parameter to try
                $nextParam = null;
                
                // Try the newly requested parameters first if they haven't been attempted
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
                        break;
                    }
                }
                
                // First try all core parameters
                if ($nextParam === null) {
                    foreach ($coreParameters as $param) {
                        $paramKey = implode(',', $param);
                        if (!in_array($paramKey, $_SESSION['attempted_parameters'])) {
                            $nextParam = $param;
                            writeDebugLog("Next parameter after fault will be core parameter: {$param[0]}");
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
                            writeDebugLog("Next parameter after fault will be extended parameter: {$param[0]}");
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
                            writeDebugLog("Next parameter after fault will be optional parameter: {$param[0]}");
                            break;
                        }
                    }
                }
                
                // If we've tried everything, just complete the session
                if ($nextParam === null) {
                    writeDebugLog("All parameters have been tried after fault, completing session");
                    // Store parameters in database before completing
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
        
        // Start with requesting one of the new parameters - UpTime, SoftwareVersion, or HardwareVersion
        $initialParameters = ['InternetGatewayDevice.DeviceInfo.Manufacturer'];
        $initialRequest = generateParameterRequestXML($sessionId, $initialParameters);
        
        header('Content-Type: text/xml');
        echo $initialRequest;
        
        exit;
    }
}

// If we haven't sent a parameter request yet, use backend TR-069 server
try {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/tr069/server.php';
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
    writeDebugLog("ERROR: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo "Internal Server Error";
}
