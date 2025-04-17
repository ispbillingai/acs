
<?php
// Helper function to write to log file
function writeLog($message, $logFile) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "$timestamp - $message\n", FILE_APPEND);
}

// Enhanced function to make a direct TR-069 request to a device with full debugging
function makeTR069Request($deviceIp, $port, $soapRequest, $username = 'admin', $password = 'admin') {
    $deviceUrl = "http://{$deviceIp}:{$port}/";
    $logFile = __DIR__ . '/../../device.log';
    $detailedLogFile = __DIR__ . '/../../tr069_communications.log';
    
    writeLog("TR-069 DIRECT REQUEST: Attempting connection to $deviceUrl", $logFile);
    
    // Log detailed request
    writeLog("TR-069 REQUEST to $deviceUrl", $detailedLogFile);
    writeLog("--- REQUEST HEADERS ---\nContent-Type: text/xml; charset=utf-8\nAuthorization: Basic " . 
             base64_encode("$username:$password") . "\n------------------", $detailedLogFile);
    writeLog("--- REQUEST BODY ---\n$soapRequest\n------------------", $detailedLogFile);
    
    try {
        // First try cURL as it provides more detailed error information
        if (function_exists('curl_init')) {
            $ch = curl_init($deviceUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: text/xml; charset=utf-8',
                'Authorization: Basic ' . base64_encode("$username:$password")
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $soapRequest);
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            
            // Create a stream to capture curl verbose output
            $verbose = fopen('php://temp', 'w+');
            curl_setopt($ch, CURLOPT_STDERR, $verbose);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            // Get verbose information
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            fclose($verbose);
            
            // Log the detailed communication
            writeLog("TR-069 CURL VERBOSE LOG: \n$verboseLog", $detailedLogFile);
            
            if ($result !== false) {
                writeLog("TR-069 RESPONSE: Received HTTP $httpCode from $deviceUrl", $logFile);
                writeLog("--- RESPONSE BODY ---\n$result\n------------------", $detailedLogFile);
                
                // Check for SOAP faults in the response
                if (stripos($result, '<SOAP-ENV:Fault>') !== false || stripos($result, '<cwmp:Fault>') !== false) {
                    preg_match('/<FaultCode>(.*?)<\/FaultCode>.*?<FaultString>(.*?)<\/FaultString>/s', $result, $faultMatches);
                    if (!empty($faultMatches)) {
                        $faultCode = $faultMatches[1] ?? 'Unknown';
                        $faultString = $faultMatches[2] ?? 'Unknown error';
                        writeLog("TR-069 FAULT: $faultCode - $faultString", $logFile);
                        return false;
                    }
                }
                
                // Consider any response with HTTP 200-299 as success
                return ($httpCode >= 200 && $httpCode < 300);
            } else {
                writeLog("TR-069 ERROR: cURL failed - $error", $logFile);
                return false;
            }
        } else {
            // Fallback to file_get_contents if cURL is not available
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: text/xml; charset=utf-8' . 
                        "\r\nAuthorization: Basic " . base64_encode("$username:$password"),
                    'content' => $soapRequest,
                    'timeout' => 10,
                    'ignore_errors' => true,
                ]
            ]);
            
            $result = @file_get_contents($deviceUrl, false, $context);
            
            // Get response code
            if (isset($http_response_header)) {
                $statusLine = $http_response_header[0];
                preg_match('{HTTP/\S*\s(\d+)}', $statusLine, $match);
                $statusCode = $match[1] ?? 'unknown';
                
                // Log detailed HTTP response
                writeLog("TR-069 RESPONSE: Status $statusCode from $deviceUrl", $logFile);
                writeLog("--- RESPONSE HEADERS ---\n" . print_r($http_response_header, true) . "\n------------------", $detailedLogFile);
                if ($result !== false) {
                    writeLog("--- RESPONSE BODY ---\n$result\n------------------", $detailedLogFile);
                }
            } else {
                $statusCode = 'connection failed';
                writeLog("TR-069 RESPONSE: Failed to connect to $deviceUrl", $logFile);
                return false;
            }
            
            if ($result !== false) {
                // Check for SOAP faults in the response
                if (stripos($result, '<SOAP-ENV:Fault>') !== false || stripos($result, '<cwmp:Fault>') !== false) {
                    preg_match('/<FaultCode>(.*?)<\/FaultCode>.*?<FaultString>(.*?)<\/FaultString>/s', $result, $faultMatches);
                    if (!empty($faultMatches)) {
                        $faultCode = $faultMatches[1] ?? 'Unknown';
                        $faultString = $faultMatches[2] ?? 'Unknown error';
                        writeLog("TR-069 FAULT: $faultCode - $faultString", $logFile);
                        return false;
                    }
                }
                
                // Check for successful response
                if (strpos($result, 'SetParameterValuesResponse') !== false) {
                    preg_match('/<Status>(.*?)<\/Status>/s', $result, $statusMatches);
                    if (!empty($statusMatches)) {
                        $opStatus = $statusMatches[1];
                        writeLog("TR-069 SUCCESS: SetParameterValues completed with status $opStatus", $logFile);
                        return true;
                    }
                }
                
                // Consider HTTP 200-299 as success if we couldn't find more specific success indicators
                return ($statusCode >= 200 && $statusCode < 300);
            } else {
                writeLog("TR-069 ERROR: Empty response or error from device", $logFile);
                return false;
            }
        }
    } catch (Exception $e) {
        writeLog("TR-069 EXCEPTION: " . $e->getMessage(), $logFile);
        return false;
    }
}

