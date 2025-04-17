
<?php
class InformResponseGenerator {
    // New static property to enable detailed logging
    public static $enableDetailedLogging = false;
    
    // Helper function for logging
    private function log($message) {
        if (self::$enableDetailedLogging) {
            $logFile = __DIR__ . '/../../../device.log';
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($logFile, "$timestamp - $message\n", FILE_APPEND);
        }
    }
    
    public function createResponse($soapId = '1') {
        $this->log("TR-069 RESPONSE: Generating InformResponse with ID: $soapId");
        
        return '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:InformResponse>
      <MaxEnvelopes>1</MaxEnvelopes>
    </cwmp:InformResponse>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
    }
    
    public function createConnectionRequestTrigger($username, $password, $url) {
        $this->log("TR-069 CONNECTION REQUEST: Generating for URL: $url");
        
        // Basic authentication header
        $authHeader = 'Authorization: Basic ' . base64_encode("$username:$password");
        
        // Command to execute
        $curlCmd = "curl -v -X POST \"$url\" -H \"Content-Type: text/xml\" -H \"$authHeader\" -d '' --connect-timeout 5";
        
        return $curlCmd;
    }
    
    public function createDebugWorkflow($sessionId, $ssid, $password = null) {
        $this->log("TR-069 DEBUG: Generating debug workflow for session: $sessionId");
        
        // Create a set of debug commands
        $debugCommands = [
            'Session ID' => $sessionId,
            'SSID to set' => $ssid
        ];
        
        if (!empty($password)) {
            $debugCommands['Password to set'] = '[HIDDEN, length: ' . strlen($password) . ']';
        }
        
        return $debugCommands;
    }
    
    public function createDetailedParameterDiscovery($paramPath = 'InternetGatewayDevice.') {
        $this->log("TR-069 PARAMETER DISCOVERY: Generating for path: $paramPath");
        
        $soapId = 'disc-' . substr(md5(time()), 0, 8);
        
        $request = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:GetParameterValues>
      <ParameterNames SOAP-ENC:arrayType="xsd:string[1]">
        <string>' . htmlspecialchars($paramPath) . '</string>
      </ParameterNames>
    </cwmp:GetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

        return $request;
    }
    
    // New method to create a detailed SetParameterValues request
    public function createDetailedSetParameterValuesRequest($soapId, $parameterStructs) {
        $this->log("TR-069 SET PARAMETERS: Generating SetParameterValues request with ID: $soapId");
        
        $parameterCount = count($parameterStructs);
        $parameterXml = '';
        
        foreach ($parameterStructs as $param) {
            $paramValue = $param['name'] === 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase' ? '[HIDDEN]' : $param['value'];
            $this->log("TR-069 PARAMETER: Setting {$param['name']} = $paramValue");
            
            $parameterXml .= "        <ParameterValueStruct>\n";
            $parameterXml .= "          <Name>" . htmlspecialchars($param['name']) . "</Name>\n";
            $parameterXml .= "          <Value xsi:type=\"" . $param['type'] . "\">" . htmlspecialchars($param['value']) . "</Value>\n";
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
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
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
}
