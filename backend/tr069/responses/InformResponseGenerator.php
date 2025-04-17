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
        $soapId = 'set-wlan-ssid';
        $parameterKey = 'WifiUpdate-' . date('Ymd');
        
        $paramCount = empty($password) ? 1 : 2;
        
        $paramXml = '        <!-- SSID (2.4 GHz, instance 1) -->
        <ParameterValueStruct>
          <Name>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID</Name>
          <Value xsi:type="xsd:string">' . htmlspecialchars($ssid) . '</Value>
        </ParameterValueStruct>';
        
        if (!empty($password)) {
            $paramXml .= '
        <!-- WPA2 key -->
        <ParameterValueStruct>
          <Name>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase</Name>
          <Value xsi:type="xsd:string">' . htmlspecialchars($password) . '</Value>
        </ParameterValueStruct>';
        }
        
        $response = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
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
    
    public function createAlternatePasswordRequest($ssid, $password) {
        $soapId = 'set-wlan-alt-pw';
        $parameterKey = 'WifiUpdate-AltPw-' . date('Ymd');
        
        $response = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                  xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:SetParameterValues>
      <ParameterList soap-enc:arrayType="cwmp:ParameterValueStruct[2]">
        <ParameterValueStruct>
          <Name>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID</Name>
          <Value xsi:type="xsd:string">' . htmlspecialchars($ssid) . '</Value>
        </ParameterValueStruct>
        <ParameterValueStruct>
          <Name>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey</Name>
          <Value xsi:type="xsd:string">' . htmlspecialchars($password) . '</Value>
        </ParameterValueStruct>
      </ParameterList>
      <ParameterKey>' . $parameterKey . '</ParameterKey>
    </cwmp:SetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';

        return $response;
    }
    
    public function createConnectionRequestTrigger($username, $password, $connectionRequestUrl) {
        $logFile = __DIR__ . '/../../../logs/tr069_connection.log';
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "$timestamp - Creating connection request trigger:\n";
        $logEntry .= "  URL: $connectionRequestUrl\n";
        $logEntry .= "  Username: $username\n";
        $logEntry .= "  Password: [REDACTED]\n";
        $logEntry .= "  Command: curl -i -u \"$username:$password\" \"$connectionRequestUrl\"\n";
        
        if (!file_exists(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        
        // Add alternative connection request URLs with different ports
        $ipAddress = parse_url($connectionRequestUrl, PHP_URL_HOST);
        $altCommands = [];
        $commonPorts = [30005, 37215, 7547, 4567];
        
        foreach ($commonPorts as $port) {
            $altUrl = "http://$ipAddress:$port/";
            $altCommands[] = "curl -i -u \"$username:$password\" \"$altUrl\"";
            $logEntry = "$timestamp - Alternative connection request URL: $altUrl\n";
            file_put_contents($logFile, $logEntry, FILE_APPEND);
        }
        
        return [
            'username' => $username,
            'password' => $password,
            'url' => $connectionRequestUrl,
            'command' => "curl -i -u \"$username:$password\" \"$connectionRequestUrl\"",
            'alternative_commands' => $altCommands
        ];
    }
    
    public function createCommitRequest($id = null) {
        $soapId = $id ?? 'commit-' . substr(md5(time()), 0, 8);
        
        $response = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:Commit>
      <CommandKey>commit-' . date('Ymd') . '</CommandKey>
    </cwmp:Commit>
  </soapenv:Body>
</soapenv:Envelope>';

        return $response;
    }
    
    public function createHG8145V5WifiRequest($ssid, $password) {
        $soapId = 'set-wlan-hg8145v5-' . substr(md5(time()), 0, 8);
        $parameterKey = 'WifiUpdate-' . date('Ymd');
        
        $logFile = __DIR__ . '/../../../logs/tr069_wifi.log';
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "$timestamp - Creating HG8145V5 WiFi request:\n";
        $logEntry .= "  SSID: $ssid\n";
        $logEntry .= "  Password Length: " . strlen($password) . " characters\n";
        $logEntry .= "  SOAP ID: $soapId\n";
        $logEntry .= "  Parameter Key: $parameterKey\n\n";
        
        if (!file_exists(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        
        $response = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                  xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:SetParameterValues>
      <ParameterList soap-enc:arrayType="cwmp:ParameterValueStruct[2]">
        <ParameterValueStruct>
          <Name>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID</Name>
          <Value xsi:type="xsd:string">' . htmlspecialchars($ssid) . '</Value>
        </ParameterValueStruct>
        <ParameterValueStruct>
          <Name>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey</Name>
          <Value xsi:type="xsd:string">' . htmlspecialchars($password) . '</Value>
        </ParameterValueStruct>
      </ParameterList>
      <ParameterKey>' . $parameterKey . '</ParameterKey>
    </cwmp:SetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';
        
        $logEntry = "$timestamp - Generated HG8145V5 WiFi SOAP request:\n$response\n\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        
        return $response;
    }
    
    public function createVerifyWiFiRequest() {
        $soapId = 'verify-wifi-' . substr(md5(time()), 0, 8);
        
        $response = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                  xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:GetParameterValues>
      <ParameterNames SOAP-ENC:arrayType="xsd:string[2]">
        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID</string>
        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey</string>
      </ParameterNames>
    </cwmp:GetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';

        return $response;
    }
    
    public function createConnectionRequestPortDiscovery() {
        $soapId = 'conn-req-port-' . substr(md5(time()), 0, 8);
        
        $response = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                  xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:GetParameterValues>
      <ParameterNames SOAP-ENC:arrayType="xsd:string[3]">
        <string>InternetGatewayDevice.ManagementServer.ConnectionRequestURL</string>
        <string>InternetGatewayDevice.ManagementServer.ConnectionRequestUsername</string>
        <string>InternetGatewayDevice.ManagementServer.ConnectionRequestPassword</string>
      </ParameterNames>
    </cwmp:GetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';

        return $response;
    }
    
    public function createDebugWorkflow($sessionId, $ssid, $password) {
        $logFile = __DIR__ . '/../../../logs/tr069_debug_workflow.log';
        $timestamp = date('Y-m-d H:i:s');
        
        if (!file_exists(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        
        $soapId = 'debug-session-' . $sessionId;
        $parameterKey = 'Debug-' . date('YmdHis');
        $commitKey = 'commit-' . date('YmdHis');
        
        $logEntry = "=== $timestamp - DEBUG TR-069 SESSION $sessionId ===\n";
        $logEntry .= "Configuration target: SSID='$ssid', Password length=" . strlen($password) . "\n";
        $logEntry .= "Using SOAP ID: $soapId\n";
        $logEntry .= "Using Parameter Key: $parameterKey\n\n";
        
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        
        $informResponse = $this->createResponse($soapId);
        file_put_contents($logFile, "$timestamp - 1. InformResponse:\n$informResponse\n\n", FILE_APPEND);
        
        $ssidDiscovery = $this->createSSIDDiscoveryRequest($soapId . '-discovery');
        file_put_contents($logFile, "$timestamp - 2. SSID Discovery Request:\n$ssidDiscovery\n\n", FILE_APPEND);
        
        $wifiConfig = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                  xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '-wifi</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:SetParameterValues>
      <ParameterList soap-enc:arrayType="cwmp:ParameterValueStruct[2]">
        <ParameterValueStruct>
          <Name>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID</Name>
          <Value xsi:type="xsd:string">' . htmlspecialchars($ssid) . '</Value>
        </ParameterValueStruct>
        <ParameterValueStruct>
          <Name>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey</Name>
          <Value xsi:type="xsd:string">' . htmlspecialchars($password) . '</Value>
        </ParameterValueStruct>
      </ParameterList>
      <ParameterKey>' . $parameterKey . '</ParameterKey>
    </cwmp:SetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';
        
        file_put_contents($logFile, "$timestamp - 3. WiFi Configuration Request:\n$wifiConfig\n\n", FILE_APPEND);
        
        $ack = $this->createParameterResponseAcknowledgement($soapId . '-ack');
        file_put_contents($logFile, "$timestamp - 4. Parameter Value Response Acknowledgement:\n$ack\n\n", FILE_APPEND);
        
        $commit = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '-commit</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:Commit>
      <CommandKey>' . $commitKey . '</CommandKey>
    </cwmp:Commit>
  </soapenv:Body>
</soapenv:Envelope>';
        
        file_put_contents($logFile, "$timestamp - 5. Commit Request:\n$commit\n\n", FILE_APPEND);
        
        $verifyRequest = $this->createVerifyWiFiRequest();
        file_put_contents($logFile, "$timestamp - 6. Verification Request:\n$verifyRequest\n\n", FILE_APPEND);
        
        return [
            'session_id' => $sessionId,
            'inform_response' => $informResponse,
            'ssid_discovery' => $ssidDiscovery,
            'wifi_config' => $wifiConfig,
            'acknowledgement' => $ack,
            'commit_request' => $commit,
            'verify_request' => $verifyRequest,
            'parameter_key' => $parameterKey,
            'commit_key' => $commitKey
        ];
    }
    
    public function createDetailedParameterDiscovery($path = 'InternetGatewayDevice.') {
        $soapId = 'param-discovery-' . substr(md5(time()), 0, 8);
        
        $response = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:GetParameterNames>
      <ParameterPath>' . htmlspecialchars($path) . '</ParameterPath>
      <NextLevel>false</NextLevel>
    </cwmp:GetParameterNames>
  </soapenv:Body>
</soapenv:Envelope>';
        
        $logFile = __DIR__ . '/../../../logs/tr069_discovery.log';
        $timestamp = date('Y-m-d H:i:s');
        
        if (!file_exists(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        
        $logEntry = "$timestamp - Parameter discovery request for path: $path\n";
        $logEntry .= "$response\n\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        
        return $response;
    }
}
