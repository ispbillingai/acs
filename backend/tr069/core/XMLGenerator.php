
<?php

class XMLGenerator {
    private static $pendingRequestsFile = '';
    
    public static function initialize() {
        self::$pendingRequestsFile = __DIR__ . '/../../../../pending_requests.json';
        error_log("TR-069 XMLGenerator: Initialized with pending requests file: " . self::$pendingRequestsFile);
        
        // Make sure the file exists
        if (!file_exists(self::$pendingRequestsFile)) {
            file_put_contents(self::$pendingRequestsFile, json_encode([]));
            error_log("TR-069 XMLGenerator: Created pending requests file");
        }
    }
    
    private static function ensureLogDirectoryExists() {
        error_log("TR-069 XMLGenerator: Ensuring log directory exists");
        $logDir = __DIR__ . '/../../../../retrieve_logs';
        if (!is_dir($logDir)) {
            error_log("TR-069 XMLGenerator: Creating log directory: " . $logDir);
            $result = mkdir($logDir, 0777, true);
            if (!$result) {
                error_log("TR-069 XMLGenerator: ERROR - Failed to create log directory: " . $logDir);
            } else {
                error_log("TR-069 XMLGenerator: Successfully created log directory: " . $logDir);
                chmod($logDir, 0777);
            }
        } else {
            error_log("TR-069 XMLGenerator: Log directory already exists: " . $logDir);
        }
        return $logDir;
    }

    private static function writeLog($message) {
        error_log("TR-069 XMLGenerator writeLog: " . $message);
        
        try {
            $logDir = self::ensureLogDirectoryExists();
            $logFile = $logDir . '/retrieve_' . date('Y-m-d') . '.log';
            
            // Ensure the log file is writable
            if (!file_exists($logFile)) {
                error_log("TR-069 XMLGenerator: Creating log file: " . $logFile);
                $result = touch($logFile);
                if (!$result) {
                    error_log("TR-069 XMLGenerator: ERROR - Failed to create log file: " . $logFile);
                } else {
                    error_log("TR-069 XMLGenerator: Successfully created log file: " . $logFile);
                    chmod($logFile, 0666);
                }
            }
            
            // Write the log message with timestamp
            $logEntry = date('Y-m-d H:i:s') . " - " . $message . "\n";
            $result = file_put_contents($logFile, $logEntry, FILE_APPEND);
            
            if ($result === false) {
                error_log("TR-069 XMLGenerator: ERROR - Failed to write to log file: " . $logFile);
            } else {
                error_log("TR-069 XMLGenerator: Successfully wrote " . $result . " bytes to log file");
            }
        } catch (Exception $e) {
            error_log("TR-069 XMLGenerator: EXCEPTION in writeLog: " . $e->getMessage());
        }
    }

    // Store a request as pending for a specific device
    public static function storeRequestAsPending($serialNumber, $requestType, $params) {
        error_log("TR-069 XMLGenerator: Storing request as pending for device: " . $serialNumber);
        self::writeLog("Storing pending request for device: " . $serialNumber . ", type: " . $requestType);
        
        if (empty(self::$pendingRequestsFile)) {
            self::initialize();
        }
        
        try {
            // Load existing pending requests
            $pendingRequests = [];
            if (file_exists(self::$pendingRequestsFile)) {
                $content = file_get_contents(self::$pendingRequestsFile);
                if (!empty($content)) {
                    $pendingRequests = json_decode($content, true) ?: [];
                }
            }
            
            // Add this request
            $pendingRequests[$serialNumber] = [
                'type' => $requestType,
                'params' => $params,
                'created' => date('Y-m-d H:i:s'),
                'id' => uniqid()
            ];
            
            // Save back to file
            $result = file_put_contents(self::$pendingRequestsFile, json_encode($pendingRequests));
            if ($result === false) {
                error_log("TR-069 XMLGenerator: ERROR - Failed to write pending request to file");
                return false;
            } else {
                error_log("TR-069 XMLGenerator: Successfully stored pending request for " . $serialNumber);
                return true;
            }
        } catch (Exception $e) {
            error_log("TR-069 XMLGenerator: EXCEPTION in storeRequestAsPending: " . $e->getMessage());
            return false;
        }
    }
    
