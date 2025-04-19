<?php
/*
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

Incase it restarts if its in porgress it stucks there we should sort that
logging should be rotated just for two days okay?
Test with gpon and epon 

//Remember to refactor with gpon the file okay?
//implement a way to find if a user is making too many request should just be within 5 minutes for request okay