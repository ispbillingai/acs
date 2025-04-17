
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
        
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $devices;
    } catch (PDOException $e) {
        return [];
    }
}