    // Check if there's a pending request for this device
    public static function hasPendingRequest($serialNumber) {
        error_log("TR-069 XMLGenerator: Checking for pending requests for device: " . $serialNumber);
        
        if (empty(self::$pendingRequestsFile)) {
            self::initialize();
        }
        
        try {
            if (file_exists(self::$pendingRequestsFile)) {
                $content = file_get_contents(self::$pendingRequestsFile);
                if (!empty($content)) {
                    $pendingRequests = json_decode($content, true) ?: [];
                    
                    if (isset($pendingRequests[$serialNumber])) {
                        error_log("TR-069 XMLGenerator: Found pending request for " . $serialNumber);
                        return true;
                    }
                }
            }
            
            error_log("TR-069 XMLGenerator: No pending requests found for " . $serialNumber);
            return false;
        } catch (Exception $e) {
            error_log("TR-069 XMLGenerator: EXCEPTION in hasPendingRequest: " . $e->getMessage());
            return false;
        }
    }
    
    // Get the pending request for this device and remove it from the queue
    public static function retrievePendingRequest($serialNumber) {
        error_log("TR-069 XMLGenerator: Retrieving pending request for device: " . $serialNumber);
        self::writeLog("Retrieving pending request for device: " . $serialNumber);
        
        if (empty(self::$pendingRequestsFile)) {
            self::initialize();
        }
        
        try {
            if (file_exists(self::$pendingRequestsFile)) {
                $content = file_get_contents(self::$pendingRequestsFile);
                if (!empty($content)) {
                    $pendingRequests = json_decode($content, true) ?: [];
                    
                    if (isset($pendingRequests[$serialNumber])) {
                        $request = $pendingRequests[$serialNumber];
                        
                        // Remove from pending list
                        unset($pendingRequests[$serialNumber]);
                        file_put_contents(self::$pendingRequestsFile, json_encode($pendingRequests));
                        
                        error_log("TR-069 XMLGenerator: Retrieved pending request for " . $serialNumber . ": " . $request['type']);
                        return $request;
                    }
                }
            }
            
            error_log("TR-069 XMLGenerator: No pending request found to retrieve for " . $serialNumber);
            return null;
        } catch (Exception $e) {
            error_log("TR-069 XMLGenerator: EXCEPTION in retrievePendingRequest: " . $e->getMessage());
            return null;
        }
    }

    public static function generateEmptyResponse($id) {
        error_log("TR-069 XMLGenerator: generateEmptyResponse with ID: " . $id);
        self::writeLog("Generating empty response with ID: $id");
        
        return '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $id . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
  </soapenv:Body>
</soapenv:Envelope>';
    }

    public static function generateSetParameterRequestXML($id, $name, $value, $type) {
        error_log("TR-069 XMLGenerator: generateSetParameterRequestXML: $name=$value with ID: $id");
        self::writeLog("Generating SetParameter request for $name=$value with ID: $id");
        
        return '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope 
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" 
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0" 
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" 
    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
    xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $id . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:SetParameterValues>
      <ParameterList soap-enc:arrayType="cwmp:ParameterValueStruct[1]">
        <ParameterValueStruct>
          <Name>' . htmlspecialchars($name) . '</Name>
          <Value xsi:type="' . $type . '">' . htmlspecialchars($value) . '</Value>
        </ParameterValueStruct>
      </ParameterList>
      <ParameterKey>' . uniqid() . '</ParameterKey>
    </cwmp:SetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';
    }
    
    public static function generatePendingSetParameterRequestXML($id, $parameterList) {
        error_log("TR-069 XMLGenerator: generating SetParameter request for " . count($parameterList) . " parameters with ID: $id");
        self::writeLog("Generating SetParameter request for " . count($parameterList) . " parameters with ID: $id");
        
        $paramXml = '';
        foreach ($parameterList as $param) {
            $paramXml .= "        <ParameterValueStruct>\n";
            $paramXml .= "          <Name>" . htmlspecialchars($param['name']) . "</Name>\n";
            $paramXml .= "          <Value xsi:type=\"" . $param['type'] . "\">" . htmlspecialchars($param['value']) . "</Value>\n";
            $paramXml .= "        </ParameterValueStruct>\n";
            
            error_log("TR-069 XMLGenerator: Parameter: {$param['name']} = {$param['value']}");
        }
        
        return '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope 
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" 
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0" 
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" 
    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
    xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $id . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:SetParameterValues>
      <ParameterList soap-enc:arrayType="cwmp:ParameterValueStruct[' . count($parameterList) . ']">
' . $paramXml . '      </ParameterList>
      <ParameterKey>' . uniqid() . '</ParameterKey>
    </cwmp:SetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';
    }
    
