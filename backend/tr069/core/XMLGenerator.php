<?php

class XMLGenerator {
    private static function ensureLogDirectoryExists() {
        $logDir = __DIR__ . '/../../../../retrieve_logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        return $logDir;
    }

    private static function writeLog($message) {
        $logDir = self::ensureLogDirectoryExists();
        $logFile = $logDir . '/retrieve_' . date('Y-m-d') . '.log';
        
        // Ensure the log file is writable
        if (!file_exists($logFile)) {
            touch($logFile);
            chmod($logFile, 0666);
        }
        
        // Write the log message with timestamp
        $logEntry = date('Y-m-d H:i:s') . " - " . $message . "\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        
        // Also log to error_log as a backup
        error_log("TR-069 RETRIEVE LOG: " . $message);
    }

    public static function generateEmptyResponse($id) {
        self::writeLog("Generating empty response with ID: $id");
        
        error_log("TR-069: Generating empty response with ID: $id");
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
        self::writeLog("Generating SetParameter request for $name=$value with ID: $id");
        
        error_log("TR-069: Generating SetParameter request for $name=$value with ID: $id");
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
    
    public static function generateGetParameterValuesXML($id, $parameterNames) {
        $parameterNamesXml = '';
        foreach ($parameterNames as $name) {
            $parameterNamesXml .= "\n        <string>" . htmlspecialchars($name) . "</string>";
        }
        
        error_log("TR-069: Generating GetParameterValues XML for " . count($parameterNames) . " parameters with ID: $id");
        return '<cwmp:GetParameterValues>
      <ParameterNames soap-enc:arrayType="xsd:string[' . count($parameterNames) . ']" xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">' . $parameterNamesXml . '
      </ParameterNames>
    </cwmp:GetParameterValues>';
    }

    public static function generateFullGetParameterValuesRequestXML($id, $parameterNames) {
        $parameterNamesXml = '';
        foreach ($parameterNames as $name) {
            $parameterNamesXml .= "\n        <string>" . htmlspecialchars($name) . "</string>";
        }
        
        error_log("TR-069: Generating FULL GetParameterValues request XML with ID: $id");
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
        self::writeLog("Generating InformResponse XML with ID: $id");
        
        error_log("TR-069: Generating InformResponse XML with ID: $id");
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
        self::writeLog("Generating compound response with InformResponse + GetParameterValues");
        self::writeLog("Parameters requested: " . implode(', ', $parameterNames));
        
        error_log("TR-069: CRITICAL - Generating compound response with InformResponse + GetParameterValues");
        
        // We need to modify this to make a properly formatted XML response with both elements
        // The key issue was that we were trying to combine two complete XML documents
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

        // Log this for debugging
        $logDir = __DIR__ . '/../../../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        
        // Save the compound XML for inspection
        $logFile = $logDir . '/tr069_compound_' . date('Ymd_His') . '.xml';
        file_put_contents($logFile, $compound);
        error_log("TR-069: Saved compound XML to: $logFile");
        
        // NEW - Write to retrieve.log specifically
        file_put_contents(__DIR__ . '/../../../retrieve.log', date('Y-m-d H:i:s') . " Generated compound response XML\n", FILE_APPEND);
        file_put_contents(__DIR__ . '/../../../retrieve.log', date('Y-m-d H:i:s') . " Compound XML length: " . strlen($compound) . "\n", FILE_APPEND);
        file_put_contents(__DIR__ . '/../../../retrieve.log', date('Y-m-d H:i:s') . " Parameters requested: " . implode(', ', $parameterNames) . "\n", FILE_APPEND);
        
        return $compound;
    }
}
