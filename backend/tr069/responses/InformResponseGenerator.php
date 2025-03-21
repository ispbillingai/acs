
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
        
        file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " Created InformResponse for session ID: " . $soapId . "\n", FILE_APPEND);
        return $response;
    }
    
    // Simplified request to get only SSID parameters
    public function createSSIDDiscoveryRequest($id = null) {
        $soapId = $id ?? '1';
        $response = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:GetParameterValues>
      <ParameterNames SOAP-ENC:arrayType="xsd:string[4]">
        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID</string>
        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID</string>
        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID</string>
        <string>Device.WiFi.SSID.1.SSID</string>
      </ParameterNames>
    </cwmp:GetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

        file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " Sent direct SSID request\n", FILE_APPEND);
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
      <ParameterPath>InternetGatewayDevice.LANDevice.1.WLANConfiguration.</ParameterPath>
      <NextLevel>false</NextLevel>
    </cwmp:GetParameterNames>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

        file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " GetParameterNames request sent for WLAN discovery\n", FILE_APPEND);
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

        file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " Sent acknowledgement for parameter response\n", FILE_APPEND);
        return $response;
    }
}

