
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
        // Updated with a more conservative set of parameters that are likely to be supported
        // by most Huawei devices, including the HG8145V
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
                    <ParameterNames SOAP-ENC:arrayType="xsd:string[5]">
                        <string>InternetGatewayDevice.DeviceInfo.SerialNumber</string>
                        <string>InternetGatewayDevice.DeviceInfo.HardwareVersion</string>
                        <string>InternetGatewayDevice.DeviceInfo.SoftwareVersion</string>
                        <string>InternetGatewayDevice.DeviceInfo.UpTime</string>
                        <string>InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANIPConnection.1.ExternalIPAddress</string>
                    </ParameterNames>
                </cwmp:GetParameterValues>
            </SOAP-ENV:Body>
        </SOAP-ENV:Envelope>';
        
        error_log("InformResponseGenerator: Created Huawei GetParameterValues request for session ID: " . $sessionId);
        file_put_contents(__DIR__ . '/../../../get.log', date('Y-m-d H:i:s') . " Huawei GetParameterValues request sent: " . $request . "\n", FILE_APPEND);
        return $request;
    }
}
