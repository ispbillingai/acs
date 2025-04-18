
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Initialize error logging
function writeErrorLog($message) {
    $logFile = __DIR__ . '/retrieve.log';
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [ERROR] " . $message . "\n", FILE_APPEND);
}

// Core device parameters to retrieve
$coreParameters = [
    'InternetGatewayDevice.DeviceInfo.Manufacturer',
    'InternetGatewayDevice.DeviceInfo.ProductClass',
    'InternetGatewayDevice.DeviceInfo.SerialNumber',
    'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
    'InternetGatewayDevice.DeviceInfo.HardwareVersion',
    'InternetGatewayDevice.DeviceInfo.UpTime',
    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
    'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries',
    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress'
];

// Function to generate parameter request XML
function generateParameterRequestXML($soapId, $parameters) {
    $parameterStrings = '';
    foreach ($parameters as $param) {
        $parameterStrings .= "        <string>" . htmlspecialchars($param) . "</string>\n";
    }
    
    return '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:GetParameterValues>
      <ParameterNames SOAP-ENC:arrayType="xsd:string[' . count($parameters) . ']">
' . $parameterStrings . '      </ParameterNames>
    </cwmp:GetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_post = file_get_contents('php://input');
    
    if (!empty($raw_post)) {
        try {
            // Parse the incoming SOAP request
            $xml = simplexml_load_string($raw_post);
            if ($xml === false) {
                writeErrorLog("Failed to parse XML request");
                throw new Exception("Failed to parse XML request");
            }

            // Extract SOAP ID
            $namespaces = $xml->getNamespaces(true);
            $header = $xml->children($namespaces['SOAP-ENV'])->Header;
            $cwmpHeader = $header->children($namespaces['cwmp']);
            $soapId = (string)$cwmpHeader->ID;

            // Handle Inform request
            if (stripos($raw_post, '<cwmp:Inform>') !== false) {
                writeErrorLog("Received Inform request");
                
                // Clean up existing router_ssids.txt
                $ssidsFile = $_SERVER['DOCUMENT_ROOT'] . '/router_ssids.txt';
                file_put_contents($ssidsFile, "# Device Information\n\n", LOCK_EX);
                
                // Send InformResponse
                $response = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
    <SOAP-ENV:Header>
        <cwmp:ID xmlns:cwmp="urn:dslforum-org:cwmp-1-0" SOAP-ENV:mustUnderstand="1">' . $soapId . '</cwmp:ID>
    </SOAP-ENV:Header>
    <SOAP-ENV:Body>
        <cwmp:InformResponse>
            <MaxEnvelopes>1</MaxEnvelopes>
        </cwmp:InformResponse>
    </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

                header('Content-Type: text/xml');
                echo $response;
                exit;
            }

            // Handle GetParameterValuesResponse
            if (stripos($raw_post, 'GetParameterValuesResponse') !== false) {
                writeErrorLog("Received GetParameterValuesResponse");
                
                $cwmp = $xml->children($namespaces['cwmp']);
                $parameterList = $cwmp->GetParameterValuesResponse->ParameterList;
                
                foreach ($parameterList->children() as $param) {
                    $name = (string)$param->Name;
                    $value = (string)$param->Value;
                    
                    // Format the output based on parameter name
                    $formattedValue = '';
                    if (stripos($name, 'Manufacturer') !== false) {
                        $formattedValue = "Manufacturer\n$value\n\n";
                    } else if (stripos($name, 'ProductClass') !== false) {
                        $formattedValue = "Model\n$value\n\n";
                    } else if (stripos($name, 'SerialNumber') !== false) {
                        $formattedValue = "Serial Number\n$value\n\n";
                    } else if (stripos($name, 'ExternalIPAddress') !== false) {
                        $formattedValue = "IP Address\n$value\n\n";
                    } else if (stripos($name, 'SSID') !== false) {
                        $formattedValue = "SSID\n$value\n\n";
                    } else if (stripos($name, 'SoftwareVersion') !== false) {
                        $formattedValue = "Software Version\n$value\n\n";
                    } else if (stripos($name, 'HardwareVersion') !== false) {
                        $formattedValue = "Hardware Version\n$value\n\n";
                    } else if (stripos($name, 'UpTime') !== false) {
                        $formattedValue = "Uptime\n$value\n\n";
                    } else if (stripos($name, 'HostNumberOfEntries') !== false) {
                        $formattedValue = "Connected Clients\n$value\n\n";
                    }
                    
                    if (!empty($formattedValue)) {
                        file_put_contents($ssidsFile, $formattedValue, FILE_APPEND | LOCK_EX);
                    }
                }

                // Add last contact time
                $lastContact = "Last Contact\n" . date('Y-m-d H:i:s') . "\n\n";
                file_put_contents($ssidsFile, $lastContact, FILE_APPEND | LOCK_EX);

                // Request the next parameter
                $nextRequest = generateParameterRequestXML($soapId, $coreParameters);
                header('Content-Type: text/xml');
                echo $nextRequest;
                exit;
            }

            // Handle any faults
            if (stripos($raw_post, '<SOAP-ENV:Fault>') !== false || stripos($raw_post, '<cwmp:Fault>') !== false) {
                preg_match('/<FaultCode>(.*?)<\/FaultCode>.*?<FaultString>(.*?)<\/FaultString>/s', $raw_post, $faultMatches);
                if (!empty($faultMatches)) {
                    writeErrorLog("Fault detected - Code: {$faultMatches[1]}, Message: {$faultMatches[2]}");
                }
            }

        } catch (Exception $e) {
            writeErrorLog("Exception: " . $e->getMessage());
            header('HTTP/1.1 500 Internal Server Error');
            exit;
        }
    }
}

// If no specific handler matched, request parameters
try {
    $sessionId = uniqid();
    $initialRequest = generateParameterRequestXML($sessionId, $coreParameters);
    header('Content-Type: text/xml');
    echo $initialRequest;
} catch (Exception $e) {
    writeErrorLog("Failed to generate initial request: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    exit;
}
