
<?php
require_once __DIR__ . '/../responses/InformResponseGenerator.php';

class MessageHandler {
    private $logger;
    private $sessionManager;
    private $taskHandler;
    private $db;

    public function __construct($db, $logger, $sessionManager, $taskHandler) {
        $this->db = $db;
        $this->logger = $logger;
        $this->sessionManager = $sessionManager;
        $this->taskHandler = $taskHandler;
    }

    public function handleInform($raw_post) {
        // Extract the SOAP ID
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        // Extract device serial number
        preg_match('/<SerialNumber>(.*?)<\/SerialNumber>/s', $raw_post, $serialMatches);
        $serialNumber = isset($serialMatches[1]) ? trim($serialMatches[1]) : null;
        
        if ($serialNumber) {
            $this->logger->logToFile("Device inform received - Serial: $serialNumber");
            $this->updateDeviceStatus($serialNumber);
            $this->sessionManager->startNewSession($serialNumber);
            
            // Look for pending tasks
            $pendingTasks = $this->taskHandler->getPendingTasks($serialNumber);
            if (!empty($pendingTasks)) {
                $this->sessionManager->setCurrentTask($pendingTasks[0]);
            }
        }
        
        // Generate and return InformResponse
        $responseGenerator = new InformResponseGenerator();
        return $responseGenerator->createResponse($soapId);
    }

    private function updateDeviceStatus($serialNumber) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO devices 
                    (serial_number, status, last_contact) 
                VALUES 
                    (:serial, 'online', NOW()) 
                ON DUPLICATE KEY UPDATE 
                    status = 'online', 
                    last_contact = NOW()
            ");
            $stmt->execute([':serial' => $serialNumber]);
        } catch (PDOException $e) {
            $this->logger->logToFile("Database error updating device status: " . $e->getMessage());
        }
    }
}
