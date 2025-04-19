<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Include refactored files
require_once __DIR__ . '/main/Logging.php';
require_once __DIR__ . '/main/TaskCreator.php';
require_once __DIR__ . '/main/ParameterSaver.php';
require_once __DIR__ . '/main/RequestProcessor.php';
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/tr069/auth/AuthenticationHandler.php';
require_once __DIR__ . '/backend/tr069/responses/InformResponseGenerator.php';
require_once __DIR__ . '/backend/tr069/tasks/TaskHandler.php';

// Initialize globals
$GLOBALS['session_id'] = 'session-' . substr(md5(time()), 0, 8);
$GLOBALS['current_task'] = null;
$GLOBALS['device_log'] = __DIR__ . '/device.log';
$GLOBALS['retrieve_log'] = __DIR__ . '/retrieve.log';

// Ensure log files exist
initializeLogFiles();

// Process TR-069 request
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $auth = new AuthenticationHandler();
    if (!$auth->authenticate()) {
        tr069_log("Authentication failed", "ERROR");
        header('HTTP/1.1 401 Unauthorized');
        header('WWW-Authenticate: Basic realm="TR-069 ACS"');
        exit;
    }
    
    $raw_post = file_get_contents('php://input');
    tr069_log("Received request: " . substr($raw_post, 0, 200) . "...", "DEBUG");
    
    $responseGenerator = new InformResponseGenerator();
    $taskHandler = new TaskHandler();
    
    $processor = new RequestProcessor($db, $responseGenerator, $taskHandler);
    $processor->processRequest($raw_post);
    
} catch (Exception $e) {
    tr069_log("Unhandled exception: " . $e->getMessage(), "ERROR");
    header('HTTP/1.1 500 Internal Server Error');
    echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">1</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <soapenv:Fault>
      <faultcode>Server</faultcode>
      <faultstring>Internal Server Error</faultstring>
    </soapenv:Fault>
  </soapenv:Body>
</soapenv:Envelope>';
}

?>