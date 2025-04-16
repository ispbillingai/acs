
<?php
class InformResponseGenerator {
    public function createResponse($id = null) {
        $soapId = $id ?? '1';
        $response = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:InformResponse>
      <MaxEnvelopes>1</MaxEnvelopes>
    </cwmp:InformResponse>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
        
        file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " [INFO] Created InformResponse for session ID: " . $soapId . "\n", FILE_APPEND);
        return $response;
    }
    
    // Simplified request to get only SSID parameters for WLAN1
    public function createSSIDDiscoveryRequest($id = null) {
        $soapId = $id ?? '1';
        $response = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:GetParameterValues>
      <ParameterNames SOAP-ENC:arrayType="xsd:string[1]">
        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID</string>
      </ParameterNames>
    </cwmp:GetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

        file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " [INFO] Sent SSID discovery request\n", FILE_APPEND);
        return $response;
    }
    
    // For WLANConfiguration discovery (used as first step)
    public function createWifiDiscoveryRequest($id = null) {
        $soapId = $id ?? '1';
        $response = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:GetParameterNames>
      <ParameterPath>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.</ParameterPath>
      <NextLevel>true</NextLevel>
    </cwmp:GetParameterNames>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

        file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " [INFO] GetParameterNames request sent for WLAN discovery\n", FILE_APPEND);
        return $response;
    }
    
    // For Huawei HG8546M - specifically crafted request for this model (WLAN1 only)
    public function createHG8546MRequest($id = null) {
        $soapId = $id ?? '1';
        $response = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:GetParameterValues>
      <ParameterNames SOAP-ENC:arrayType="xsd:string[1]">
        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID</string>
      </ParameterNames>
    </cwmp:GetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

        file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " [INFO] Sent HG8546M specific request for SSIDs\n", FILE_APPEND);
        return $response;
    }
    
    // Acknowledge a parameter response
    public function createParameterResponseAcknowledgement($id = null) {
        $soapId = $id ?? '1';
        $response = '<?xml version="1.0" encoding="UTF-8"?>
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

        file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " [INFO] Sent acknowledgement for parameter response\n", FILE_APPEND);
        return $response;
    }
    
    // Custom GetParameterValues request with specific parameter list
    public function createCustomGetParameterValuesRequest($id = null, $parameterNames = []) {
        $soapId = $id ?? '1';
        
        // Default to SSID only if no parameters specified
        if (empty($parameterNames)) {
            $parameterNames = ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID'];
        }
        
        // Filter out any WLAN 2-5 parameters
        $filteredParameters = [];
        foreach ($parameterNames as $param) {
            if (!preg_match('/WLANConfiguration\.[2-5]/', $param)) {
                $filteredParameters[] = $param;
            }
        }
        
        if (empty($filteredParameters)) {
            $filteredParameters = ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID'];
        }
        
        $arraySize = count($filteredParameters);
        $parameterStrings = '';
        
        foreach ($filteredParameters as $param) {
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

        file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " [INFO] Sent custom GetParameterValues request for " . count($filteredParameters) . " parameters\n", FILE_APPEND);
        return $response;
    }
}
