
<?php
// Database initialization script for REST API
header('Content-Type: application/json');

// Include database connection
require_once __DIR__ . '/../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Check connection
if (!$db) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Create device_tasks table if it doesn't exist
try {
    $createTasksTable = "CREATE TABLE IF NOT EXISTS device_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id INT NOT NULL,
        task_type VARCHAR(50) NOT NULL COMMENT 'wifi, wan, reboot, etc.',
        task_data TEXT NOT NULL COMMENT 'JSON encoded parameters',
        status ENUM('pending', 'in_progress', 'completed', 'failed', 'canceled') NOT NULL DEFAULT 'pending',
        message TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB";
    
    $db->exec($createTasksTable);
    
    // Create device_parameters table if it doesn't exist
    $createParametersTable = "CREATE TABLE IF NOT EXISTS device_parameters (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id INT NOT NULL,
        param_name VARCHAR(255) NOT NULL,
        param_value TEXT,
        param_type VARCHAR(50) DEFAULT 'string',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY device_param_idx (device_id, param_name),
        FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB";
    
    $db->exec($createParametersTable);
    
    // Success
    echo json_encode([
        'success' => true,
        'message' => 'Database initialization completed successfully'
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'error' => 'Database initialization failed',
        'details' => $e->getMessage()
    ]);
}
