<?php
// Disable all error logging
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 0);

// Tracking variables
$GLOBALS['knownDevices'] = [];
$GLOBALS['discoveredParameters'] = [];
$GLOBALS['hostCount'] = 0;
$GLOBALS['currentHostIndex'] = 1;

// Log only important parameter set operations
function writeLog($message, $isSetParam = false) {
    if ($isSetParam) {
        $logFile = __DIR__ . '/../device.log';
        file_put_contents($logFile, date('Y-m-d H:i:s') . " [INFO] " . $message . "\n", FILE_APPEND);
    }
}

// Minimal logging for router data
function logRouterData($paramName, $paramValue) {
    // Only log parameter set operations, ignore gets and queries
    if (stripos($paramName, 'SetParameterValues') !== false) {
        writeLog("Set Parameter: {$paramName} = {$paramValue}", true);
    }
}

// Set unlimited execution time for long-running sessions
set_time_limit(0);

// Basic connection information
$clientIP = $_SERVER['REMOTE_ADDR'];
$isNewSession = !isset($GLOBALS['knownDevices'][$clientIP]);

// Enhanced Huawei device detection based on User-Agent
$isHuawei = false;
$isMikroTik = false;
$modelDetected = '';

if (isset($_SERVER['HTTP_USER_AGENT'])) {
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    
    // Detect MikroTik devices
    if (stripos($userAgent, 'mikrotik') !== false) {
        $isMikroTik = true;
    }
    
    // Detect Huawei devices
    if (stripos($userAgent, 'huawei') !== false || 
        stripos($userAgent, 'hw_') !== false ||
        stripos($userAgent, 'hg8') !== false) {
        $isHuawei = true;
        
        if (stripos($userAgent, 'hg8546') !== false) {
            $modelDetected = 'HG8546M';
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
            
            if (stripos($raw_post, 'HG8546M') !== false) {
                $modelDetected = 'HG8546M';
            }
        }
    }
}

// Track which parameters have been attempted to avoid loops
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

// Prioritize optical power readings parameters
$opticalPowerParameters = [
    ['InternetGatewayDevice.WANDevice.1.X_EponInterfaceConfig.TXPower'],
    ['InternetGatewayDevice.WANDevice.1.X_EponInterfaceConfig.RXPower'],
    ['InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.TXPower'],
    ['InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.RXPower']
];

// Core parameters that are likely to work across most devices
$coreParameters = [
    ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID'],
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
    $hostParams = [];
    for ($i = 1; $i <= $hostCount; $i++) {
        $hostParams[] = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.IPAddress"];
        $hostParams[] = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.HostName"];
        $hostParams[] = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.PhysAddress"];
        $hostParams[] = ["InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.Active"];
    }
    return $hostParams;
}

// Function to generate a parameter request XML with focus on optical parameters
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

// Function to generate a parameter SET request XML
function generateSetParameterRequestXML($soapId, $paramName, $paramValue, $paramType = "xsd:string") {
    // Log parameter set operations to device.log
    writeLog("Setting parameter: {$paramName} = {$paramValue}", true);
    
    $response = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:SetParameterValues>
      <ParameterList SOAP-ENC:arrayType="cwmp:ParameterValueStruct[1]">
        <ParameterValueStruct>
          <Name>' . htmlspecialchars($paramName) . '</Name>
          <Value xsi:type="' . $paramType . '">' . htmlspecialchars($paramValue) . '</Value>
        </ParameterValueStruct>
      </ParameterList>
      <ParameterKey></ParameterKey>
    </cwmp:SetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

    return $response;
}

// Function to generate a Commit RPC request XML
function generateCommitRequestXML($soapId) {
    $commandKey = 'commit-' . date('Ymd-His');
    
    $response = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:Commit>
      <CommandKey>' . $commandKey . '</CommandKey>
    </cwmp:Commit>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

    writeLog("Commit RPC sent (key $commandKey)", true);
    return $response;
}

