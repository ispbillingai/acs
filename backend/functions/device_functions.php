
<?php
function getDevices($db) {
    try {
        $sql = "SELECT 
                id,
                serial_number as serialNumber,
                manufacturer,
                model_name as model,
                status,
                last_contact as lastContact,
                ip_address as ipAddress,
                software_version as softwareVersion,
                hardware_version as hardwareVersion
                FROM devices 
                ORDER BY last_contact DESC";
        
        error_log("Executing SQL query: " . $sql);
        
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Found " . count($devices) . " devices");
        error_log("Devices data: " . print_r($devices, true));
        
        return $devices;
    } catch (PDOException $e) {
        error_log("Database error in getDevices: " . $e->getMessage());
        error_log("SQL State: " . $e->getCode());
        return [];
    }
}
