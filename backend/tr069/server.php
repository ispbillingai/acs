
<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/session_manager.php';
require_once __DIR__ . '/device_manager.php';
require_once __DIR__ . '/parsers/InformMessageParser.php';
require_once __DIR__ . '/responses/InformResponseGenerator.php';
require_once __DIR__ . '/auth/AuthenticationHandler.php';

class TR069Server {
    private $db;
    private $sessionManager;
    private $deviceManager;
    private $informParser;
    private $responseGenerator;
    private $authHandler;
    private $soapResponse;
    private $deviceId;
    private $sessionId;

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->sessionManager = new SessionManager($this->db);
        $this->deviceManager = new DeviceManager($this->db);
        $this->informParser = new InformMessageParser();
        $this->responseGenerator = new InformResponseGenerator();
        $this->authHandler = new AuthenticationHandler();
    }

    public function handleRequest() {
        error_log("TR069Server: Beginning request handling");
        error_log("TR-069 Request received: " . date('Y-m-d H:i:s'));
        
        if (!$this->authHandler->authenticate()) {
            header('WWW-Authenticate: Basic realm="TR-069 ACS"');
            header('HTTP/1.1 401 Unauthorized');
            exit('Authentication required');
        }

        $rawPost = file_get_contents('php://input');
        error_log("Raw POST data: " . $rawPost);
        
        if (empty($rawPost)) {
            $this->handleEmptyRequest();
            return;
        }

        try {
            libxml_use_internal_errors(true);
            $xml = new SimpleXMLElement($rawPost);
            $this->processRequest($xml);
        } catch (Exception $e) {
            error_log("TR069Server Error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            header('HTTP/1.1 500 Internal Server Error');
            echo "Internal Server Error: " . $e->getMessage();
            exit;
        }

        $this->sendResponse();
    }

    private function handleEmptyRequest() {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            header('Content-Type: text/html; charset=utf-8');
            echo "TR-069 ACS Server";
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('HTTP/1.1 204 No Content');
            return;
        }
        
        header('HTTP/1.1 405 Method Not Allowed');
        header('Allow: GET, POST');
        exit('Method Not Allowed');
    }

    private function processRequest($xml) {
        $namespace = $xml->getNamespaces(true);
        $soapEnv = isset($namespace['soapenv']) ? $namespace['soapenv'] : 'http://schemas.xmlsoap.org/soap/envelope/';
        $cwmp = isset($namespace['cwmp']) ? $namespace['cwmp'] : 'urn:dslforum-org:cwmp-1-0';

        $body = $xml->children($soapEnv)->Body;
        if (empty($body)) {
            throw new Exception("Empty SOAP Body");
        }

        $request = $body->children($cwmp);
        $requestName = $request->getName();

        error_log("Processing request type: " . $requestName);

        switch ($requestName) {
            case 'Inform':
                $this->handleInform($request);
                break;
            default:
                throw new Exception("Unknown request type: $requestName");
        }
    }

    private function handleInform($request) {
        try {
            $deviceInfo = $this->informParser->parseInform($request);
            $this->deviceId = $this->deviceManager->updateDevice($deviceInfo);
            $this->sessionId = $this->sessionManager->createSession($deviceInfo['serialNumber']);
            $this->soapResponse = $this->responseGenerator->createResponse($this->sessionId);
            
            error_log("Inform handled successfully. Device ID: " . $this->deviceId . ", Session ID: " . $this->sessionId);
        } catch (Exception $e) {
            error_log("Error in handleInform: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    private function sendResponse() {
        if ($this->soapResponse) {
            header('Content-Type: text/xml; charset=utf-8');
            echo $this->soapResponse;
            error_log("Response sent: " . $this->soapResponse);
        }
    }
}
