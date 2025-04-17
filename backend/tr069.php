
<?php
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 0);

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
        
        if (!empty($raw_post)) {
            if (stripos($raw_post, '<cwmp:Inform>') !== false) {
                $response = $messageHandler->handleInform($raw_post);
                header('Content-Type: text/xml');
                echo $response;
                exit;
            }
            
            // Check if this is a GetParameterValuesResponse
            if (stripos($raw_post, '<cwmp:GetParameterValuesResponse>') !== false) {
                $logger->logToFile("Received GetParameterValuesResponse");
                
                // Extract the SOAP ID
                preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
                $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
                
                // Extract all parameter values
                preg_match_all('/<ParameterValueStruct>\s*<Name>(.*?)<\/Name>\s*<Value[^>]*>(.*?)<\/Value>\s*<\/ParameterValueStruct>/s', $raw_post, $params, PREG_SET_ORDER);
                
                if (!empty($params)) {
                    $logger->logToFile("Found " . count($params) . " parameters in response");
                    
                    $serialNumber = $sessionManager->getCurrentSessionDeviceSerial();
                    if ($serialNumber) {
                        $logger->logToFile("Found session for device: " . $serialNumber);
                        
                        // Get device ID
                        $stmt = $db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
                        $stmt->execute([':serial' => $serialNumber]);
                        $deviceId = $stmt->fetchColumn();
                        
                        if ($deviceId) {
                            $updateValues = [];
                            
                            foreach ($params as $param) {
                                $paramName = trim($param[1]);
                                $paramValue = trim($param[2]);
                                
                                $logger->logToFile("Processing parameter: $paramName = $paramValue");
                                
                                // Store all parameters in the parameters table
                                try {
                                    $stmt = $db->prepare("
                                        INSERT INTO parameters (device_id, param_name, param_value, created_at, updated_at)
                                        VALUES (:deviceId, :paramName, :paramValue, NOW(), NOW())
                                        ON DUPLICATE KEY UPDATE param_value = :paramValue, updated_at = NOW()
                                    ");
                                    
                                    $stmt->execute([
                                        ':deviceId' => $deviceId,
                                        ':paramName' => $paramName,
                                        ':paramValue' => $paramValue
                                    ]);
                                    
                                    $logger->logToFile("Stored parameter in parameters table");
                                } catch (PDOException $e) {
                                    $logger->logToFile("Error storing parameter: " . $e->getMessage());
                                }
                                
                                // Update specific fields in the devices table
                                if (strpos($paramName, '.UpTime') !== false) {
                                    $updateValues['uptime'] = (int)$paramValue;
                                    $logger->logToFile("Will update uptime: $paramValue");
                                } else if (strpos($paramName, '.SSID') !== false) {
                                    $updateValues['ssid'] = $paramValue;
                                    $logger->logToFile("Will update SSID: $paramValue");
                                } else if (strpos($paramName, '.ExternalIPAddress') !== false) {
                                    $updateValues['ip_address'] = $paramValue;
                                    $logger->logToFile("Will update IP: $paramValue");
                                }
                            }
                            
                            // Update the device record if we have values to update
                            if (!empty($updateValues)) {
                                $updateSql = "UPDATE devices SET ";
                                $updateParams = [':deviceId' => $deviceId];
                                
                                foreach ($updateValues as $field => $value) {
                                    $updateSql .= "$field = :$field, ";
                                    $updateParams[":$field"] = $value;
                                }
                                
                                $updateSql .= "updated_at = NOW() WHERE id = :deviceId";
                                
                                try {
                                    $stmt = $db->prepare($updateSql);
                                    $stmt->execute($updateParams);
                                    $logger->logToFile("Updated device record with parameters: " . json_encode($updateValues));
                                } catch (PDOException $e) {
                                    $logger->logToFile("Error updating device record: " . $e->getMessage());
                                }
                            }
                            
                            // Mark the get_parameters task as completed
                            try {
                                $stmt = $db->prepare("
                                    UPDATE device_tasks 
                                    SET status = 'completed', updated_at = NOW(), message = 'Parameters retrieved successfully' 
                                    WHERE device_id = :deviceId AND task_type = 'get_parameters' AND status = 'in_progress'
                                ");
                                $stmt->execute([':deviceId' => $deviceId]);
                                $logger->logToFile("Marked get_parameters task as completed");
                            } catch (PDOException $e) {
                                $logger->logToFile("Error updating task status: " . $e->getMessage());
                            }
                        }
                    }
                }
                
                // Send empty response to complete this transaction
                header('Content-Type: text/xml');
                echo XMLGenerator::generateEmptyResponse($soapId);
                exit;
            }
            
            // Handle empty POST or the next step in the session after Inform response
            if (empty(trim($raw_post)) || stripos($raw_post, '<cwmp:GetParameterValuesResponse>') !== false) {
                $currentTask = $sessionManager->getCurrentTask();
                
                // If we have a current task, handle it
                if ($currentTask) {
                    $logger->logToFile("Processing task: " . $currentTask['task_type']);
                    
                    if ($currentTask['task_type'] === 'get_parameters') {
                        // This is our special task to get essential parameters
                        $logger->logToFile("Handling get_parameters task");
                        
                        $taskData = json_decode($currentTask['task_data'], true);
                        if (isset($taskData['parameters']) && is_array($taskData['parameters'])) {
                            $parameterNames = $taskData['parameters'];
                            $logger->logToFile("Requesting parameters: " . implode(', ', $parameterNames));
                            
                            // Generate GetParameterValues request
                            $getParamRequest = XMLGenerator::generateGetParameterValuesXML(
                                uniqid(),
                                $parameterNames
                            );
                            
                            // Mark task as in progress
                            try {
                                $stmt = $db->prepare("
                                    UPDATE device_tasks 
                                    SET status = 'in_progress', updated_at = NOW() 
                                    WHERE id = :taskId
                                ");
                                $stmt->execute([':taskId' => $currentTask['id']]);
                                $logger->logToFile("Marked get_parameters task as in_progress");
                            } catch (PDOException $e) {
                                $logger->logToFile("Error updating task status: " . $e->getMessage());
                            }
                            
                            header('Content-Type: text/xml');
                            echo $getParamRequest;
                            exit;
                        }
                    } else {
                        // Handle other task types as before
                        $parameterRequest = $taskHandler->generateParameterValues(
                            $currentTask['task_type'],
                            $currentTask['task_data']
                        );
                        
                        if ($parameterRequest) {
                            header('Content-Type: text/xml');
                            echo XMLGenerator::generateSetParameterRequestXML(
                                uniqid(),
                                $parameterRequest['name'],
                                $parameterRequest['value'],
                                $parameterRequest['type']
                            );
                            exit;
                        }
                    }
                } else {
                    // Check if there are any pending get_parameters tasks
                    $serialNumber = $sessionManager->getCurrentSessionDeviceSerial();
                    if ($serialNumber) {
                        $logger->logToFile("No current task, checking for pending get_parameters tasks for: " . $serialNumber);
                        
                        try {
                            $stmt = $db->prepare("
                                SELECT dt.* FROM device_tasks dt
                                JOIN devices d ON dt.device_id = d.id
                                WHERE d.serial_number = :serial
                                AND dt.task_type = 'get_parameters'
                                AND dt.status = 'pending'
                                ORDER BY dt.created_at ASC
                                LIMIT 1
                            ");
                            $stmt->execute([':serial' => $serialNumber]);
                            $task = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($task) {
                                $logger->logToFile("Found pending get_parameters task: " . $task['id']);
                                $sessionManager->setCurrentTask($task);
                                
                                $taskData = json_decode($task['task_data'], true);
                                if (isset($taskData['parameters']) && is_array($taskData['parameters'])) {
                                    $parameterNames = $taskData['parameters'];
                                    $logger->logToFile("Requesting parameters: " . implode(', ', $parameterNames));
                                    
                                    // Generate GetParameterValues request
                                    $getParamRequest = XMLGenerator::generateGetParameterValuesXML(
                                        uniqid(),
                                        $parameterNames
                                    );
                                    
                                    // Mark task as in progress
                                    try {
                                        $stmt = $db->prepare("
                                            UPDATE device_tasks 
                                            SET status = 'in_progress', updated_at = NOW() 
                                            WHERE id = :taskId
                                        ");
                                        $stmt->execute([':taskId' => $task['id']]);
                                        $logger->logToFile("Marked get_parameters task as in_progress");
                                    } catch (PDOException $e) {
                                        $logger->logToFile("Error updating task status: " . $e->getMessage());
                                    }
                                    
                                    header('Content-Type: text/xml');
                                    echo $getParamRequest;
                                    exit;
                                }
                            } else {
                                $logger->logToFile("No pending get_parameters tasks found");
                            }
                        } catch (PDOException $e) {
                            $logger->logToFile("Error checking for pending tasks: " . $e->getMessage());
                        }
                    }
                }
            }
            
            // Default response for unhandled cases
            header('Content-Type: text/xml');
            echo XMLGenerator::generateEmptyResponse(uniqid());
            exit;
        }
    }
} catch (Exception $e) {
    $logger->logToFile("Exception: " . $e->getMessage());
    header('Content-Type: text/xml');
    echo XMLGenerator::generateEmptyResponse(uniqid());
}