// Function to attempt TR-069 requests on multiple ports with enhanced logging
function tryMultiplePortsForTR069($deviceIp, $soapRequest, $username = 'admin', $password = 'admin') {
    $portsToTry = [7547, 30005, 37215, 4567, 8080, 80, 443];
    $logFile = __DIR__ . '/../../device.log';
    
    writeLog("TR-069 MULTI-PORT ATTEMPT: Testing multiple ports on $deviceIp", $logFile);
    
    foreach ($portsToTry as $port) {
        writeLog("TR-069 TRYING PORT: $port on $deviceIp", $logFile);
        if (makeTR069Request($deviceIp, $port, $soapRequest, $username, $password)) {
            writeLog("TR-069 SUCCESS: Found working port $port on $deviceIp", $logFile);
            return ['success' => true, 'port' => $port];
        }
    }
    
    writeLog("TR-069 FAILURE: No working ports found on $deviceIp", $logFile);
    return ['success' => false];
}

// Function to determine the correct TR-069 parameter paths for a specific device model
function getCorrectTR069ParameterPaths($manufacturer, $model) {
    $logFile = __DIR__ . '/../../device.log';
    
    writeLog("TR-069 PARAM PATHS: Determining for $manufacturer $model", $logFile);
    
    // Default paths (TR-098 standard)
    $paths = [
        'ssid' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
        'password' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
        'security' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',
        'encryption' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.WPAEncryptionModes'
    ];
    
    // Model-specific overrides
    if (stripos($manufacturer, 'huawei') !== false) {
        if (stripos($model, 'hg8546') !== false) {
            // Huawei HG8546M specific paths
            $paths['password'] = 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase';
            writeLog("TR-069 PARAM PATHS: Using Huawei HG8546M specific paths", $logFile);
        }
    }
    
    return $paths;
}

// Helper function to encode SOAP parameters properly
function encodeSoapParameters($parameters) {
    $result = [];
    foreach ($parameters as $param) {
        $result[] = [
            'name' => $param['name'],
            'value' => htmlspecialchars($param['value'], ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            'type' => $param['type']
        ];
    }
    return $result;
}

// Function to generate TR-069 SetParameterValues SOAP request
function generateSetParameterValuesRequest($parameters, $sessionId = null) {
    if ($sessionId === null) {
        $sessionId = 'sess-' . substr(md5(time()), 0, 8);
    }
    
    $parameterCount = count($parameters);
    $parameterXml = '';
    
    foreach ($parameters as $param) {
        $parameterXml .= "        <ParameterValueStruct>\n";
        $parameterXml .= "          <Name>" . htmlspecialchars($param['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</Name>\n";
        $parameterXml .= "          <Value xsi:type=\"" . $param['type'] . "\">" . 
                          htmlspecialchars($param['value'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</Value>\n";
        $parameterXml .= "        </ParameterValueStruct>\n";
    }
    
    $request = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope
    xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
    xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $sessionId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:SetParameterValues>
      <ParameterList SOAP-ENC:arrayType="cwmp:ParameterValueStruct[' . $parameterCount . ']">
' . $parameterXml . '      </ParameterList>
      <ParameterKey>ChangeParams' . substr(md5(time()), 0, 3) . '</ParameterKey>
    </cwmp:SetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

    return $request;
}
