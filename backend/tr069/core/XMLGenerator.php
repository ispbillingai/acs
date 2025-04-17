
<?php

class XMLGenerator {
    public static function generateEmptyResponse($id) {
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
}
