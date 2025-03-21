
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
            // Default parameters for WiFi discovery if none specified
            $parameters = [
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_WPAKey',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.KeyPassphrase',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.X_HW_WPAKey',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.KeyPassphrase',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.BeaconType'
            ];
        }
        
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
        
        // Different discovery steps for HG8145V
        switch ($step) {
            case 1:
                // First try to get all WLAN configuration
                $path = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.";
                $nextLevel = "false";
                file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " HG8145V Step 1: GetParameterNames for " . $path . "\n", FILE_APPEND);
                break;
                
            case 2:
                // Based on the discovered parameters, now directly request the WiFi credentials
                file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " HG8145V Step 2: Requesting WiFi credentials\n", FILE_APPEND);
                return $this->createCustomGetParameterValuesRequest($id, [
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.KeyPassphrase'
                ]);
                
            case 3:
                // Try to get Huawei-specific parameters
                file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " HG8145V Step 3: Requesting Huawei-specific WiFi parameters\n", FILE_APPEND);
                return $this->createCustomGetParameterValuesRequest($id, [
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_WPAKey',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.X_HW_WPAKey',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_SecurityMode',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.X_HW_SecurityMode'
                ]);
                
            case 4:
                // Try to get alternative parameter paths
                file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " HG8145V Step 4: Requesting alternative WiFi password paths\n", FILE_APPEND);
                return $this->createCustomGetParameterValuesRequest($id, [
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.KeyPassphrase',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.BeaconType'
                ]);
                
            case 5:
                // Try a different namespace approach
                file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " HG8145V Step 5: Checking for X_HW_WLAN namespace\n", FILE_APPEND);
                $path = "InternetGatewayDevice.X_HW_WLAN.";
                $nextLevel = "false";
                break;
                
            default:
                // Default to step 1
                $path = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.";
                $nextLevel = "false";
                file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " HG8145V Default: GetParameterNames for " . $path . "\n", FILE_APPEND);
        }
        
        // For steps 1 and 5, use GetParameterNames
        $response = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:GetParameterNames>
      <ParameterPath>' . $path . '</ParameterPath>
      <NextLevel>' . $nextLevel . '</NextLevel>
    </cwmp:GetParameterNames>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

        return $response;
    }
    
    // Create a general GetParameterValues request for standard WiFi parameters
    public function createGetParameterValuesRequest($id = null) {
        return $this->createCustomGetParameterValuesRequest($id);
    }
}
