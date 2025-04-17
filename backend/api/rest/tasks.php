
<?php
// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set headers for REST API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include database connection
require_once __DIR__ . '/../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Log function for debugging
function writeLog($message) {
    $logFile = $_SERVER['DOCUMENT_ROOT'] . '/rest_api.log';
    $timestamp = date('Y-m-d H:i:s');
    
    // Make sure the log is writable
    if (!file_exists($logFile)) {
        touch($logFile);
        chmod($logFile, 0666);
    }
    
    // Log to file
    file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

writeLog("REST API Tasks Request: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);

// Parse JSON input for POST/PUT requests
$inputData = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
    $inputJSON = file_get_contents('php://input');
    $inputData = json_decode($inputJSON, true);
    
    if ($inputData === null && json_last_error() !== JSON_ERROR_NONE) {
        writeLog("JSON Parse Error: " . json_last_error_msg());
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON data provided', 'details' => json_last_error_msg()]);
        exit;
    }
    
    writeLog("Input data: " . print_r($inputData, true));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get tasks list or specific task
    $taskId = $_GET['id'] ?? null;
    $deviceId = $_GET['device_id'] ?? null;
    $status = $_GET['status'] ?? null;
    
    try {
        if ($taskId) {
            // Get specific task by ID
            $stmt = $db->prepare("
                SELECT 
                    t.*,
                    d.serial_number as device_serial
                FROM device_tasks t
                JOIN devices d ON t.device_id = d.id
                WHERE t.id = :id
            ");
            $stmt->execute([':id' => $taskId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$task) {
                http_response_code(404);
                echo json_encode(['error' => 'Task not found']);
                exit;
            }
            
            // Parse task data if it's JSON
            if ($task['task_data'] && isJson($task['task_data'])) {
                $task['task_data'] = json_decode($task['task_data'], true);
            }
            
            echo json_encode([
                'success' => true,
                'task' => $task
            ]);
        } 
        else if ($deviceId) {
            // Get tasks for specific device
            $query = "
                SELECT 
                    t.*,
                    d.serial_number as device_serial
                FROM device_tasks t
                JOIN devices d ON t.device_id = d.id
                WHERE t.device_id = :device_id
            ";
            
            $params = [':device_id' => $deviceId];
            
            // Add status filter if provided
            if ($status) {
                $query .= " AND t.status = :status";
                $params[':status'] = $status;
            }
            
            $query .= " ORDER BY t.created_at DESC";
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse task data if it's JSON
            foreach ($tasks as &$task) {
                if ($task['task_data'] && isJson($task['task_data'])) {
                    $task['task_data'] = json_decode($task['task_data'], true);
                }
            }
            
            echo json_encode([
                'success' => true,
                'device_id' => $deviceId,
                'tasks' => $tasks
            ]);
        } 
        else {
            // Get all tasks with pagination
            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
            $offset = ($page - 1) * $limit;
            
            $query = "
                SELECT 
                    t.*,
                    d.serial_number as device_serial
                FROM device_tasks t
                JOIN devices d ON t.device_id = d.id
            ";
            
            $params = [];
            
            // Add status filter if provided
            if ($status) {
                $query .= " WHERE t.status = :status";
                $params[':status'] = $status;
            }
            
            $query .= " ORDER BY t.created_at DESC LIMIT :limit OFFSET :offset";
            
            $stmt = $db->prepare($query);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            if ($status) {
                $stmt->bindValue(':status', $status);
            }
            
            $stmt->execute();
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count for pagination
            $countQuery = "SELECT COUNT(*) FROM device_tasks";
            if ($status) {
                $countQuery .= " WHERE status = :status";
                $countStmt = $db->prepare($countQuery);
                $countStmt->execute([':status' => $status]);
            } else {
                $countStmt = $db->prepare($countQuery);
                $countStmt->execute();
            }
            $totalCount = $countStmt->fetchColumn();
            $totalPages = ceil($totalCount / $limit);
            
            // Parse task data if it's JSON
            foreach ($tasks as &$task) {
                if ($task['task_data'] && isJson($task['task_data'])) {
                    $task['task_data'] = json_decode($task['task_data'], true);
                }
            }
            
            echo json_encode([
                'success' => true,
                'tasks' => $tasks,
                'pagination' => [
                    'total' => $totalCount,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => $totalPages
                ]
            ]);
        }
    } catch (PDOException $e) {
        writeLog("Database error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error', 'details' => $e->getMessage()]);
    }
} 
else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update task status
    if (empty($inputData)) {
        http_response_code(400);
        echo json_encode(['error' => 'No data provided']);
        exit;
    }
    
    $taskId = $inputData['task_id'] ?? null;
    $action = $inputData['action'] ?? null;
    
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['error' => 'Task ID is required']);
        exit;
    }
    
    try {
        // Check if task exists
        $stmt = $db->prepare("SELECT * FROM device_tasks WHERE id = :id");
        $stmt->execute([':id' => $taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$task) {
            http_response_code(404);
            echo json_encode(['error' => 'Task not found']);
            exit;
        }
        
        switch ($action) {
            case 'cancel':
                // Cancel a pending task
                if ($task['status'] !== 'pending') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Only pending tasks can be canceled']);
                    exit;
                }
                
                $updateStmt = $db->prepare("
                    UPDATE device_tasks 
                    SET status = 'canceled', message = 'Manually canceled', updated_at = NOW()
                    WHERE id = :id
                ");
                $updateStmt->execute([':id' => $taskId]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Task canceled successfully'
                ]);
                break;
                
            case 'retry':
                // Retry a failed task
                if (!in_array($task['status'], ['failed', 'canceled'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Only failed or canceled tasks can be retried']);
                    exit;
                }
                
                $updateStmt = $db->prepare("
                    UPDATE device_tasks 
                    SET status = 'pending', message = 'Retrying task', updated_at = NOW()
                    WHERE id = :id
                ");
                $updateStmt->execute([':id' => $taskId]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Task queued for retry'
                ]);
                break;
                
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unsupported action: ' . $action]);
                break;
        }
    } catch (PDOException $e) {
        writeLog("Database error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error', 'details' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

// Helper function to check if a string is valid JSON
function isJson($string) {
    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}
