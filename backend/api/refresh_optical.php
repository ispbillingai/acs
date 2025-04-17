
<?php
header('Content-Type: application/json');

// Include database configuration
require_once '../config/database.php';

// Initialize response
$response = [
    'success' => false,
    'message' => 'Unknown error occurred'
];

try {
    // Verify device ID was provided
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        $response['message'] = 'Device ID is required';
        echo json_encode($response);
        exit;
    }
    
    $deviceId = $_GET['id'];
    
    // Connect to database
    $database = new Database();
    $db = $database->getConnection();
    
    // Get device information to verify it exists
    $stmt = $db->prepare("SELECT * FROM devices WHERE id = :id");
    $stmt->execute([':id' => $deviceId]);
    $device = $stmt->fetch(\PDO::FETCH_ASSOC);
    
    if (!$device) {
        $response['message'] = 'Device not found';
        echo json_encode($response);
        exit;
    }
    
    // Removed logging line: file_put_contents()
    
    // Look up in parameters table if we already have optical readings
    $stmt = $db->prepare("SELECT param_name, param_value FROM parameters 
                         WHERE device_id = :deviceId 
                         AND (param_name LIKE '%TXPower%' OR param_name LIKE '%RXPower%')");
    $stmt->execute([':deviceId' => $deviceId]);
    $opticalParams = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
    // If no optical readings exist, generate placeholders
    if (empty($opticalParams)) {
        // Insert placeholder values to show in UI - using the specific GPON parameters for HG8145V5
        $paramTypes = [
            'InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.TXPower' => 'N/A (Pending)',
            'InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.RXPower' => 'N/A (Pending)',
            'InternetGatewayDevice.WANDevice.1.X_EponInterfaceConfig.TXPower' => 'N/A (Pending)',
            'InternetGatewayDevice.WANDevice.1.X_EponInterfaceConfig.RXPower' => 'N/A (Pending)',
            'InternetGatewayDevice.Device.Optical.Interface.1.CurrentTXPower' => 'N/A (Pending)',
            'InternetGatewayDevice.Device.Optical.Interface.1.CurrentRXPower' => 'N/A (Pending)'
        ];
        
        foreach ($paramTypes as $paramName => $paramValue) {
            $stmt = $db->prepare("INSERT INTO parameters (device_id, param_name, param_value, param_type) 
                                VALUES (:deviceId, :paramName, :paramValue, 'string')
                                ON DUPLICATE KEY UPDATE param_value = :paramValue");
            $stmt->execute([
                ':deviceId' => $deviceId,
                ':paramName' => $paramName,
                ':paramValue' => $paramValue
            ]);
        }
        
        // Removed logging line: file_put_contents()
    }
    
    // Success response
    $response['success'] = true;
    $response['message'] = 'Optical readings refresh initiated';
    $response['deviceId'] = $deviceId;
    
    echo json_encode($response);
    
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    echo json_encode($response);
    
    // Removed logging line: file_put_contents()
}
