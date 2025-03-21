
<?php
class InformResponseGenerator {
    public function createResponse($sessionId) {
        // First send the InformResponse
        $informResponse = '<?xml version="1.0" encoding="UTF-8"?>
        <SOAP-ENV:Envelope 
            xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" 
            xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
            <SOAP-ENV:Header>
                <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $sessionId . '</cwmp:ID>
            </SOAP-ENV:Header>
            <SOAP-ENV:Body>
                <cwmp:InformResponse>
                    <MaxEnvelopes>1</MaxEnvelopes>
                </cwmp:InformResponse>
            </SOAP-ENV:Body>
        </SOAP-ENV:Envelope>';

        error_log("TR069: Created InformResponse for session ID: " . $sessionId);
        return $informResponse;
    }

    public function createGetParameterValuesRequest($sessionId) {
        $request = '<?xml version="1.0" encoding="UTF-8"?>
        <SOAP-ENV:Envelope
            xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"
            xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
            <SOAP-ENV:Header>
                <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $sessionId . '</cwmp:ID>
            </SOAP-ENV:Header>
            <SOAP-ENV:Body>
                <cwmp:GetParameterValues>
                    <ParameterNames SOAP-ENV:arrayType="xsd:string[1]">
                        <string>InternetGatewayDevice.DeviceInfo.SoftwareVersion</string>
                    </ParameterNames>
                </cwmp:GetParameterValues>
            </SOAP-ENV:Body>
        </SOAP-ENV:Envelope>';
        
        error_log("TR069: Created basic GetParameterValues request for session ID: " . $sessionId);
        file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " Basic GetParameterValues for SoftwareVersion sent\n", FILE_APPEND);
        return $request;
    }
    
    public function createWifiDiscoveryRequest($sessionId) {
        // First step: discover if WLANConfiguration exists and its children
        return $this->createGetParameterNamesRequest($sessionId, "InternetGatewayDevice.LANDevice.1.WLANConfiguration.", 1);
    }
    
    public function createGetParameterNamesRequest($sessionId, $parameterPath = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.", $nextLevel = 1) {
        $request = '<?xml version="1.0" encoding="UTF-8"?>
        <SOAP-ENV:Envelope
            xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"
            xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
            xmlns:xsd="http://www.w3.org/2001/XMLSchema"
            xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/">
            <SOAP-ENV:Header>
                <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $sessionId . '</cwmp:ID>
            </SOAP-ENV:Header>
            <SOAP-ENV:Body>
                <cwmp:GetParameterNames>
                    <ParameterPath>' . $parameterPath . '</ParameterPath>
                    <NextLevel>' . $nextLevel . '</NextLevel>
                </cwmp:GetParameterNames>
            </SOAP-ENV:Body>
        </SOAP-ENV:Envelope>';
        
        error_log("TR069: Created GetParameterNames request for path: " . $parameterPath . ", session ID: " . $sessionId);
        file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " GetParameterNames request sent for path: " . $parameterPath . "\n", FILE_APPEND);
        return $request;
    }
    
    public function createCustomGetParameterValuesRequest($sessionId, $parameterPaths) {
        // Create a GetParameterValues request with custom parameter paths
        $paramCount = count($parameterPaths);
        
        $paramXml = '';
        foreach ($parameterPaths as $path) {
            $paramXml .= "<string>{$path}</string>\n";
        }
        
        $request = '<?xml version="1.0" encoding="UTF-8"?>
        <SOAP-ENV:Envelope
            xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"
            xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
            xmlns:xsd="http://www.w3.org/2001/XMLSchema"
            xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/">
            <SOAP-ENV:Header>
                <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $sessionId . '</cwmp:ID>
            </SOAP-ENV:Header>
            <SOAP-ENV:Body>
                <cwmp:GetParameterValues>
                    <ParameterNames SOAP-ENC:arrayType="xsd:string[' . $paramCount . ']">
                        ' . $paramXml . '
                    </ParameterNames>
                </cwmp:GetParameterValues>
            </SOAP-ENV:Body>
        </SOAP-ENV:Envelope>';
        
        error_log("TR069: Created custom GetParameterValues request with " . $paramCount . " parameters");
        file_put_contents(__DIR__ . '/../../../wifi_discovery.log', date('Y-m-d H:i:s') . " Custom GetParameterValues for " . implode(", ", $parameterPaths) . "\n", FILE_APPEND);
        return $request;
    }
    
    public function createHG8145VWifiDiscoverySequence($sessionId, $step = 1) {
        switch ($step) {
            case 1:
                // Start with root WLANConfiguration
                return $this->createGetParameterNamesRequest($sessionId, "InternetGatewayDevice.LANDevice.1.WLANConfiguration.", 1);
                
            case 2:
                // Now explore WLAN instance 1
                return $this->createGetParameterNamesRequest($sessionId, "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.", 1);
                
            case 3:
                // Try basic parameters that should exist
                return $this->createCustomGetParameterValuesRequest(
                    $sessionId,
                    ["InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID"]
                );
                
            case 4:
                // Try to find the password parameter structure
                return $this->createGetParameterNamesRequest($sessionId, "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.", 1);
                
            case 5:
                // Try Huawei-specific parameters
                return $this->createCustomGetParameterValuesRequest(
                    $sessionId,
                    [
                        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_WPAKey", 
                        "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_SecretKey"
                    ]
                );
                
            default:
                // Fallback to standard SSID request
                return $this->createCustomGetParameterValuesRequest(
                    $sessionId,
                    ["InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID"]
                );
        }
    }
}
