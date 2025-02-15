
<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/session_manager.php';
require_once __DIR__ . '/device_manager.php';

class TR069Server {
    private $db;
    private $sessionManager;
    private $deviceManager;
    private $soapResponse;

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->sessionManager = new SessionManager($this->db);
        $this->deviceManager = new DeviceManager($this->db);
        $this->soapResponse = null;
    }

    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';

        if ($method !== 'POST' || strpos($contentType, 'text/xml') === false) {
            header('HTTP/1.1 405 Method Not Allowed');
            exit('Invalid request method or content type');
        }

        $rawPost = file_get_contents('php://input');
        if (empty($rawPost)) {
            header('HTTP/1.1 400 Bad Request');
            exit('Empty request body');
        }

        try {
            $xml = new SimpleXMLElement($rawPost);
            $this->processRequest($xml);
        } catch (Exception $e) {
            error_log("TR-069 Error: " . $e->getMessage());
            header('HTTP/1.1 500 Internal Server Error');
            exit('Error processing request');
        }

        $this->sendResponse();
    }

    private function processRequest($xml) {
        $namespace = $xml->getNamespaces(true);
        $soapEnv = $namespace['soapenv'];
        $cwmp = $namespace['cwmp'];

        $body = $xml->children($soapEnv)->Body;
        $request = $body->children($cwmp);
        $requestName = $request->getName();

        switch ($requestName) {
            case 'Inform':
                $this->handleInform($request);
                break;
            case 'TransferComplete':
                $this->handleTransferComplete($request);
                break;
            case 'GetParameterValuesResponse':
                $this->handleGetParameterValuesResponse($request);
                break;
            default:
                throw new Exception("Unknown request type: $requestName");
        }
    }

    private function handleInform($request) {
        $deviceId = (string)$request->DeviceId;
        $events = $request->Event->EventStruct;
        $parameters = $request->ParameterList->ParameterValueStruct;

        // Process device information
        $deviceInfo = [
            'manufacturer' => (string)$deviceId->Manufacturer,
            'oui' => (string)$deviceId->OUI,
            'productClass' => (string)$deviceId->ProductClass,
            'serialNumber' => (string)$deviceId->SerialNumber
        ];

        // Update device in database
        $this->deviceManager->updateDevice($deviceInfo);

        // Create new session
        $sessionId = $this->sessionManager->createSession($deviceInfo['serialNumber']);

        // Prepare Inform response
        $this->soapResponse = $this->createInformResponse($sessionId);
    }

    private function createInformResponse($sessionId) {
        return '<?xml version="1.0" encoding="UTF-8"?>
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
    }

    private function sendResponse() {
        if ($this->soapResponse) {
            header('Content-Type: text/xml; charset=utf-8');
            echo $this->soapResponse;
        }
    }
}
