
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

        error_log("InformResponseGenerator: Created InformResponse for session ID: " . $sessionId);
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
                    <ParameterNames SOAP-ENV:arrayType="xsd:string[2]">
                        <string>Device.DeviceInfo.UpTime</string>
                        <string>Device.Ethernet.Interface.1.MACAddress</string>
                    </ParameterNames>
                </cwmp:GetParameterValues>
            </SOAP-ENV:Body>
        </SOAP-ENV:Envelope>';
        
        error_log("InformResponseGenerator: Created Standard GetParameterValues request for session ID: " . $sessionId);
        file_put_contents(__DIR__ . '/../../../get.log', date('Y-m-d H:i:s') . " Standard GetParameterValues request sent: " . $request . "\n", FILE_APPEND);
        return $request;
    }
    
    public function createHuaweiGetParameterValuesRequest($sessionId) {
        // Updated to focus only on WiFi parameters
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
                    <ParameterNames SOAP-ENC:arrayType="xsd:string[4]">
                        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID</string>
                        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase</string>
                        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID</string>
                        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.KeyPassphrase</string>
                    </ParameterNames>
                </cwmp:GetParameterValues>
            </SOAP-ENV:Body>
        </SOAP-ENV:Envelope>';
        
        error_log("InformResponseGenerator: Created Huawei WiFi-only GetParameterValues request for session ID: " . $sessionId);
        file_put_contents(__DIR__ . '/../../../get.log', date('Y-m-d H:i:s') . " Huawei WiFi-only GetParameterValues request sent: " . $request . "\n", FILE_APPEND);
        return $request;
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
        
        error_log("InformResponseGenerator: Created GetParameterNames request for path: " . $parameterPath . ", session ID: " . $sessionId);
        file_put_contents(__DIR__ . '/../../../get.log', date('Y-m-d H:i:s') . " GetParameterNames request sent for path: " . $parameterPath . ", nextLevel: " . $nextLevel . "\n", FILE_APPEND);
        file_put_contents(__DIR__ . '/../../../get.log', date('Y-m-d H:i:s') . " GetParameterNames request XML: " . $request . "\n", FILE_APPEND);
        return $request;
    }
    
    public function createHuaweiWifiDiscoveryRequest($sessionId) {
        // Start with discovering what's under WLANConfiguration.
        // This will help identify the correct parameter paths
        return $this->createGetParameterNamesRequest($sessionId, "InternetGatewayDevice.LANDevice.1.WLANConfiguration.", 1);
    }
}