// Skip processing for MikroTik devices entirely - they consistently fail
if ($isMikroTik) {
    header('Content-Length: 0');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    
    // Start with a clean router_ssids.txt file for new connections
    if (stripos($raw_post, '<cwmp:Inform>') !== false && $isHuawei) {
        if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt')) {
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
            // Extract the SOAP ID
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            
            // Extract model information
            preg_match('/<ProductClass>(.*?)<\/ProductClass>/s', $raw_post, $modelMatches);
            if (isset($modelMatches[1])) {
                $model = trim($modelMatches[1]);
                
                if (stripos($model, 'HG8546M') !== false) {
                    $modelDetected = 'HG8546M';
                }
            }
            
            // Extract manufacturer if available
            preg_match('/<Manufacturer>(.*?)<\/Manufacturer>/s', $raw_post, $mfrMatches);
            if (isset($mfrMatches[1])) {
                $manufacturer = trim($mfrMatches[1]);
                file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt', "InternetGatewayDevice.DeviceInfo.Manufacturer = {$manufacturer}\n", FILE_APPEND);
            }
            
            // Log device model if possible
            preg_match('/<ProductClass>(.*?)<\/ProductClass>.*?<SerialNumber>(.*?)<\/SerialNumber>/s', $raw_post, $deviceMatches);
            if (isset($deviceMatches[1]) && isset($deviceMatches[2])) {
                $model = $deviceMatches[1];
                $serial = $deviceMatches[2];
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
            // Extract information using regex
            preg_match_all('/<Name>(.*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>/si', $raw_post, $matches, PREG_SET_ORDER);
            
            $foundHostNumberOfEntries = false;
            $foundDeviceInfoParams = false;
            
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
                    
                    // Check if this is one of the requested parameters
                    if (stripos($paramName, 'DeviceInfo.UpTime') !== false || 
                        stripos($paramName, 'DeviceInfo.SoftwareVersion') !== false || 
                        stripos($paramName, 'DeviceInfo.HardwareVersion') !== false ||
                        stripos($paramName, 'DeviceInfo.Manufacturer') !== false) {
                        $foundDeviceInfoParams = true;
                    }
                    
                    // Check if this is the host count parameter
                    if (stripos($paramName, 'HostNumberOfEntries') !== false) {
                        $foundHostNumberOfEntries = true;
                        $_SESSION['host_count'] = intval($paramValue);
                        $GLOBALS['hostCount'] = intval($paramValue);
                    }
                    
                    // Log the parameter to the router_ssids.txt file
                    file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt', "{$paramName} = {$paramValue}\n", FILE_APPEND);
                }
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
                    $_SESSION['current_host_index']++;
                }
            }
            
            // If we haven't found the requested device info parameters, prioritize them
            if ($nextParam === null && !$foundDeviceInfoParams) {
                foreach ($coreParameters as $param) {
                    if (
                        (stripos($param[0], 'DeviceInfo.UpTime') !== false || 
                        stripos($param[0], 'DeviceInfo.SoftwareVersion') !== false || 
                        stripos($param[0], 'DeviceInfo.HardwareVersion') !== false ||
                        stripos($param[0], 'DeviceInfo.Manufacturer') !== false) && 
                        !in_array(implode(',', $param), $_SESSION['attempted_parameters'])
                    ) {
                        $nextParam = $param;
                        break;
                    }
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
                $url = 'http://' . $_SERVER['HTTP_HOST'] . '/backend/api/store_tr069_data.php';
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_exec($ch);
                curl_close($ch);
                
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
        
        // Check for SetParameterValuesResponse
        if (stripos($raw_post, 'SetParameterValuesResponse') !== false) {
            // Extract the status
            preg_match('/<Status>(.*?)<\/Status>/s', $raw_post, $statusMatches);
            if (isset($statusMatches[1])) {
                $status = trim($statusMatches[1]);
                writeLog("Parameter set operation completed with status: " . $status, true);
            }
            
            // Extract SOAP ID
            preg_match('/<cwmp:ID SOAP-ENV:mustUnderstand="1">(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
            
            // Send empty response to complete session
            header('Content-Type: text/xml');
            echo '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
            exit;
        }
        
        // Check for fault messages
        if (stripos($raw_post, '<SOAP-ENV:Fault>') !== false || stripos($raw_post, '<cwmp:Fault>') !== false) {
            preg_match('/<FaultCode>(.*?)<\/FaultCode>.*?<FaultString>(.*?)<\/FaultString>/s', $raw_post, $faultMatches);
            
            if (!empty($faultMatches)) {
                $faultCode = $faultMatches[1];
                $faultString = $faultMatches[2];
                
                // Only log faults for set operations
                if (stripos($raw_post, 'SetParameterValues') !== false) {
                    writeLog("SET Parameter Fault: Code=" . $faultCode . ", Message=" . $faultString, true);
                }
                
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
                }
                
                // Check if we need to continue with host parameters
                if ($_SESSION['host_count'] > 0 && $_SESSION['current_host_index'] <= $_SESSION['host_count']) {
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
                        break;
                    }
                }
                
                // First try all core parameters
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
                    // Store parameters in database before completing
                    $url = 'http://' . $_SERVER['HTTP_HOST'] . '/backend/api/store_tr069_data.php';
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_exec($ch);
                    curl_close($ch);
                    
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
    header('HTTP/1.1 500 Internal Server Error');
    echo "Internal Server Error";
}

// Enhance the SOAP request generation for optical power readings
if (stripos($raw_post, '<cwmp:Inform>') !== false) {
    // After regular Inform response, prioritize optical power readings
    
    // Create a custom SOAP envelope for optical power readings
    $opticalRequest = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:GetParameterValues>
      <ParameterNames SOAP-ENC:arrayType="xsd:string[4]">
        <string>InternetGatewayDevice.WANDevice.1.X_EponInterfaceConfig.TXPower</string>
        <string>InternetGatewayDevice.WANDevice.1.X_EponInterfaceConfig.RXPower</string>
        <string>InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.TXPower</string>
        <string>InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.RXPower</string>
      </ParameterNames>
    </cwmp:GetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
    
    header('Content-Type: text/xml');
    echo $opticalRequest;
    exit;
}

try {
    $infoStmt = $db->prepare("
        SELECT ip_address, software_version, hardware_version, ssid, uptime, ssid_password 
        FROM devices 
        WHERE serial_number = :serial
    ");
    $infoStmt->execute([':serial' => $serialNumber]);
    $deviceInfo = $infoStmt->fetch(PDO::FETCH_ASSOC);
    
    // Always create a task, using ssid_password as a trigger
    // This parameter will always be null/empty, forcing task creation
    $needsInfo = true;
    
    if ($needsInfo) {
        tr069_log("Device $serialNumber needs info update - queueing info task", "INFO");
        
        // Get device_id
        $idStmt = $db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
        $idStmt->execute([':serial' => $serialNumber]);
        $deviceId = $idStmt->fetchColumn();
        
        if ($deviceId) {
            // Queue an info task
            $taskData = json_encode([
                'names' => [] // Use default parameters in InfoTaskGenerator
            ]);
            
            $insertStmt = $db->prepare("
                INSERT INTO device_tasks
                    (device_id, task_type, task_data, status, created_at)
                VALUES
                    (:device_id, 'info', :task_data, 'pending', NOW())
            ");
            
            $insertStmt->execute([
                ':device_id' => $deviceId,
                ':task_data' => $taskData
            ]);
            
            tr069_log("Queued info task for device $serialNumber", "INFO");
        }
    }
} catch (PDOException $e) {
    tr069_log("Error checking/queueing info task: " . $e->getMessage(), "ERROR");
}