    public static function generateGetParameterValuesXML($id, $parameterNames) {
        error_log("TR-069 XMLGenerator: Generating GetParameterValuesXML for " . count($parameterNames) . " parameters with ID: $id");
        
        self::writeLog("Generating GetParameterValues for " . implode(', ', $parameterNames));
        
        $parameterNamesXml = '';
        foreach ($parameterNames as $name) {
            $parameterNamesXml .= "\n        <string>" . htmlspecialchars($name) . "</string>";
        }
        
        return '<cwmp:GetParameterValues>
      <ParameterNames soap-enc:arrayType="xsd:string[' . count($parameterNames) . ']" xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">' . $parameterNamesXml . '
      </ParameterNames>
    </cwmp:GetParameterValues>';
    }

    public static function generateFullGetParameterValuesRequestXML($id, $parameterNames) {
        error_log("TR-069 XMLGenerator: Generating FULL GetParameterValuesRequestXML for " . count($parameterNames) . " parameters with ID: $id");
        
        self::writeLog("Generating FULL GetParameterValuesRequest for " . count($parameterNames) . " parameters: " . implode(', ', $parameterNames));
        
        $parameterNamesXml = '';
        foreach ($parameterNames as $name) {
            $parameterNamesXml .= "\n        <string>" . htmlspecialchars($name) . "</string>";
        }
        
        return '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
    xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $id . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:GetParameterValues>
      <ParameterNames soap-enc:arrayType="xsd:string[' . count($parameterNames) . ']">' . $parameterNamesXml . '
      </ParameterNames>
    </cwmp:GetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';
    }

    public static function generateInformResponseXML($id) {
        error_log("TR-069 XMLGenerator: Generating InformResponseXML with ID: $id");
        self::writeLog("Generating InformResponse XML with ID: $id");
        
        return '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $id . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:InformResponse>
      <MaxEnvelopes>1</MaxEnvelopes>
    </cwmp:InformResponse>
  </soapenv:Body>
</soapenv:Envelope>';
    }

    public static function generateCompoundInformResponseWithGPV($soapId, $parameterNames) {
        error_log("TR-069 XMLGenerator: generateCompoundInformResponseWithGPV with ID: $soapId for " . count($parameterNames) . " parameters");
        self::writeLog("Generating compound response with InformResponse + GetParameterValues");
        self::writeLog("Parameters requested: " . implode(', ', $parameterNames));
        
        error_log("TR-069 XMLGenerator: Attempting to create/check retrieve.log directly");
        $retrieveLog = __DIR__ . '/../../../retrieve.log';
        if (!file_exists($retrieveLog)) {
            error_log("TR-069 XMLGenerator: Creating retrieve.log at " . $retrieveLog);
            touch($retrieveLog);
            chmod($retrieveLog, 0666);
        }
        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Starting compound response generation\n", FILE_APPEND);
        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Parameters: " . implode(', ', $parameterNames) . "\n", FILE_APPEND);
        
        $compound = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope 
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" 
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
    xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:InformResponse>
      <MaxEnvelopes>1</MaxEnvelopes>
    </cwmp:InformResponse>
  </soapenv:Body>
</soapenv:Envelope>';

        $logDir = __DIR__ . '/../../../logs';
        if (!is_dir($logDir)) {
            error_log("TR-069 XMLGenerator: Creating logs directory at " . $logDir);
            $result = mkdir($logDir, 0777, true);
            if (!$result) {
                error_log("TR-069 XMLGenerator: ERROR - Failed to create logs directory");
            } else {
                error_log("TR-069 XMLGenerator: Successfully created logs directory");
            }
        }
        
        $logFile = $logDir . '/tr069_compound_' . date('Ymd_His') . '.xml';
        file_put_contents($logFile, $compound);
        error_log("TR-069 XMLGenerator: Saved compound XML to: $logFile");
        
        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Generated compound response XML\n", FILE_APPEND);
        file_put_contents($retrieveLog, date('Y-m-d H:i:s') . " Compound XML length: " . strlen($compound) . "\n", FILE_APPEND);
        
        return $compound;
    }
    
    public static function directLogToFile($message, $filename = null) {
        error_log("TR-069 XMLGenerator: Direct logging message: " . $message);
        
        if ($filename === null) {
            $filename = __DIR__ . '/../../../direct_debug.log';
        }
        
        $logEntry = date('Y-m-d H:i:s') . " - " . $message . "\n";
        $result = file_put_contents($filename, $logEntry, FILE_APPEND);
        
        if ($result === false) {
            error_log("TR-069 XMLGenerator: ERROR - Failed direct logging to: " . $filename);
        } else {
            error_log("TR-069 XMLGenerator: Direct logged " . $result . " bytes to: " . $filename);
        }
    }
}

// Initialize the class
XMLGenerator::initialize();
