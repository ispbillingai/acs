
<?php

class CommitHelper
{
    private $logger;

    public function __construct($logger)
    {
        $this->logger = $logger;
    }

    public function sendCommit($acs)
    {
        $commandKey = 'commit-' . date('Ymd-His');
        $soap = $this->buildSoapEnvelope('Commit', ['CommandKey' => $commandKey]);
        $acs->sendSoap($soap);
        $this->logger->logToFile("Commit RPC sent (key $commandKey)");
    }

    private function buildSoapEnvelope($method, $params)
    {
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope 
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <soapenv:Header>
        <cwmp:ID soapenv:mustUnderstand="1">' . uniqid() . '</cwmp:ID>
    </soapenv:Header>
    <soapenv:Body>
        <cwmp:' . $method . '>';

        foreach ($params as $key => $value) {
            $envelope .= "\n            <$key>$value</$key>";
        }

        $envelope .= '
        </cwmp:' . $method . '>
    </soapenv:Body>
</soapenv:Envelope>';

        return $envelope;
    }
}

