
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
        // Starting with top-level discovery 
        // Try multiple potential TR-069 WiFi parent paths to find the right one for this device
        return $this->createGetParameterNamesRequest($sessionId, "InternetGatewayDevice.", 1);
    }
    
    public function createAlternativeHuaweiWifiRequest($sessionId, $attempts = 0) {
        // Define alternative paths to try based on common Huawei models
        $pathAttempts = [
            // First try standard TR-069 paths
            '<?xml version="1.0" encoding="UTF-8"?>
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
                            <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey</string>
                            <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID</string>
                            <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey</string>
                        </ParameterNames>
                    </cwmp:GetParameterValues>
                </SOAP-ENV:Body>
            </SOAP-ENV:Envelope>',
            
            // Then try vendor-specific extensions
            '<?xml version="1.0" encoding="UTF-8"?>
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
                            <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_SecurityEntry.PreSharedKey</string>
                            <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID</string>
                            <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.X_HW_SecurityEntry.PreSharedKey</string>
                        </ParameterNames>
                    </cwmp:GetParameterValues>
                </SOAP-ENV:Body>
            </SOAP-ENV:Envelope>',
            
            // Try another common pattern
            '<?xml version="1.0" encoding="UTF-8"?>
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
                        <ParameterNames SOAP-ENC:arrayType="xsd:string[2]">
                            <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.</string>
                            <string>InternetGatewayDevice.X_HW_WLAN.</string>
                        </ParameterNames>
                    </cwmp:GetParameterValues>
                </SOAP-ENV:Body>
            </SOAP-ENV:Envelope>'
        ];
        
        // Get the appropriate attempt based on the counter
        $attemptIndex = $attempts % count($pathAttempts);
        $request = $pathAttempts[$attemptIndex];
        
        error_log("InformResponseGenerator: Created Alternative Huawei WiFi request attempt #" . ($attemptIndex + 1) . " for session ID: " . $sessionId);
        file_put_contents(__DIR__ . '/../../../get.log', date('Y-m-d H:i:s') . " Alternative Huawei WiFi request attempt #" . ($attemptIndex + 1) . " sent: " . $request . "\n", FILE_APPEND);
        
        return $request;
    }
    
    public function createExploreParametersRequest($sessionId, $phase = 1) {
        // Different phases of exploration:
        // Phase 1: Get top-level InternetGatewayDevice
        // Phase 2: Explore LANDevice
        // Phase 3: Explore WANDevice
        // Phase 4: Explore X_HW_* vendor extensions
        
        $paramPath = "";
        $nextLevel = 1; // Usually just get immediate children
        
        switch ($phase) {
            case 1:
                $paramPath = "InternetGatewayDevice.";
                break;
            case 2:
                $paramPath = "InternetGatewayDevice.LANDevice.";
                break;
            case 3:
                $paramPath = "InternetGatewayDevice.LANDevice.1.";
                break;
            case 4:
                $paramPath = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.";
                break;
            case 5:
                $paramPath = "InternetGatewayDevice.X_HW_Features.";
                break;
            case 6:
                $paramPath = "InternetGatewayDevice.X_HW_WLAN.";
                break;
            default:
                // Default to exploring the WiFi configurations in detail
                $paramPath = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.";
                $nextLevel = 0; // Get full subtree
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
                <cwmp:GetParameterNames>
                    <ParameterPath>' . $paramPath . '</ParameterPath>
                    <NextLevel>' . $nextLevel . '</NextLevel>
                </cwmp:GetParameterNames>
            </SOAP-ENV:Body>
        </SOAP-ENV:Envelope>';
        
        error_log("InformResponseGenerator: Created EXPLORATION GetParameterNames request for phase " . $phase . ", path: " . $paramPath);
        file_put_contents(__DIR__ . '/../../../get.log', date('Y-m-d H:i:s') . " EXPLORATION Phase " . $phase . " request for path: " . $paramPath . "\n", FILE_APPEND);
        
        return $request;
    }
    
    public function createWifiSecurityParametersRequest($sessionId) {
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
                    <ParameterNames SOAP-ENC:arrayType="xsd:string[10]">
                        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID</string>
                        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey</string>
                        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase</string>
                        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SecurityKey</string>
                        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.WEPKey.1.WEPKey</string>
                        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_SecurityKey</string>
                        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID</string>
                        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.PreSharedKey</string>
                        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.KeyPassphrase</string>
                        <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SecurityKey</string>
                    </ParameterNames>
                </cwmp:GetParameterValues>
            </SOAP-ENV:Body>
        </SOAP-ENV:Envelope>';
        
        error_log("InformResponseGenerator: Created comprehensive WiFi security parameters request");
        file_put_contents(__DIR__ . '/../../../get.log', date('Y-m-d H:i:s') . " Trying comprehensive WiFi security parameters\n", FILE_APPEND);
        
        return $request;
    }

    // New methods specifically for HG8145V model
    public function createHG8145VDiscoverySequence($sessionId, $step = 1) {
        switch ($step) {
            case 1:
                // First step: Get root level parameters
                return $this->createGetParameterNamesRequest($sessionId, "InternetGatewayDevice.", 1);
            
            case 2:
                // Second step: Explore the LANDevice
                return $this->createGetParameterNamesRequest($sessionId, "InternetGatewayDevice.LANDevice.", 1);
                
            case 3:
                // Third step: Explore WLANConfiguration path
                return $this->createGetParameterNamesRequest($sessionId, "InternetGatewayDevice.LANDevice.1.WLANConfiguration.", 1);
                
            case 4:
                // Fourth step: Explore specific HG8145V parameters for WLAN1
                return $this->createGetParameterNamesRequest($sessionId, "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.", 1);
                
            case 5:
                // Fifth step: Explore 5GHz WLAN (often index 5 in Huawei ONTs)
                return $this->createGetParameterNamesRequest($sessionId, "InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.", 1);
                
            case 6:
                // Sixth step: Try vendor specific X_HW paths
                return $this->createGetParameterNamesRequest($sessionId, "InternetGatewayDevice.X_HW_", 1);
                
            default:
                // Final step: Try commonly successful direct parameter values for HG8145V model
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
                            <ParameterNames SOAP-ENC:arrayType="xsd:string[6]">
                                <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID</string>
                                <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_WPAKey</string>
                                <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_HW_SecretKey</string>
                                <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID</string>
                                <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.X_HW_WPAKey</string>
                                <string>InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.X_HW_SecretKey</string>
                            </ParameterNames>
                        </cwmp:GetParameterValues>
                    </SOAP-ENV:Body>
                </SOAP-ENV:Envelope>';
                
                error_log("InformResponseGenerator: Created HG8145V-specific values request");
                file_put_contents(__DIR__ . '/../../../get.log', date('Y-m-d H:i:s') . " HG8145V-specific values request: " . $request . "\n", FILE_APPEND);
                return $request;
        }
    }
}
