
<?php
require_once __DIR__ . '/../backend/api/tr069_data_logger.php';

// Process router data and ensure it's properly stored in the database
function processRouterData($data, $serialNumber = null) {
    logTR069Data("Starting to process router data" . ($serialNumber ? " for device: {$serialNumber}" : ""));
    
    try {
        require_once __DIR__ . '/../backend/config/database.php';
        $database = new Database();
        $db = $database->getConnection();
        
        if (!$db) {
            logTR069Data("Database connection failed", "ERROR");
            return [
                'success' => false,
                'error' => 'Database connection failed'
            ];
        }
        
        // If we have a serial number, find the device
        $deviceId = null;
        
        if ($serialNumber) {
            $stmt = $db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
            $stmt->execute([':serial' => $serialNumber]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($device) {
                $deviceId = $device['id'];
                logTR069Data("Found device ID: {$deviceId} for serial: {$serialNumber}");
            } else {
                logTR069Data("No device found with serial number: {$serialNumber}", "WARNING");
            }
        }
        
        // Process parameters
        $processedData = [];
        $paramCount = 0;
        
        // Parse raw parameters from the data
        if (isset($data['raw_parameters']) && is_array($data['raw_parameters'])) {
            foreach ($data['raw_parameters'] as $param) {
                if (isset($param['name']) && isset($param['value'])) {
                    $processedData[$param['name']] = $param['value'];
                    $paramCount++;
                    
                    // Store in database if we have a device ID
                    if ($deviceId) {
                        // Check if parameter exists
                        $checkStmt = $db->prepare("SELECT id FROM parameters WHERE device_id = :device_id AND param_name = :name");
                        $checkStmt->execute([
                            ':device_id' => $deviceId,
                            ':name' => $param['name']
                        ]);
                        $existingParam = $checkStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($existingParam) {
                            // Update parameter
                            $updateStmt = $db->prepare("UPDATE parameters SET param_value = :value, updated_at = NOW() WHERE id = :id");
                            $result = $updateStmt->execute([
                                ':value' => $param['value'],
                                ':id' => $existingParam['id']
                            ]);
                            
                            if ($result) {
                                logDatabaseOperation("UPDATE PARAMETER", "{$param['name']} = {$param['value']}", true);
                            } else {
                                logDatabaseOperation("UPDATE PARAMETER", "{$param['name']} = {$param['value']}", false);
                            }
                        } else {
                            // Insert new parameter
                            $insertStmt = $db->prepare("INSERT INTO parameters (device_id, param_name, param_value, param_type) VALUES (:device_id, :name, :value, :type)");
                            $result = $insertStmt->execute([
                                ':device_id' => $deviceId,
                                ':name' => $param['name'],
                                ':value' => $param['value'],
                                ':type' => 'string'
                            ]);
                            
                            if ($result) {
                                logDatabaseOperation("INSERT PARAMETER", "{$param['name']} = {$param['value']}", true);
                            } else {
                                logDatabaseOperation("INSERT PARAMETER", "{$param['name']} = {$param['value']}", false);
                            }
                        }
                    }
                }
            }
        }
        
        // Update device last contact time
        if ($deviceId) {
            $updateStmt = $db->prepare("UPDATE devices SET last_contact = NOW(), status = 'online' WHERE id = :id");
            $result = $updateStmt->execute([':id' => $deviceId]);
            
            if ($result) {
                logDatabaseOperation("UPDATE DEVICE", "Set last_contact to NOW() and status to online for device ID: {$deviceId}", true);
            } else {
                logDatabaseOperation("UPDATE DEVICE", "Failed to update last_contact for device ID: {$deviceId}", false);
            }
        }
        
        logTR069Data("Processed {$paramCount} parameters for router data");
        
        return [
            'success' => true,
            'message' => "Processed {$paramCount} parameters",
            'device_id' => $deviceId,
            'parameters_processed' => $paramCount
        ];
        
    } catch (Exception $e) {
        logTR069Data("Error processing router data: " . $e->getMessage(), "ERROR");
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Helper function to extract serial number from parameters
function extractSerialNumber($data) {
    if (isset($data['raw_parameters']) && is_array($data['raw_parameters'])) {
        foreach ($data['raw_parameters'] as $param) {
            if (isset($param['name']) && isset($param['value'])) {
                if (stripos($param['name'], 'SerialNumber') !== false) {
                    return $param['value'];
                }
            }
        }
    }
    
    return null;
}
