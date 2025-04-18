Test Epon 
Test gpon
Use this  to get all the <details></details>

<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope 
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">get-epontest-01</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:GetParameterValues>
      <ParameterNames soap-enc:arrayType="xsd:string[1]">
        <string>InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPONInterfaceConfig.SignalLevel</string>
      </ParameterNames>
    </cwmp:GetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>
