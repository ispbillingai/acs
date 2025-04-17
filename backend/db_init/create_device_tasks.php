
<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Create device_tasks table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS device_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id INT NOT NULL,
        task_type VARCHAR(50) NOT NULL COMMENT 'wifi, wan, reboot, etc.',
        task_data TEXT NOT NULL COMMENT 'JSON encoded parameters',
        status ENUM('pending', 'in_progress', 'completed', 'failed') NOT NULL DEFAULT 'pending',
        message TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB";
    
    $db->exec($sql);
    
    echo "Device tasks table created or already exists.";
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage();
}
