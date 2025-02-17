
<?php
class DeviceManager {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function updateDevice($deviceInfo) {
        try {
            // First check if device exists
            $stmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
            $stmt->execute([':serial' => $deviceInfo['serialNumber']]);
            $existingId = $stmt->fetchColumn();

            if ($existingId) {
                // Update existing device
                $sql = "UPDATE devices SET 
                        manufacturer = :manufacturer,
                        model_name = :model,
                        status = :status,
                        last_contact = NOW(),
                        ip_address = :ip_address,
                        software_version = :software_version,
                        hardware_version = :hardware_version
                        WHERE id = :id";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':id' => $existingId,
                    ':manufacturer' => $deviceInfo['manufacturer'],
                    ':model' => $deviceInfo['modelName'],
                    ':status' => $deviceInfo['status'],
                    ':ip_address' => $_SERVER['REMOTE_ADDR'],
                    ':software_version' => $deviceInfo['softwareVersion'] ?? null,
                    ':hardware_version' => $deviceInfo['hardwareVersion'] ?? null
                ]);

                return $existingId;
            } else {
                // Insert new device
                $sql = "INSERT INTO devices 
                        (serial_number, manufacturer, model_name, status, 
                        last_contact, ip_address, software_version, hardware_version) 
                        VALUES 
                        (:serial, :manufacturer, :model, :status, 
                        NOW(), :ip_address, :software_version, :hardware_version)";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':serial' => $deviceInfo['serialNumber'],
                    ':manufacturer' => $deviceInfo['manufacturer'],
                    ':model' => $deviceInfo['modelName'],
                    ':status' => $deviceInfo['status'],
                    ':ip_address' => $_SERVER['REMOTE_ADDR'],
                    ':software_version' => $deviceInfo['softwareVersion'] ?? null,
                    ':hardware_version' => $deviceInfo['hardwareVersion'] ?? null
                ]);

                return $this->db->lastInsertId();
            }
        } catch (PDOException $e) {
            error_log("Database error in updateDevice: " . $e->getMessage());
            throw $e;
        }
    }

    private function getDeviceId($serialNumber) {
        $stmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
        $stmt->execute([':serial' => $serialNumber]);
        return $stmt->fetchColumn();
    }
}
