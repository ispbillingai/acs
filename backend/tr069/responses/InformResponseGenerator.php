
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

        // Then send GetParameterValues request for additional parameters
        $getParameterValues = '<?xml version="1.0" encoding="UTF-8"?>
        <SOAP-ENV:Envelope
            xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"
            xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
            <SOAP-ENV:Header>
                <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $sessionId . '</cwmp:ID>
            </SOAP-ENV:Header>
            <SOAP-ENV:Body>
                <cwmp:GetParameterValues>
                    <ParameterNames SOAP-ENV:arrayType="xsd:string[7]">
                        <string>Device.WiFi.SSID.1.SSID</string>
                        <string>Device.WiFi.SSID.1.Status</string>
                        <string>Device.WiFi.AccessPoint.1.Security.ModeEnabled</string>
                        <string>Device.WiFi.AccessPoint.1.Security.KeyPassphrase</string>
                        <string>Device.DeviceInfo.UpTime</string>
                        <string>Device.Ethernet.Interface.1.MACAddress</string>
                        <string>Device.WiFi.AccessPoint.1.AssociatedDeviceNumberOfEntries</string>
                    </ParameterNames>
                </cwmp:GetParameterValues>
            </SOAP-ENV:Body>
        </SOAP-ENV:Envelope>';

        return $informResponse;
    }

    public function createGetParameterValuesRequest($sessionId) {
        return '<?xml version="1.0" encoding="UTF-8"?>
        <SOAP-ENV:Envelope
            xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"
            xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
            <SOAP-ENV:Header>
                <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $sessionId . '</cwmp:ID>
            </SOAP-ENV:Header>
            <SOAP-ENV:Body>
                <cwmp:GetParameterValues>
                    <ParameterNames SOAP-ENV:arrayType="xsd:string[7]">
                        <string>Device.WiFi.SSID.1.SSID</string>
                        <string>Device.WiFi.SSID.1.Status</string>
                        <string>Device.WiFi.AccessPoint.1.Security.ModeEnabled</string>
                        <string>Device.WiFi.AccessPoint.1.Security.KeyPassphrase</string>
                        <string>Device.DeviceInfo.UpTime</string>
                        <string>Device.Ethernet.Interface.1.MACAddress</string>
                        <string>Device.WiFi.AccessPoint.1.AssociatedDeviceNumberOfEntries</string>
                    </ParameterNames>
                </cwmp:GetParameterValues>
            </SOAP-ENV:Body>
        </SOAP-ENV:Envelope>';
    }
}
