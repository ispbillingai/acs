
<?php
class DeviceManager {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function updateDevice($deviceInfo) {
        try {
            if (empty($deviceInfo['serialNumber'])) {
                throw new Exception("Cannot update device: missing serial number");
            }

            // First check if device exists
            $stmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
            $stmt->execute([':serial' => $deviceInfo['serialNumber']]);
            $existingId = $stmt->fetchColumn();

            if ($existingId) {
                // Update existing device
                $sql = "UPDATE devices SET 
                        manufacturer = :manufacturer,
                        model_name = :model_name,
                        mac_address = :mac_address,
                        status = :status,
                        last_contact = NOW(),
                        ip_address = :ip_address,
                        software_version = :software_version,
                        hardware_version = :hardware_version,
                        ssid = :ssid,
                        ssid_password = :ssid_password,
                        uptime = :uptime,
                        local_admin_password = :local_admin_password,
                        tr069_password = :tr069_password,
                        connected_clients = :connected_clients
                        WHERE serial_number = :serial_number";

                $params = [
                    ':manufacturer' => $deviceInfo['manufacturer'] ?: null,
                    ':model_name' => $deviceInfo['modelName'] ?: null,
                    ':mac_address' => $deviceInfo['macAddress'] ?: null,
                    ':status' => $deviceInfo['status'],
                    ':ip_address' => $_SERVER['REMOTE_ADDR'],
                    ':software_version' => $deviceInfo['softwareVersion'] ?: null,
                    ':hardware_version' => $deviceInfo['hardwareVersion'] ?: null,
                    ':ssid' => $deviceInfo['ssid'] ?: null,
                    ':ssid_password' => $deviceInfo['ssidPassword'] ?: null,
                    ':uptime' => $deviceInfo['uptime'] ?: 0,
                    ':local_admin_password' => $deviceInfo['localAdminPassword'] ?: null,
                    ':tr069_password' => $deviceInfo['tr069Password'] ?: null,
                    ':connected_clients' => count($deviceInfo['connectedClients']),
                    ':serial_number' => $deviceInfo['serialNumber']
                ];
                
                error_log("Updating device with params: " . print_r($params, true));
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);

                $this->updateConnectedClients($existingId, $deviceInfo['connectedClients'] ?? []);
                return $existingId;
            } else {
                // Insert new device
                $sql = "INSERT INTO devices 
                        (serial_number, manufacturer, model_name, mac_address, status, 
                        last_contact, ip_address, software_version, hardware_version, 
                        ssid, ssid_password, uptime, local_admin_password, tr069_password, connected_clients) 
                        VALUES 
                        (:serial_number, :manufacturer, :model_name, :mac_address, :status,
                        NOW(), :ip_address, :software_version, :hardware_version,
                        :ssid, :ssid_password, :uptime, :local_admin_password, :tr069_password, :connected_clients)";

                $params = [
                    ':serial_number' => $deviceInfo['serialNumber'],
                    ':manufacturer' => $deviceInfo['manufacturer'] ?: null,
                    ':model_name' => $deviceInfo['modelName'] ?: null,
                    ':mac_address' => $deviceInfo['macAddress'] ?: null,
                    ':status' => $deviceInfo['status'],
                    ':ip_address' => $_SERVER['REMOTE_ADDR'],
                    ':software_version' => $deviceInfo['softwareVersion'] ?: null,
                    ':hardware_version' => $deviceInfo['hardwareVersion'] ?: null,
                    ':ssid' => $deviceInfo['ssid'] ?: null,
                    ':ssid_password' => $deviceInfo['ssidPassword'] ?: null,
                    ':uptime' => $deviceInfo['uptime'] ?: 0,
                    ':local_admin_password' => $deviceInfo['localAdminPassword'] ?: null,
                    ':tr069_password' => $deviceInfo['tr069Password'] ?: null,
                    ':connected_clients' => count($deviceInfo['connectedClients'])
                ];

                error_log("Inserting new device with params: " . print_r($params, true));
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);

                $newId = $this->db->lastInsertId();
                $this->updateConnectedClients($newId, $deviceInfo['connectedClients'] ?? []);
                return $newId;
            }
        } catch (PDOException $e) {
            error_log("Database error in updateDevice: " . $e->getMessage());
            throw $e;
        }
    }

    private function updateConnectedClients($deviceId, $clients) {
        try {
            // First, remove old clients
            $stmt = $this->db->prepare("DELETE FROM connected_clients WHERE device_id = ?");
            $stmt->execute([$deviceId]);

            if (empty($clients)) {
                return;
            }

            // Insert new clients
            $sql = "INSERT INTO connected_clients 
                    (device_id, mac_address, ip_address, hostname, signal_strength, connected_since, last_seen) 
                    VALUES 
                    (?, ?, ?, ?, ?, NOW(), NOW())";
            $stmt = $this->db->prepare($sql);

            foreach ($clients as $client) {
                $stmt->execute([
                    $deviceId,
                    $client['macAddress'],
                    $client['ipAddress'] ?? null,
                    $client['hostname'] ?? null,
                    $client['signalStrength'] ?? null
                ]);
            }
        } catch (PDOException $e) {
            error_log("Error updating connected clients: " . $e->getMessage());
            throw $e;
        }
    }
}
