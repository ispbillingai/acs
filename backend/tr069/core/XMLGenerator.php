
<?php

class XMLGenerator {
    public static function generateEmptyResponse($id) {
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
        // This is the inner part of the GetParameterValues request that will be injected into InformResponse
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

    // Generate InformResponse XML
    public static function generateInformResponseXML($id) {
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

    // NEW METHOD: Create a compound response with InformResponse + GetParameterValues
    public static function generateCompoundInformResponseWithGPV($soapId, $parameterNames) {
        // Create the InformResponse part
        $informResponse = '<?xml version="1.0" encoding="UTF-8"?>
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
    </cwmp:InformResponse>';

        // Generate the GetParameterValues part
        $gpvXml = self::generateGetParameterValuesXML($soapId, $parameterNames);
        
        // Combine them with proper closing tags
        $compound = $informResponse . "\n    " . $gpvXml . "\n  </soapenv:Body>\n</soapenv:Envelope>";
        
        error_log("TR-069: Generated compound InformResponse+GPV XML with ID: $soapId and " . count($parameterNames) . " parameters");
        
        // Log the full XML for debugging
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . '/tr069_compound_' . date('Ymd_His') . '.xml';
        file_put_contents($logFile, $compound);
        error_log("TR-069: Saved compound XML to: $logFile");
        
        return $compound;
    }
}
