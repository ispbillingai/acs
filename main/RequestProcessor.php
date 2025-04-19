<?php

class RequestProcessor {
    private $db;
    private $responseGenerator;
    private $taskHandler;

    public function __construct($db, $responseGenerator, $taskHandler) {
        $this->db = $db;
        $this->responseGenerator = $responseGenerator;
        $this->taskHandler = $taskHandler;
    }

    public function processRequest($raw_post) {
        if (stripos($raw_post, '<cwmp:Inform>') !== false) {
            $this->handleInform($raw_post);
        } elseif (stripos($raw_post, '<cwmp:GetParameterValuesResponse>') !== false || 
                 (stripos($raw_post, 'ParameterList') !== false && 
                  stripos($raw_post, 'ParameterValueStruct') !== false)) {
            $this->handleGetParameterValuesResponse($raw_post);
        } elseif (stripos($raw_post, 'SetParameterValuesResponse') !== false || 
                 stripos($raw_post, '<Status>') !== false) {
            $this->handleSetParameterValuesResponse($raw_post);
        } elseif (empty(trim($raw_post)) || $raw_post === "\r\n") {
            $this->handleEmptyRequest($raw_post);
        } else {
            $this->sendDefaultResponse();
        }
    }

    private function handleInform($raw_post) {
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        preg_match('/<SerialNumber>(.*?)<\/SerialNumber>/s', $raw_post, $serialMatches);
        $serialNumber = isset($serialMatches[1]) ? trim($serialMatches[1]) : null;
        
        if ($serialNumber) {
            tr069_log("Device inform received - Serial: $serialNumber", "INFO");
            
            try {
                $stmt = $this->db->prepare("
                    INSERT INTO devices 
                        (serial_number, status, last_contact) 
                    VALUES 
                        (:serial, 'online', NOW()) 
                    ON DUPLICATE KEY UPDATE 
                        status = 'online', last_contact = NOW()
                ");
                $stmt->execute([':serial' => $serialNumber]);
                tr069_log("Updated device status to online - Serial: $serialNumber", "INFO");
            } catch (PDOException $e) {
                tr069_log("Database error updating device status: " . $e->getMessage(), "ERROR");
            }
            
            preg_match('/<ProductClass>(.*?)<\/ProductClass>/s', $raw_post, $modelMatches);
            $model = isset($modelMatches[1]) ? trim($modelMatches[1]) : null;
            
            preg_match('/<Manufacturer>(.*?)<\/Manufacturer>/s', $raw_post, $mfrMatches);
            $manufacturer = isset($mfrMatches[1]) ? trim($mfrMatches[1]) : null;
            
            if ($model || $manufacturer) {
                try {
                    $updateFields = [];
                    $params = [':serial' => $serialNumber];
                    
                    if ($model) {
                        $updateFields[] = "model_name = :model";
                        $params[':model'] = $model;
                    }
                    
                    if ($manufacturer) {
                        $updateFields[] = "manufacturer = :manufacturer";
                        $params[':manufacturer'] = $manufacturer;
                    }
                    
                    if (!empty($updateFields)) {
                        $sql = "UPDATE devices SET " . implode(", ", $updateFields) . " WHERE serial_number = :serial";
                        $stmt = $this->db->prepare($sql);
                        $stmt->execute($params);
                        tr069_log("Updated device details - Model: $model, Manufacturer: $manufacturer", "INFO");
                    }
                } catch (PDOException $e) {
                    tr069_log("Database error updating device details: " . $e->getMessage(), "ERROR");
                }
            }
            
            try {
                $idStmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
                $idStmt->execute([':serial' => $serialNumber]);
                $deviceId = $idStmt->fetchColumn();
                
                if ($deviceId) {
                    tr069_log("Found device ID: $deviceId for serial: $serialNumber", "INFO");
                    createPendingInfoTask($deviceId, $this->db);
                } else {
                    tr069_log("Device ID not found for serial: $serialNumber", "ERROR");
                }
            } catch (PDOException $e) {
                tr069_log("Database error finding device ID: " . $e->getMessage(), "ERROR");
            }
            
            $pendingTasks = $this->taskHandler->getPendingTasks($serialNumber);
            
            if (!empty($pendingTasks)) {
                $GLOBALS['current_task'] = $pendingTasks[0];
                tr069_log("Found pending task: " . $pendingTasks[0]['task_type'] . " - ID: " . $pendingTasks[0]['id'], "INFO");
                
                session_start();
                $_SESSION['current_task'] = $pendingTasks[0];
                $_SESSION['device_serial'] = $serialNumber;
                session_write_close();
            } else {
                tr069_log("No pending tasks for device ID: " . ($deviceId ?: 'unknown'), "INFO");
                
                try {
                    $deviceStmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial_number");
                    $deviceStmt->execute([':serial_number' => $serialNumber]);
                    $deviceId = $deviceStmt->fetchColumn();
                    
                    if ($deviceId) {
                        $inProgressStmt = $this->db->prepare("
                            SELECT * FROM device_tasks 
                            WHERE device_id = :device_id AND status = 'in_progress' 
                            ORDER BY updated_at DESC LIMIT 1
                        ");
                        $inProgressStmt->execute([':device_id' => $deviceId]);
                        $inProgressTask = $inProgressStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($inProgressTask) {
                            tr069_log("Found in-progress task: " . $inProgressTask['task_type'] . " - ID: " . $inProgressTask['id'], "INFO");
                            $taskTime = strtotime($inProgressTask['updated_at']);
                            $currentTime = time();
                            
                            if (($currentTime - $taskTime) > 300) {
                                $this->taskHandler->updateTaskStatus($inProgressTask['id'], 'completed', 'Auto-completed after timeout');
                                tr069_log("Auto-completed task ID: " . $inProgressTask['id'], "INFO");
                            } else {
                                session_start();
                                $_SESSION['in_progress_task'] = $inProgressTask;
                                $_SESSION['device_serial'] = $serialNumber;
                                session_write_close();
                            }
                        }
                    }
                } catch (PDOException $e) {
                    tr069_log("Database error checking in-progress tasks: " . $e->getMessage(), "ERROR");
                }
            }
        }
        
        $response = $this->responseGenerator->createResponse($soapId);
        tr069_log("Sending InformResponse", "DEBUG");
        
        header('Content-Type: text/xml');
        echo $response;
        exit;
    }

    private function handleGetParameterValuesResponse($raw_post) {
        tr069_log("Received GetParameterValuesResponse", "INFO");
        
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        session_start();
        $serialNumber = isset($_SESSION['device_serial']) ? $_SESSION['device_serial'] : null;
        $current_task = isset($_SESSION['current_task']) ? $_SESSION['current_task'] : null;
        session_write_close();
        
        if ($serialNumber && $current_task) {
            saveParameterValues($raw_post, $serialNumber, $this->db);
            
            $this->taskHandler->updateTaskStatus($current_task['id'], 'completed', 'Successfully retrieved device information');
            tr069_log("Task completed: " . $current_task['id'] . " (Type: {$current_task['task_type']})", "INFO");
            
            session_start();
            unset($_SESSION['current_task']);
            session_write_close();
        } else {
            tr069_log("No device serial or task found for GetParameterValuesResponse", "WARNING");
        }
        
        header('Content-Type: text/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body></soapenv:Body>
</soapenv:Envelope>';
        exit;
    }

    private function handleSetParameterValuesResponse($raw_post) {
        tr069_log("Received SetParameterValuesResponse", "INFO");
        
        preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
        $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        
        preg_match('/<Status>(.*?)<\/Status>/s', $raw_post, $statusMatches);
        $status = isset($statusMatches[1]) ? trim($statusMatches[1]) : '0';
        
        $current_task = null;
        if ($GLOBALS['current_task']) {
            $current_task = $GLOBALS['current_task'];
        } else {
            session_start();
            if (isset($_SESSION['current_task'])) {
                $current_task = $_SESSION['current_task'];
                unset($_SESSION['current_task']);
            } elseif (isset($_SESSION['in_progress_task'])) {
                $current_task = $_SESSION['in_progress_task'];
                unset($_SESSION['in_progress_task']);
            }
            $serialNumber = isset($_SESSION['device_serial']) ? $_SESSION['device_serial'] : null;
            session_write_close();
        }
        
        if ($current_task) {
            $taskStatus = ($status === '0') ? 'completed' : 'failed';
            $taskMessage = ($status === '0') ? 'Successfully applied ' . $current_task['task_type'] . ' configuration' : 'Device returned error status: ' . $status;
            $this->taskHandler->updateTaskStatus($current_task['id'], $taskStatus, $taskMessage);
            tr069_log("Task $taskStatus: " . $current_task['id'] . " - Status: $status", ($status === '0' ? "INFO" : "ERROR"));
            
            $GLOBALS['current_task'] = null;
        } else {
            if ($serialNumber) {
                try {
                    $deviceStmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
                    $deviceStmt->execute([':serial' => $serialNumber]);
                    $deviceId = $deviceStmt->fetchColumn();
                    
                    if ($deviceId) {
                        $taskStmt = $this->db->prepare("
                            SELECT * FROM device_tasks 
                            WHERE device_id = :device_id AND status = 'in_progress' 
                            ORDER BY updated_at DESC LIMIT 1
                        ");
                        $taskStmt->execute([':device_id' => $deviceId]);
                        $inProgressTask = $taskStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($inProgressTask) {
                            $taskStatus = ($status === '0') ? 'completed' : 'failed';
                            $taskMessage = ($status === '0') ? 'Successfully applied ' . $inProgressTask['task_type'] . ' configuration' : 'Device returned error status: ' . $status;
                            $this->taskHandler->updateTaskStatus($inProgressTask['id'], $taskStatus, $taskMessage);
                            tr069_log("Processed in-progress task: " . $inProgressTask['id'] . " - $taskStatus", ($status === '0' ? "INFO" : "ERROR"));
                        } else {
                            tr069_log("No in-progress tasks found for device ID: $deviceId", "WARNING");
                        }
                    }
                } catch (PDOException $e) {
                    tr069_log("Database error finding in-progress tasks: " . $e->getMessage(), "ERROR");
                }
            } else {
                tr069_log("No current task or serial number found for SetParameterValuesResponse", "WARNING");
            }
        }
        
        header('Content-Type: text/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body></soapenv:Body>
</soapenv:Envelope>';
        exit;
    }

    private function handleEmptyRequest($raw_post) {
        $soapId = '1';
        if (!empty($raw_post)) {
            preg_match('/<cwmp:ID [^>]*>(.*?)<\/cwmp:ID>/', $raw_post, $idMatches);
            $soapId = isset($idMatches[1]) ? $idMatches[1] : '1';
        }
        
        session_start();
        $current_task = null;
        $serialNumber = null;
        
        if ($GLOBALS['current_task']) {
            $current_task = $GLOBALS['current_task'];
        } elseif (isset($_SESSION['current_task'])) {
            $current_task = $_SESSION['current_task'];
            $serialNumber = isset($_SESSION['device_serial']) ? $_SESSION['device_serial'] : null;
        } elseif (isset($_SESSION['in_progress_task'])) {
            $inProgressTask = $_SESSION['in_progress_task'];
            $this->taskHandler->updateTaskStatus($inProgressTask['id'], 'completed', 'Auto-completed during session');
            tr069_log("Auto-completed in-progress task: " . $inProgressTask['id'], "INFO");
            unset($_SESSION['in_progress_task']);
            session_write_close();
            header('Content-Type: text/xml');
            echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body></soapenv:Body>
</soapenv:Envelope>';
            exit;
        }
        
        if ($current_task && $serialNumber) {
            tr069_log("Processing task from session: {$current_task['task_type']} - ID: {$current_task['id']}", "INFO");
            
            $taskData = json_decode($current_task['task_data'], true) ?: [];
            $group = $taskData['group'] ?? 'Unknown';
            $parameters = $taskData['parameters'] ?? [];
            
            if ($current_task['task_type'] === 'info' || $current_task['task_type'] === 'info_group') {
                if (!empty($parameters)) {
                    $nameXml = '';
                    $paramCount = count($parameters);
                    
                    foreach ($parameters as $param) {
                        $nameXml .= "        <string>" . htmlspecialchars($param) . "</string>\n";
                        tr069_log("Requesting parameter: $param (Group: $group)", "DEBUG");
                    }
                    
                    $getValuesRequest = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0" xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:GetParameterValues>
      <ParameterNames soap-enc:arrayType="xsd:string[' . $paramCount . ']">
' . $nameXml . '      </ParameterNames>
    </cwmp:GetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';

                    header('Content-Type: text/xml');
                    echo $getValuesRequest;
                    
                    $this->taskHandler->updateTaskStatus($current_task['id'], 'in_progress', "Sent GetParameterValues request (Group: $group)");
                    tr069_log("Task marked as in_progress: {$current_task['id']} (Group: $group)", "INFO");
                    
                    session_write_close();
                    exit;
                } else {
                    tr069_log("No parameters defined for task {$current_task['id']}", "ERROR");
                    $this->taskHandler->updateTaskStatus($current_task['id'], 'failed', 'No parameters defined');
                    unset($_SESSION['current_task']);
                    session_write_close();
                }
            } elseif ($current_task['task_type'] === 'wifi') {
                $parameterRequests = $this->taskHandler->generateParameterValues($current_task['task_type'], $current_task['task_data']);
                
                if ($parameterRequests) {
                    $requests = is_array($parameterRequests) && isset($parameterRequests[0]) && is_array($parameterRequests[0]) ? $parameterRequests : [$parameterRequests];
                    
                    if ($requests[0]['method'] === 'SetParameterValues') {
                        $paramXml = '';
                        $paramCount = count($requests[0]['parameters']);
                        
                        foreach ($requests[0]['parameters'] as $param) {
                            $paramXml .= "        <ParameterValueStruct>\n";
                            $paramXml .= "          <Name>" . htmlspecialchars($param['name']) . "</Name>\n";
                            $paramXml .= "          <Value xsi:type=\"" . $param['type'] . "\">" . htmlspecialchars($param['value']) . "</Value>\n";
                            $paramXml .= "        </ParameterValueStruct>\n";
                            tr069_log("Setting parameter: {$param['name']} = {$param['value']}", "DEBUG");
                        }
                        
                        $setParamRequest = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0" xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:SetParameterValues>
      <ParameterList soap-enc:arrayType="cwmp:ParameterValueStruct[' . $paramCount . ']">
' . $paramXml . '      </ParameterList>
      <ParameterKey>Task-' . $current_task['id'] . '-' . substr(md5(time()), 0, 8) . '</ParameterKey>
    </cwmp:SetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';

                        header('Content-Type: text/xml');
                        echo $setParamRequest;
                        
                        $this->taskHandler->updateTaskStatus($current_task['id'], 'in_progress', 'Sent parameters to device');
                        tr069_log("Task marked as in_progress: {$current_task['id']}", "INFO");
                        session_write_close();
                        exit;
                    }
                } else {
                    tr069_log("Failed to generate parameters for task {$current_task['id']}", "ERROR");
                    $this->taskHandler->updateTaskStatus($current_task['id'], 'failed', 'Failed to generate parameters');
                    unset($_SESSION['current_task']);
                    session_write_close();
                }
            } elseif ($current_task['task_type'] === 'reboot') {
                $parameterRequests = $this->taskHandler->generateParameterValues($current_task['task_type'], $current_task['task_data']);
                
                if ($parameterRequests && $parameterRequests[0]['method'] === 'Reboot') {
                    $rebootRequest = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:Reboot>
      <CommandKey>' . $parameterRequests[0]['commandKey'] . '</CommandKey>
    </cwmp:Reboot>
  </soapenv:Body>
</soapenv:Envelope>';

                    tr069_log("Sending reboot command with key: " . $parameterRequests[0]['commandKey'], "INFO");
                    
                    header('Content-Type: text/xml');
                    header('Connection: close');
                    header('Content-Length: ' . strlen($rebootRequest));
                    echo $rebootRequest;
                    flush();
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    
                    $this->taskHandler->updateTaskStatus($current_task['id'], 'in_progress', 'Sent reboot command to device');
                    tr069_log("Task marked as in_progress: {$current_task['id']}", "INFO");
                    session_write_close();
                    exit;
                }
            } elseif ($current_task['task_type'] === 'huawei_reboot') {
                $parameterRequests = $this->taskHandler->generateParameterValues($current_task['task_type'], $current_task['task_data']);
                
                if ($parameterRequests && $parameterRequests[0]['method'] === 'X_HW_DelayReboot') {
                    $rebootRequest = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:X_HW_DelayReboot>
      <CommandKey>' . $parameterRequests[0]['commandKey'] . '</CommandKey>
      <DelaySeconds>' . $parameterRequests[0]['delay'] . '</DelaySeconds>
    </cwmp:X_HW_DelayReboot>
  </soapenv:Body>
</soapenv:Envelope>';

                    tr069_log("Sending Huawei vendor reboot command with key: " . $parameterRequests[0]['commandKey'], "INFO");
                    
                    header('Content-Type: text/xml');
                    header('Connection: close');
                    header('Content-Length: ' . strlen($rebootRequest));
                    echo $rebootRequest;
                    flush();
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    
                    $this->taskHandler->updateTaskStatus($current_task['id'], 'in_progress', 'Sent vendor reboot command to device');
                    tr069_log("Task marked as in_progress: {$current_task['id']}", "INFO");
                    session_write_close();
                    exit;
                }
            }
        }
        
        session_write_close();
        header('Content-Type: text/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . $soapId . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body></soapenv:Body>
</soapenv:Envelope>';
        exit;
    }

    private function sendDefaultResponse() {
        header('Content-Type: text/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">1</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body></soapenv:Body>
</soapenv:Envelope>';
        exit;
    }
}

?>