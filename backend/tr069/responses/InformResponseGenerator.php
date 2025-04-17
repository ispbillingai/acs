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
        
        return $response;
    }
    
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

        return $response;
    }
    
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

        return $response;
    }
    
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

        return $response;
    }
    
    public function createOpticalPowerRequest($id = null) {
        $soapId = $id ?? '1';
        $response = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:GetParameterValues>
      <ParameterNames SOAP-ENC:arrayType="xsd:string[6]">
        <string>InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.TXPower</string>
        <string>InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.RXPower</string>
        <string>InternetGatewayDevice.WANDevice.1.X_EponInterfaceConfig.TXPower</string>
        <string>InternetGatewayDevice.WANDevice.1.X_EponInterfaceConfig.RXPower</string>
        <string>InternetGatewayDevice.Device.Optical.Interface.1.CurrentTXPower</string>
        <string>InternetGatewayDevice.Device.Optical.Interface.1.CurrentRXPower</string>
      </ParameterNames>
    </cwmp:GetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

        return $response;
    }
    
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

        return $response;
    }
    
    public function createSetSSIDRequest($ssid, $id = null) {
        $soapId = 'tr181-wifi-a1acc4a2';
        $parameterKey = 'TR181ChangeSSIDa1a';
        
        $response = '<?xml version="1.0" encoding="UTF-8"?>
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
    <cwmp:SetParameterValues>
      <ParameterList soap-enc:arrayType="cwmp:ParameterValueStruct[1]">
        <ParameterValueStruct>
          <Name>InternetGatewayDevice.Device.WiFi.SSID.1.SSID</Name>
          <Value xsi:type="xsd:string">' . htmlspecialchars($ssid) . '</Value>
        </ParameterValueStruct>
      </ParameterList>
      <ParameterKey>' . $parameterKey . '</ParameterKey>
    </cwmp:SetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';

        return $response;
    }
    
    public function createSetWiFiPasswordRequest($password, $id = null) {
        $soapId = $id ?? 'set-wifi-pass-' . substr(md5(time()), 0, 8);
        $parameterKey = 'ChangeWiFiPass' . substr(md5(time()), 0, 3);
        
        $response = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:SetParameterValues>
      <ParameterList SOAP-ENC:arrayType="cwmp:ParameterValueStruct[1]">
        <ParameterValueStruct>
          <Name>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase</Name>
          <Value xsi:type="xsd:string">' . htmlspecialchars($password) . '</Value>
        </ParameterValueStruct>
      </ParameterList>
      <ParameterKey>' . $parameterKey . '</ParameterKey>
    </cwmp:SetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

        return $response;
    }
    
    public function createCustomGetParameterValuesRequest($id = null, $parameterNames = []) {
        $soapId = $id ?? '1';
        
        if (empty($parameterNames)) {
            $parameterNames = ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID'];
        }
        
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

        return $response;
    }
    
    public function createCompleteWiFiConfigRequest($ssid, $password = null) {
        $soapId = 'tr181-wifi-a1acc4a2';
        $parameterKey = 'TR181ChangeSSIDa1a';
        
        $paramCount = empty($password) ? 1 : 3;
        
        $paramXml = '        <ParameterValueStruct>
          <Name>InternetGatewayDevice.Device.WiFi.SSID.1.SSID</Name>
          <Value xsi:type="xsd:string">' . htmlspecialchars($ssid) . '</Value>
        </ParameterValueStruct>';
        
        if (!empty($password)) {
            $paramXml .= '
        <ParameterValueStruct>
          <Name>InternetGatewayDevice.Device.WiFi.AccessPoint.1.Security.KeyPassphrase</Name>
          <Value xsi:type="xsd:string">' . htmlspecialchars($password) . '</Value>
        </ParameterValueStruct>
        <ParameterValueStruct>
          <Name>InternetGatewayDevice.Device.WiFi.AccessPoint.1.Security.ModeEnabled</Name>
          <Value xsi:type="xsd:string">WPA2-Personal</Value>
        </ParameterValueStruct>';
        }
        
        $response = '<?xml version="1.0" encoding="UTF-8"?>
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
    <cwmp:SetParameterValues>
      <ParameterList soap-enc:arrayType="cwmp:ParameterValueStruct[' . $paramCount . ']">
' . $paramXml . '
      </ParameterList>
      <ParameterKey>' . $parameterKey . '</ParameterKey>
    </cwmp:SetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';

        return $response;
    }
    
    public function createMultiModelWiFiRequests($ssid, $password = null) {
        $requests = [];
        
        $requests['tr181'] = $this->createCompleteWiFiConfigRequest($ssid, $password);
        
        $soapId = 'tr098-wifi-a1acc4a2';
        $parameterKey = 'TR098ChangeSSIDa1a';
        
        $paramCount = empty($password) ? 1 : 3;
        
        $paramXml = '        <ParameterValueStruct>
          <Name>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID</Name>
          <Value xsi:type="xsd:string">' . htmlspecialchars($ssid) . '</Value>
        </ParameterValueStruct>';
        
        if (!empty($password)) {
            $paramXml .= '
        <ParameterValueStruct>
          <Name>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase</Name>
          <Value xsi:type="xsd:string">' . htmlspecialchars($password) . '</Value>
        </ParameterValueStruct>
        <ParameterValueStruct>
          <Name>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType</Name>
          <Value xsi:type="xsd:string">WPAand11i</Value>
        </ParameterValueStruct>';
        }
        
        $requests['tr098'] = '<?xml version="1.0" encoding="UTF-8"?>
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
    <cwmp:SetParameterValues>
      <ParameterList soap-enc:arrayType="cwmp:ParameterValueStruct[' . $paramCount . ']">
' . $paramXml . '
      </ParameterList>
      <ParameterKey>' . $parameterKey . '</ParameterKey>
    </cwmp:SetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';
        
        return $requests;
    }
}
