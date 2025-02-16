
<?php
class DeviceManager {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function updateDevice($deviceInfo) {
        try {
            $sql = "INSERT INTO devices 
                    (serial_number, manufacturer, model_name, status, 
                    last_contact, ssid, connected_clients) 
                    VALUES (:serial, :manufacturer, :model, :status, 
                    NOW(), :ssid, :connected_clients) 
                    ON DUPLICATE KEY UPDATE 
                    last_contact = NOW(),
                    status = :status,
                    ssid = :ssid,
                    connected_clients = :connected_clients";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':serial' => $deviceInfo['serialNumber'],
                ':manufacturer' => $deviceInfo['manufacturer'],
                ':model' => $deviceInfo['modelName'],
                ':status' => $deviceInfo['status'],
                ':ssid' => $deviceInfo['ssid'] ?? '',
                ':connected_clients' => $deviceInfo['connected_clients'] ?? 0
            ]);

            return $this->db->lastInsertId() ?: $this->getDeviceId($deviceInfo['serialNumber']);
        } catch (PDOException $e) {
            error_log("Database error in updateDevice: " . $e->getMessage());
            throw $e;
        }
    }

    public function updateSSID($deviceId, $ssid, $password) {
        try {
            $sql = "UPDATE devices 
                    SET ssid = :ssid, 
                        ssid_password = :password 
                    WHERE id = :device_id";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':device_id' => $deviceId,
                ':ssid' => $ssid,
                ':password' => $password
            ]);
        } catch (PDOException $e) {
            error_log("Database error in updateSSID: " . $e->getMessage());
            throw $e;
        }
    }

    public function updateConnectedClients($deviceId, $clients) {
        try {
            // First, clear existing clients
            $sql = "DELETE FROM connected_clients WHERE device_id = :device_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':device_id' => $deviceId]);

            // Then insert new clients
            foreach ($clients as $client) {
                $sql = "INSERT INTO connected_clients 
                        (device_id, mac_address, ip_address, hostname, connected_since) 
                        VALUES (:device_id, :mac, :ip, :hostname, NOW())";
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':device_id' => $deviceId,
                    ':mac' => $client['mac'],
                    ':ip' => $client['ip'],
                    ':hostname' => $client['hostname']
                ]);
            }
        } catch (PDOException $e) {
            error_log("Database error in updateConnectedClients: " . $e->getMessage());
            throw $e;
        }
    }

    private function getDeviceId($serialNumber) {
        $stmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
        $stmt->execute([':serial' => $serialNumber]);
        return $stmt->fetchColumn();
    }
}
