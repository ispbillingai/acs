<?php
class DeviceManager {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function updateOrCreateDevice($serialNumber, $manufacturer, $modelName, $hardwareVersion, $softwareVersion) {
        try {
            if (empty($serialNumber)) {
                throw new Exception("Cannot update device: missing serial number");
            }
            
            // Check if device exists
            $stmt = $this->db->prepare("SELECT id FROM devices WHERE serial_number = :serial");
            $stmt->execute([':serial' => $serialNumber]);
            $existingId = $stmt->fetchColumn();

            if ($existingId) {
                // Update existing device
                $sql = "UPDATE devices SET 
                        manufacturer = :manufacturer,
                        model_name = :model_name,
                        status = 'online',
                        last_contact = NOW(),
                        ip_address = :ip_address,
                        software_version = :software_version,
                        hardware_version = :hardware_version
                        WHERE serial_number = :serial_number";

                $params = [
                    ':manufacturer' => $manufacturer,
                    ':model_name' => $modelName,
                    ':ip_address' => $_SERVER['REMOTE_ADDR'],
                    ':software_version' => $softwareVersion,
                    ':hardware_version' => $hardwareVersion,
                    ':serial_number' => $serialNumber
                ];
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);

                return $existingId;
            } else {
                // Insert new device
                $sql = "INSERT INTO devices 
                        (serial_number, manufacturer, model_name, status, 
                        last_contact, ip_address, software_version, hardware_version) 
                        VALUES 
                        (:serial_number, :manufacturer, :model_name, 'online',
                        NOW(), :ip_address, :software_version, :hardware_version)";

                $params = [
                    ':serial_number' => $serialNumber,
                    ':manufacturer' => $manufacturer,
                    ':model_name' => $modelName,
                    ':ip_address' => $_SERVER['REMOTE_ADDR'],
                    ':software_version' => $softwareVersion,
                    ':hardware_version' => $hardwareVersion
                ];

                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);

                return $this->db->lastInsertId();
            }
        } catch (PDOException $e) {
            error_log("Database error in updateOrCreateDevice: " . $e->getMessage());
            throw $e;
        }
    }

    public function storeDeviceParameter($deviceId, $paramName, $paramValue) {
        try {
            // Check if parameter already exists
            $stmt = $this->db->prepare("SELECT id FROM device_parameters WHERE device_id = :device_id AND param_name = :param_name");
            $stmt->execute([
                ':device_id' => $deviceId,
                ':param_name' => $paramName
            ]);
            $existingId = $stmt->fetchColumn();

            if ($existingId) {
                // Update existing parameter
                $sql = "UPDATE device_parameters SET 
                        param_value = :param_value,
                        updated_at = NOW()
                        WHERE id = :id";
                
                $params = [
                    ':param_value' => $paramValue,
                    ':id' => $existingId
                ];
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
            } else {
                // Insert new parameter
                $sql = "INSERT INTO device_parameters 
                        (device_id, param_name, param_value, created_at, updated_at) 
                        VALUES 
                        (:device_id, :param_name, :param_value, NOW(), NOW())";
                
                $params = [
                    ':device_id' => $deviceId,
                    ':param_name' => $paramName,
                    ':param_value' => $paramValue
                ];
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
            }
            
            return true;
        } catch (PDOException $e) {
            error_log("Database error in storeDeviceParameter: " . $e->getMessage());
            file_put_contents(__DIR__ . '/../../wifi_discovery.log', date('Y-m-d H:i:s') . " Database error: " . $e->getMessage() . "\n", FILE_APPEND);
            return false;
        }
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
                        connected_clients = :connected_clients,
                        rx_power = :rx_power,
                        tx_power = :tx_power
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
                ':rx_power' => $deviceInfo['rxPower'],
                ':tx_power' => $deviceInfo['txPower'],
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
