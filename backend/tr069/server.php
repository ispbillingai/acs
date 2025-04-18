
<?php
require_once __DIR__ . '/tasks/utils/CommitHelper.php';

class TR069Server
{
    private $db;
    private $deviceId;
    private $serialNumber;
    private $logger;
    private $isHuawei = false;
    private $modelHint = null;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->logger = $this; // Use TR069Server itself as the logger
    }

    public function setDeviceId($deviceId)
    {
        $this->deviceId = $deviceId;
    }

    public function setSerialNumber($serialNumber)
    {
        $this->serialNumber = $serialNumber;
    }

    public function setHuaweiDetection($isHuawei)
    {
        $this->isHuawei = $isHuawei;
    }

    public function setModelHint($modelHint)
    {
        $this->modelHint = $modelHint;
    }

    public function logToFile($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [TR-069] {$message}" . PHP_EOL;

        // Log to Apache error log as backup
        error_log("[TR-069] {$message}", 0);

        // Log to dedicated device.log file
        $logFile = $_SERVER['DOCUMENT_ROOT'] . '/device.log';
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }

    private function parseSoapRequest()
    {
        $rawPost = file_get_contents('php://input');
        $xml = simplexml_load_string($rawPost);
        $namespaces = $xml->getNamespaces(true);
        $cwmp = $xml->children($namespaces['cwmp']);

        $method = (string) $cwmp->getName();
        $params = [];

        if ($method === 'Inform') {
            $params['DeviceId'] = [
                'Manufacturer' => (string) $cwmp->DeviceId->Manufacturer,
                'OUI'          => (string) $cwmp->DeviceId->OUI,
                'ProductClass' => (string) $cwmp->DeviceId->ProductClass,
                'SerialNumber' => (string) $cwmp->DeviceId->SerialNumber,
            ];

            foreach ($cwmp->ParameterList->ParameterValueStruct as $param) {
                $name  = (string) $param->Name;
                $value = (string) $param->Value;
                $params['ParameterList'][$name] = $value;
            }
        } elseif ($method === 'GetParameterValuesResponse') {
            foreach ($cwmp->ParameterList->ParameterValueStruct as $param) {
                $name  = (string) $param->Name;
                $value = (string) $param->Value;
                $params[$name] = $value;
            }
        }

        return [
            'method' => $method,
            'params' => $params,
            'raw'    => $rawPost,
        ];
    }

    private function buildSoapEnvelope($method, $params = [])
    {
        $messageId = uniqid();
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <soapenv:Header>
        <cwmp:ID soapenv:mustUnderstand="1">' . $messageId . '</cwmp:ID>
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

    public function sendSoap($soap)
    {
        header('Content-Type: text/xml');
        echo $soap;
        exit;
    }

    public function sendGetParameterValues($parameters)
    {
        $arraySize = count($parameters);
        $parameterStrings = '';

        foreach ($parameters as $param) {
            $parameterStrings .= "        <string>" . htmlspecialchars($param) . "</string>\n";
        }

        $soap = '<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
  <SOAP-ENV:Header>
    <cwmp:ID SOAP-ENV:mustUnderstand="1">' . uniqid() . '</cwmp:ID>
  </SOAP-ENV:Header>
  <SOAP-ENV:Body>
    <cwmp:GetParameterValues>
      <ParameterNames SOAP-ENC:arrayType="xsd:string[' . $arraySize . ']">
' . $parameterStrings . '      </ParameterNames>
    </cwmp:GetParameterValues>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

        $this->sendSoap($soap);
    }

    public function sendSetParameterValues($parameters)
    {
        $paramXml = '';
        $paramCount = count($parameters);

        foreach ($parameters as $param) {
            $paramXml .= "        <ParameterValueStruct>\n";
            $paramXml .= "          <Name>" . htmlspecialchars($param['name']) . "</Name>\n";
            $paramXml .= "          <Value xsi:type=\"" . $param['type'] . "\">" . htmlspecialchars($param['value']) . "</Value>\n";
            $paramXml .= "        </ParameterValueStruct>\n";
        }

        $soap = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
    xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/">
  <soapenv:Header>
    <cwmp:ID soapenv:mustUnderstand="1">' . uniqid() . '</cwmp:ID>
  </soapenv:Header>
  <soapenv:Body>
    <cwmp:SetParameterValues>
      <ParameterList soap-enc:arrayType="cwmp:ParameterValueStruct[' . $paramCount . ']">
' . $paramXml . '      </ParameterList>
      <ParameterKey>setParameterValues-' . substr(md5(time()), 0, 10) . '</ParameterKey>
    </cwmp:SetParameterValues>
  </soapenv:Body>
</soapenv:Envelope>';

        $this->sendSoap($soap);
    }

    public function handleRequest()
    {
        $request = $this->parseSoapRequest();
        $this->logToFile('Received method: ' . $request['method']);

        if ($request['method'] === 'Inform') {
            $this->logToFile('Inform received from device.');

            // Extract serial number from Inform request
            $this->serialNumber = $request['params']['DeviceId']['SerialNumber'];

            // Find device in database by serial number
            $stmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial_number");
            $stmt->execute([':serial_number' => $this->serialNumber]);
            $deviceId = $stmt->fetchColumn();

            if ($deviceId) {
                $this->deviceId = $deviceId;
                $this->logToFile("Device found in database with ID: " . $this->deviceId);
                
                // Debug: Add extra logging to trace execution path
                $this->logToFile("About to create pending info task for device ID: " . $this->deviceId);
                
                // Create a pending info task for this device
                $this->createPendingInfoTask($this->deviceId);
            } else {
                $this->logToFile("Device with serial number {$this->serialNumber} not found in database.");
                // Optionally, create a new device entry here
            }

            $informResponseParams = [];
            $soapResponse = $this->buildSoapEnvelope('InformResponse', $informResponseParams);
            $this->sendSoap($soapResponse);
        }

        // Handle tasks based on deviceId
        if ($this->deviceId) {
            $this->processTasks();
        } else {
            $this->logToFile('No device ID available, skipping task processing.');
        }
    }
    
    private function createPendingInfoTask($deviceId) 
    {
        try {
            $this->logToFile("TASK CREATION: Starting task creation for device ID: " . $deviceId);
            
            // Check if there are any pending tasks for this device
            $checkStmt = $this->db->prepare("
                SELECT COUNT(*) FROM device_tasks 
                WHERE device_id = :device_id AND status = 'pending'
            ");
            $checkStmt->execute([':device_id' => $deviceId]);
            $pendingCount = $checkStmt->fetchColumn();
            
            $this->logToFile("TASK CREATION: Found {$pendingCount} existing pending tasks for device ID: {$deviceId}");
            
            // Always create a new task regardless of existing pending tasks
            // Default task data for info task
            $taskData = json_encode(['names' => []]);
            
            // Insert the task
            $insertStmt = $this->db->prepare("
                INSERT INTO device_tasks 
                    (device_id, task_type, task_data, status, message, created_at, updated_at) 
                VALUES 
                    (:device_id, 'info', :task_data, 'pending', 'Auto-created on device connection', NOW(), NOW())
            ");
            
            $insertResult = $insertStmt->execute([
                ':device_id' => $deviceId,
                ':task_data' => $taskData
            ]);
            
            if ($insertResult) {
                $taskId = $this->db->lastInsertId();
                $this->logToFile("TASK CREATION: Successfully created pending info task with ID: {$taskId}");
                
                // Debug - Double check if task was actually created
                $verifyStmt = $this->db->prepare("SELECT * FROM device_tasks WHERE id = :id");
                $verifyStmt->execute([':id' => $taskId]);
                $taskExists = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($taskExists) {
                    $this->logToFile("TASK CREATION: Verified task exists in database with ID: {$taskId}");
                } else {
                    $this->logToFile("TASK CREATION ERROR: Task not found in database after creation!");
                }
            } else {
                $this->logToFile("TASK CREATION ERROR: Failed to create pending info task. Database error.");
                
                // More detailed error logging
                $errorInfo = $insertStmt->errorInfo();
                $this->logToFile("TASK CREATION ERROR: " . print_r($errorInfo, true));
            }
        } catch (PDOException $e) {
            $this->logToFile("TASK CREATION ERROR: Exception while creating pending task: " . $e->getMessage());
            $this->logToFile("TASK CREATION ERROR: Stack trace: " . $e->getTraceAsString());
        }
    }

    private function processTasks()
    {
        // Fetch pending tasks for the device
        $stmt = $this->db->prepare("
            SELECT dt.* FROM device_tasks dt
            WHERE dt.device_id = :device_id AND dt.status = 'pending'
            ORDER BY dt.created_at ASC
            LIMIT 1
        ");
        $stmt->execute([':device_id' => $this->deviceId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($task) {
            $this->logToFile("Processing task ID: " . $task['id'] . ", Type: " . $task['task_type']);

            // Update task status to 'in_progress'
            $updateStmt = $this->db->prepare("UPDATE device_tasks SET status = 'in_progress' WHERE id = :id");
            $updateStmt->execute([':id' => $task['id']]);

            $taskData = json_decode($task['task_data'], true);

            if ($task['task_type'] === 'wifi') {
                $this->handleWifiTask($task, $taskData);
            } elseif ($task['task_type'] === 'reboot') {
                $this->handleRebootTask($task, $taskData);
            } else {
                $this->logToFile("Unsupported task type: " . $task['task_type']);
                $this->completeTask($task['id'], 'failed', 'Unsupported task type');
            }
        } else {
            $this->logToFile('No pending tasks found for device.');
        }
    }

    private function handleWifiTask($task, $taskData)
    {
        $this->logToFile("Handling WiFi task ID: " . $task['id']);

        // Build ParameterValueStruct array
        $params = [];
        $instance24 = $taskData['instance_24g'] ?? 1;
        $instance5 = $taskData['instance_5g'] ?? null;
        $ssid = $taskData['ssid'] ?? null;
        $password = $taskData['password'] ?? null;

        if (!$ssid) {
            $this->logToFile('SSID missing in task data.');
            $this->completeTask($task['id'], 'failed', 'SSID missing in task data');
            return;
        }

        $params[] = [
            'name'  => "InternetGatewayDevice.LANDevice.1.WLANConfiguration.$instance24.SSID",
            'value' => $ssid,
            'type'  => 'xsd:string',
        ];

        $params[] = [
            'name'  => "InternetGatewayDevice.LANDevice.1.WLANConfiguration.$instance24.Enable",
            'value' => 'true',
            'type'  => 'xsd:boolean',
        ];

        if ($password) {
            $params[] = [
                'name'  => "InternetGatewayDevice.LANDevice.1.WLANConfiguration.$instance24.PreSharedKey.1.PreSharedKey",
                'value' => $password,
                'type'  => 'xsd:string',
            ];
        }

        if ($instance5) {
            $params[] = [
                'name'  => "InternetGatewayDevice.LANDevice.1.WLANConfiguration.$instance5.SSID",
                'value' => $ssid,
                'type'  => 'xsd:string',
            ];

            $params[] = [
                'name'  => "InternetGatewayDevice.LANDevice.1.WLANConfiguration.$instance5.Enable",
                'value' => 'true',
                'type'  => 'xsd:boolean',
            ];

            if ($password) {
                $params[] = [
                    'name'  => "InternetGatewayDevice.LANDevice.1.WLANConfiguration.$instance5.PreSharedKey.1.PreSharedKey",
                    'value' => $password,
                    'type'  => 'xsd:string',
                ];
            }
        }

        if ($task['method'] === 'SetParameterValues+Commit') {
            $this->sendSetParameterValues($params);
            
            // Initialize commit helper and send commit
            $commitHelper = new CommitHelper($this->logger);
            $commitHelper->sendCommit($this);
            
            $this->logToFile("Sent SetParameterValues with Commit for task: " . $task['id']);
        }

        $this->completeTask($task['id'], 'completed', 'WiFi configuration applied');
    }

    private function handleRebootTask($task, $taskData)
    {
        $this->logToFile("Handling Reboot task ID: " . $task['id']);

        $commandKey = 'reboot-' . date('Ymd-His');
        $rebootParams = ['CommandKey' => $commandKey];

        $soap = $this->buildSoapEnvelope('Reboot', $rebootParams);
        $this->sendSoap($soap);

        $this->completeTask($task['id'], 'completed', 'Reboot command sent');
    }

    private function completeTask($taskId, $status, $message)
    {
        $stmt = $this->db->prepare("UPDATE device_tasks SET status = :status, message = :message WHERE id = :id");
        $stmt->execute([':status' => $status, ':message' => $message, ':id' => $taskId]);

        $this->logToFile("Task ID: " . $taskId . " completed with status: " . $status . ", Message: " . $message);
    }
}
