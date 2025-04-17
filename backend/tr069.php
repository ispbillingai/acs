<?php
/* -----------------------------------------------------------------
 *  ultra‑minimal TR‑069 endpoint – single file demo
 * ----------------------------------------------------------------*/

error_reporting(E_ALL);
ini_set('display_errors', 0);  // <-- change to 1 only while debugging

/* ---------- helper: build empty envelope ----------------------- */
function empty_rpc($id = null)
{
    $id = $id ?: uniqid();
    return '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
   xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
   xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
 <soapenv:Header>
   <cwmp:ID soapenv:mustUnderstand="1">'.$id.'</cwmp:ID>
 </soapenv:Header>
 <soapenv:Body/>
</soapenv:Envelope>';
}

/* ---------- helper: inner GetParameterValues block ------------- */
function gpv_block(array $names, $id = null)
{
    $id = $id ?: uniqid();
    $list = '';
    foreach ($names as $n) {
        $list .= "\n      <string>".htmlspecialchars($n)."</string>";
    }

    return '<cwmp:GetParameterValues>
      <ParameterNames soap-enc:arrayType="xsd:string['.count($names).']" xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">'.
        $list.'
      </ParameterNames>
    </cwmp:GetParameterValues>';
}

/* ---------- helper: InformResponse envelope -------------------- */
function inform_response($soapId)
{
    return '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">'.$soapId.'</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:InformResponse>
      <MaxEnvelopes>1</MaxEnvelopes>
    </cwmp:InformResponse>
  </soapenv:Body>
</soapenv:Envelope>';
}

/* ---------- main entry ----------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit;    // CPEs always POST
}

$raw = file_get_contents('php://input');
error_log("TR‑069 raw LEN=".strlen($raw)."  head=".substr($raw,0,120));

/* ---- INFORM ----------------------------------------------------*/
if (stripos($raw, '<cwmp:Inform>') !== false) {
    // extract the cwmp:ID
    preg_match('~<cwmp:ID [^>]*>(.*?)</cwmp:ID>~', $raw, $m);
    $soapId = $m[1] ?? '1';

    // pick parameters we always want
    $need = [
        'InternetGatewayDevice.DeviceInfo.UpTime',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
        'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
        'InternetGatewayDevice.DeviceInfo.HardwareVersion'
    ];

    // build InformResponse
    $resp = inform_response($soapId);

    // normalise closing tag (no whitespace)
    $resp = preg_replace('~\s+</([A-Za-z0-9_-]+):Body>~', '</$1:Body>', $resp, 1);

    // inject GPV before </*:Body>
    $compound = preg_replace(
        '~</([A-Za-z0-9_-]+):Body>~',
        gpv_block($need)."\n  </$1:Body>",
        $resp,
        1
    );

    error_log("TR‑069 sending compound (first 200): ".substr($compound,0,200));
    header('Content-Type: text/xml'); echo $compound; exit;
}

/* ---- GetParameterValuesResponse --------------------------------*/
if (stripos($raw, '<cwmp:GetParameterValuesResponse') !== false) {
    error_log("TR‑069 GPV‑RESPONSE head=".substr($raw,0,200));
    // TODO: parse and store values here
    header('Content-Type: text/xml'); echo empty_rpc(); exit;
}

/* ---- any other SOAP (SetParameterValuesResponse etc.) ----------*/
header('Content-Type: text/xml'); echo empty_rpc(); exit;
