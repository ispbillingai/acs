
<?php
class DeviceManager {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function updateDevice($deviceInfo) {
        $sql = "INSERT INTO devices 
                (serial_number, oui, manufacturer, model_name, last_contact, status) 
                VALUES (:serial, :oui, :manufacturer, :model, NOW(), 'online') 
                ON DUPLICATE KEY UPDATE 
                last_contact = NOW(),
                status = 'online'";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':serial' => $deviceInfo['serialNumber'],
            ':oui' => $deviceInfo['oui'],
            ':manufacturer' => $deviceInfo['manufacturer'],
            ':model' => $deviceInfo['productClass']
        ]);

        return $this->db->lastInsertId();
    }

    public function updateParameter($deviceId, $name, $value, $type) {
        $sql = "INSERT INTO parameters 
                (device_id, param_name, param_value, param_type) 
                VALUES (:device_id, :name, :value, :type) 
                ON DUPLICATE KEY UPDATE 
                param_value = :value,
                param_type = :type";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':device_id' => $deviceId,
            ':name' => $name,
            ':value' => $value,
            ':type' => $type
        ]);
    }

    public function getDevice($serialNumber) {
        $sql = "SELECT * FROM devices WHERE serial_number = :serial";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':serial' => $serialNumber]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
