
<?php
class DeviceManager {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function updateDevice($deviceInfo) {
        try {
            $sql = "INSERT INTO devices 
                    (serial_number, oui, manufacturer, model_name, software_version, 
                    hardware_version, last_contact, status) 
                    VALUES (:serial, :oui, :manufacturer, :model, :sw_ver, 
                    :hw_ver, NOW(), 'online') 
                    ON DUPLICATE KEY UPDATE 
                    last_contact = NOW(),
                    status = 'online',
                    software_version = :sw_ver,
                    hardware_version = :hw_ver";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':serial' => $deviceInfo['serialNumber'],
                ':oui' => $deviceInfo['oui'],
                ':manufacturer' => $deviceInfo['manufacturer'],
                ':model' => $deviceInfo['productClass'],
                ':sw_ver' => $deviceInfo['softwareVersion'],
                ':hw_ver' => $deviceInfo['hardwareVersion']
            ]);

            // Get device ID
            if ($this->db->lastInsertId()) {
                return $this->db->lastInsertId();
            } else {
                $stmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
                $stmt->execute([':serial' => $deviceInfo['serialNumber']]);
                return $stmt->fetchColumn();
            }
        } catch (PDOException $e) {
            error_log("Database error in updateDevice: " . $e->getMessage());
            throw $e;
        }
    }

    public function updateParameter($deviceId, $name, $value, $type) {
        try {
            $sql = "INSERT INTO parameters 
                    (device_id, param_name, param_value, param_type, updated_at) 
                    VALUES (:device_id, :name, :value, :type, NOW()) 
                    ON DUPLICATE KEY UPDATE 
                    param_value = :value,
                    param_type = :type,
                    updated_at = NOW()";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':device_id' => $deviceId,
                ':name' => $name,
                ':value' => $value,
                ':type' => $type
            ]);
        } catch (PDOException $e) {
            error_log("Database error in updateParameter: " . $e->getMessage());
            throw $e;
        }
    }

    public function logEvent($serialNumber, $eventCode) {
        try {
            // Get device ID first
            $stmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
            $stmt->execute([':serial' => $serialNumber]);
            $deviceId = $stmt->fetchColumn();

            if ($deviceId) {
                $sql = "INSERT INTO events (device_id, event_code, created_at) 
                        VALUES (:device_id, :event_code, NOW())";
                
                $stmt = $this->db->prepare($sql);
                return $stmt->execute([
                    ':device_id' => $deviceId,
                    ':event_code' => $eventCode
                ]);
            }
            return false;
        } catch (PDOException $e) {
            error_log("Database error in logEvent: " . $e->getMessage());
            throw $e;
        }
    }

    public function getDevice($serialNumber) {
        try {
            $sql = "SELECT * FROM devices WHERE serial_number = :serial";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':serial' => $serialNumber]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Database error in getDevice: " . $e->getMessage());
            throw $e;
        }
    }

    public function getDeviceParameters($deviceId) {
        try {
            $sql = "SELECT * FROM parameters WHERE device_id = :device_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':device_id' => $deviceId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Database error in getDeviceParameters: " . $e->getMessage());
            throw $e;
        }
    }
}
