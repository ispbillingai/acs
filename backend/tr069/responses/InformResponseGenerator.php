
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
    
    // Create a request to discover all WLAN parameters
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

        file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " GetParameterNames request sent for path: InternetGatewayDevice.LANDevice.1.WLANConfiguration.\n", FILE_APPEND);
        return $response;
    }
    
    // Create a request to get SSID and password values from specific parameters
    public function createCustomGetParameterValuesRequest($id = null, $parameters = []) {
        if (empty($parameters)) {
            // Default parameters for Huawei HG8145V WiFi discovery
            $parameters = [
                // 2.4GHz WiFi parameters
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_WPAKey',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',
                
                // 5GHz WiFi parameters
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.KeyPassphrase',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.X_HW_WPAKey',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.KeyPassphrase',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.BeaconType'
            ];
        }
        
        // Log what parameters we're requesting
        file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " Requesting parameters: " . implode(", ", $parameters) . "\n", FILE_APPEND);
        
        $parameterNames = '';
        foreach ($parameters as $param) {
            $parameterNames .= "<string>{$param}</string>\n        ";
        }
        
        $soapId = $id ?? '1';
        $response = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:GetParameterValues>
      <ParameterNames SOAP-ENC:arrayType="xsd:string[' . count($parameters) . ']">
        ' . $parameterNames . '
      </ParameterNames>
    </cwmp:GetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

        file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " GetParameterValues request sent for " . count($parameters) . " parameters\n", FILE_APPEND);
        return $response;
    }
    
    // Special sequence for HG8145V to discover WiFi parameters
    public function createHG8145VWifiDiscoverySequence($id = null, $step = 1) {
        $soapId = $id ?? '1';
        
        // For HG8145V, directly request the WiFi credentials
        file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " Direct HG8145V WiFi credential request\n", FILE_APPEND);
        
        // Request SSID and password directly for both 2.4GHz and 5GHz interfaces
        return $this->createCustomGetParameterValuesRequest($id, [
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_WPAKey',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.X_HW_WPAKey',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.KeyPassphrase'
        ]);
    }
    
    // Create a general GetParameterValues request for standard WiFi parameters
    public function createGetParameterValuesRequest($id = null) {
        return $this->createCustomGetParameterValuesRequest($id);
    }
}
