
<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/tr069/core/SessionManager.php';
require_once __DIR__ . '/tr069/core/MessageHandler.php';
require_once __DIR__ . '/tr069/core/XMLGenerator.php';
require_once __DIR__ . '/tr069/tasks/TaskHandler.php';

class Logger {
    private $logFile;

    public function __construct() {
        $this->logFile = $_SERVER['DOCUMENT_ROOT'] . '/device.log';
        if (!file_exists(dirname($this->logFile))) {
            mkdir(dirname($this->logFile), 0755, true);
        }
    }

    public function logToFile($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [TR-069] {$message}" . PHP_EOL;
        error_log("[TR-069] {$message}", 0);
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }
}

// Initialize components
$database = new Database();
$db = $database->getConnection();
$logger = new Logger();
$sessionManager = new SessionManager($db, $logger);
$taskHandler = new TaskHandler();
$messageHandler = new MessageHandler($db, $logger, $sessionManager, $taskHandler);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw_post = file_get_contents('php://input');
        $logger->logToFile("==== NEW REQUEST ====");
        $logger->logToFile("Received request length: " . strlen($raw_post));
        $logger->logToFile("Request first 100 chars: " . substr($raw_post, 0, 100) . "...");
        
        if (!empty($raw_post)) {
            // Handle Inform messages
            if (stripos($raw_post, '<cwmp:Inform>') !== false) {
                $logger->logToFile("Detected Inform message");
                $response = $messageHandler->handleInform($raw_post);
                header('Content-Type: text/xml');
                $logger->logToFile("Sending response length: " . strlen($response));
                echo $response;
                exit;
            }
            
            // Handle GetParameterValuesResponse messages
            if (stripos($raw_post, '<cwmp:GetParameterValuesResponse>') !== false) {
                $logger->logToFile("Detected GetParameterValuesResponse message");
                $response = $messageHandler->handleGetParameterValuesResponse($raw_post);
                header('Content-Type: text/xml');
                $logger->logToFile("Sending response length: " . strlen($response));
                echo $response;
                exit;
            }
            
            // Handle empty POST or the next step in the session after Inform response
            if (empty(trim($raw_post)) || stripos($raw_post, '<cwmp:SetParameterValuesResponse>') !== false) {
                $logger->logToFile("Detected empty POST or SetParameterValuesResponse");
                $response = $messageHandler->handleEmptyPost();
                header('Content-Type: text/xml');
                $logger->logToFile("Sending response length: " . strlen($response));
                echo $response;
                exit;
            }
        } else {
            // Handle completely empty POST
            $logger->logToFile("Detected completely empty POST");
            $response = $messageHandler->handleEmptyPost();
            header('Content-Type: text/xml');
            $logger->logToFile("Sending response length: " . strlen($response));
            echo $response;
            exit;
        }
        
        // Default response for unhandled cases
        $logger->logToFile("Unhandled message type, sending empty response");
        header('Content-Type: text/xml');
        $emptyResponse = XMLGenerator::generateEmptyResponse(uniqid());
        $logger->logToFile("Sending response length: " . strlen($emptyResponse));
        echo $emptyResponse;
        exit;
    }
} catch (Exception $e) {
    $logger->logToFile("Exception: " . $e->getMessage());
    $logger->logToFile("Stack trace: " . $e->getTraceAsString());
    header('Content-Type: text/xml');
    echo XMLGenerator::generateEmptyResponse(uniqid());
}
