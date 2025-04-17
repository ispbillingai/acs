
<?php
// Helper function to write to log file
function writeToLog($message, $logFile) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "$timestamp - $message\n", FILE_APPEND);
}

// Function to make a direct TR-069 request to a device
function makeTR069Request($deviceIp, $port, $soapRequest, $username = 'admin', $password = 'admin') {
    $deviceUrl = "http://{$deviceIp}:{$port}/";
    $logFile = __DIR__ . '/../../device.log';
    
    writeToLog("TR-069 DIRECT REQUEST: Attempting connection to $deviceUrl", $logFile);
    
    try {
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
            writeToLog("TR-069 RESPONSE: Status $statusCode from $deviceUrl", $logFile);
        } else {
            $statusCode = 'connection failed';
            writeToLog("TR-069 RESPONSE: Failed to connect to $deviceUrl", $logFile);
            return false;
        }
        
        if ($result !== false) {
            // Check for SOAP faults in the response
            if (stripos($result, '<SOAP-ENV:Fault>') !== false || stripos($result, '<cwmp:Fault>') !== false) {
                preg_match('/<FaultCode>(.*?)<\/FaultCode>.*?<FaultString>(.*?)<\/FaultString>/s', $result, $faultMatches);
                if (!empty($faultMatches)) {
                    $faultCode = $faultMatches[1] ?? 'Unknown';
                    $faultString = $faultMatches[2] ?? 'Unknown error';
                    writeToLog("TR-069 FAULT: $faultCode - $faultString", $logFile);
                }
                return false;
            }
            
            // Check for successful response
            if (strpos($result, 'SetParameterValuesResponse') !== false) {
                preg_match('/<Status>(.*?)<\/Status>/s', $result, $statusMatches);
                if (!empty($statusMatches)) {
                    $opStatus = $statusMatches[1];
                    writeToLog("TR-069 SUCCESS: SetParameterValues completed with status $opStatus", $logFile);
                    return true;
                }
            }
            
            writeToLog("TR-069 RESPONSE: Received ambiguous response", $logFile);
            return false;
        } else {
            writeToLog("TR-069 ERROR: Empty response or error from device", $logFile);
            return false;
        }
    } catch (Exception $e) {
        writeToLog("TR-069 EXCEPTION: " . $e->getMessage(), $logFile);
        return false;
    }
}

// Function to attempt TR-069 requests on multiple ports
function tryMultiplePortsForTR069($deviceIp, $soapRequest, $username = 'admin', $password = 'admin') {
    $portsToTry = [7547, 30005, 37215, 4567, 8080];
    $logFile = __DIR__ . '/../../device.log';
    
    writeToLog("TR-069 MULTI-PORT ATTEMPT: Testing multiple ports on $deviceIp", $logFile);
    
    foreach ($portsToTry as $port) {
        writeToLog("TR-069 TRYING PORT: $port on $deviceIp", $logFile);
        if (makeTR069Request($deviceIp, $port, $soapRequest, $username, $password)) {
            writeToLog("TR-069 SUCCESS: Found working port $port on $deviceIp", $logFile);
            return true;
        }
    }
    
    writeToLog("TR-069 FAILURE: No working ports found on $deviceIp", $logFile);
    return false;
}

// Function to determine the correct TR-069 parameter paths for a specific device model
function getCorrectTR069ParameterPaths($manufacturer, $model) {
    $logFile = __DIR__ . '/../../device.log';
    
    writeToLog("TR-069 PARAM PATHS: Determining for $manufacturer $model", $logFile);
    
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
            writeToLog("TR-069 PARAM PATHS: Using Huawei HG8546M specific paths", $logFile);
        }
    }
    
    return $paths;
}
