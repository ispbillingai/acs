
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
            
            // Make sure all required keys exist with default values if not provided
            $deviceInfo = array_merge([
                'manufacturer' => null,
                'modelName' => null,
                'macAddress' => null,
                'status' => 'online',
                'softwareVersion' => null,
                'hardwareVersion' => null,
                'ssid' => null,
                'ssidPassword' => null,
                'uptime' => 0,
                'localAdminPassword' => null,
                'tr069Password' => null,
                'connectedClients' => 0,
                'ipAddress' => $_SERVER['REMOTE_ADDR'] // Default to client's IP address
            ], $deviceInfo);

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
                    ':manufacturer' => $deviceInfo['manufacturer'],
                    ':model_name' => $deviceInfo['modelName'],
                    ':mac_address' => $deviceInfo['macAddress'],
                    ':status' => $deviceInfo['status'],
                    ':ip_address' => $deviceInfo['ipAddress'] ?: $_SERVER['REMOTE_ADDR'],
                    ':software_version' => $deviceInfo['softwareVersion'],
                    ':hardware_version' => $deviceInfo['hardwareVersion'],
                    ':ssid' => $deviceInfo['ssid'],
                    ':ssid_password' => $deviceInfo['ssidPassword'],
                    ':uptime' => $deviceInfo['uptime'],
                    ':local_admin_password' => $deviceInfo['localAdminPassword'],
                    ':tr069_password' => $deviceInfo['tr069Password'],
                    ':connected_clients' => $deviceInfo['connectedClients'],
                    ':serial_number' => $deviceInfo['serialNumber']
                ];
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);

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
                    ':manufacturer' => $deviceInfo['manufacturer'],
                    ':model_name' => $deviceInfo['modelName'],
                    ':mac_address' => $deviceInfo['macAddress'],
                    ':status' => $deviceInfo['status'],
                    ':ip_address' => $deviceInfo['ipAddress'] ?: $_SERVER['REMOTE_ADDR'],
                    ':software_version' => $deviceInfo['softwareVersion'],
                    ':hardware_version' => $deviceInfo['hardwareVersion'],
                    ':ssid' => $deviceInfo['ssid'],
                    ':ssid_password' => $deviceInfo['ssidPassword'],
                    ':uptime' => $deviceInfo['uptime'],
                    ':local_admin_password' => $deviceInfo['localAdminPassword'],
                    ':tr069_password' => $deviceInfo['tr069Password'],
                    ':connected_clients' => $deviceInfo['connectedClients']
                ];

                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);

                return $this->db->lastInsertId();
            }
        } catch (PDOException $e) {
            error_log("Database error in updateDevice: " . $e->getMessage());
            throw $e;
        }
    }
}
