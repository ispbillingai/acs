<?php

function saveParameterValues($raw, $serialNumber, $db) {
    $deviceMap = [
        'ExternalIPAddress' => 'ip_address',
        'SoftwareVersion' => 'software_version',
        'HardwareVersion' => 'hardware_version',
        'UpTime' => 'uptime',
        'SSID' => 'ssid',
        'HostNumberOfEntries' => 'connected_devices',
        'X_GponInterafceConfig.TXPower' => 'tx_power',
        'X_GponInterafceConfig.RXPower' => 'rx_power',
        'DNSServers' => 'dns_servers',
        'SubnetMask' => 'subnet_mask',
        'DefaultGateway' => 'default_gateway',
        'Username' => 'pppoe_username'
    ];
    
    $hostMap = [
        'IPAddress' => 'ip_address',
        'HostName' => 'hostname',
        'MACAddress' => 'mac_address',
        'Active' => 'is_active'
    ];
    
    $devicePairs = [];
    $hosts = [];
    preg_match_all('/<ParameterValueStruct>.*?<Name>(.*?)<\/Name>.*?<Value[^>]*>(.*?)<\/Value>/s', $raw, $matches, PREG_SET_ORDER);
    
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Extracted parameters: " . print_r($matches, true) . "\n", FILE_APPEND);
    
    $hostCount = 0;
    foreach ($matches as $param) {
        $name = $param[1];
        $value = $param[2];
        
        if (empty($value)) continue;
        
        // Check for HostNumberOfEntries to trigger follow-up host tasks
        if (strpos($name, 'HostNumberOfEntries') !== false && is_numeric($value)) {
            $hostCount = (int)$value;
            file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Found HostNumberOfEntries: $hostCount\n", FILE_APPEND);
        }
        
        foreach ($deviceMap as $needle => $column) {
            if (strpos($name, $needle) !== false) {
                $devicePairs[$column] = $value;
                file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Mapped $name to devices.$column = $value\n", FILE_APPEND);
            }
        }
        
        if (preg_match('/Hosts\.Host\.(\d+)\.(\w+)/', $name, $hostMatches)) {
            $hostIndex = $hostMatches[1];
            $hostProperty = $hostMatches[2];
            
            if (!isset($hosts[$hostIndex])) {
                $hosts[$hostIndex] = [
                    'ip_address' => '',
                    'hostname' => '',
                    'mac_address' => '',
                    'is_active' => 0
                ];
            }
            
            foreach ($hostMap as $needle => $column) {
                if ($hostProperty === $needle) {
                    $hosts[$hostIndex][$column] = ($needle === 'Active') ? ($value === '1' || strtolower($value) === 'true' ? 1 : 0) : $value;
                    file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Mapped $name to connected_clients.$column = " . $hosts[$hostIndex][$column] . "\n", FILE_APPEND);
                }
            }
        }
    }
    
    if (!empty($devicePairs)) {
        try {
            $setStatements = [];
            $params = [':serial' => $serialNumber];
            
            foreach ($devicePairs as $column => $value) {
                $setStatements[] = "$column = :$column";
                $params[":$column"] = $value;
            }
            
            $sql = "UPDATE devices SET " . implode(', ', $setStatements) . " WHERE serial_number = :serial";
            file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] SQL (devices): $sql\n", FILE_APPEND);
            file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Params (devices): " . print_r($params, true) . "\n", FILE_APPEND);
            
            $stmt = $db->prepare($sql);
            $result = $stmt->execute($params);
            
            file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Update result (devices): " . ($result ? "success" : "failed") . "\n", FILE_APPEND);
            tr069_log("Device $serialNumber updated with " . implode(', ', array_keys($devicePairs)), "INFO");
        } catch (Exception $e) {
            file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Database error (devices): " . $e->getMessage() . "\n", FILE_APPEND);
            tr069_log("Error updating device data: " . $e->getMessage(), "ERROR");
        }
    }
    
    // Create follow-up task for host details if hostCount > 0
    if ($hostCount > 0) {
        try {
            $hostTasksCreated = false;
            for ($i = 1; $i <= $hostCount; $i++) {
                $hostParams = [
                    "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.Active",
                    "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.IPAddress",
                    "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.HostName",
                    "InternetGatewayDevice.LANDevice.1.Hosts.Host.{$i}.MACAddress"
                ];
                
                $taskData = json_encode([
                    'group' => "Host $i",
                    'parameters' => $hostParams,
                    'host_count' => $hostCount
                ]);
                
                $insertStmt = $db->prepare("
                    INSERT INTO device_tasks 
                        (device_id, task_type, task_data, status, message, created_at, updated_at) 
                    VALUES 
                        (:device_id, 'info_group', :task_data, 'pending', 'Auto-created for Host $i parameters', NOW(), NOW())
                ");
                
                $insertResult = $insertStmt->execute([
                    ':device_id' => $db->query("SELECT id FROM devices WHERE serial_number = '$serialNumber'")->fetchColumn(),
                    ':task_data' => $taskData
                ]);
                
                if ($insertResult) {
                    $taskId = $db->lastInsertId();
                    tr069_log("TASK CREATION: Successfully created info_group task with ID: $taskId for Host $i", "INFO");
                    $hostTasksCreated = true;
                } else {
                    tr069_log("TASK CREATION ERROR: Failed to create info_group task for Host $i. Database error: " . print_r($insertStmt->errorInfo(), true), "ERROR");
                }
            }
            
            if ($hostTasksCreated) {
                file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Created follow-up tasks for $hostCount hosts\n", FILE_APPEND);
            }
        } catch (PDOException $e) {
            tr069_log("TASK CREATION ERROR: Exception creating host tasks: " . $e->getMessage(), "ERROR");
        }
    }
    
    if (!empty($hosts)) {
        try {
            $stmt = $db->prepare("SELECT id, connected_devices FROM devices WHERE serial_number = :serial");
            $stmt->execute([':serial' => $serialNumber]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$device) {
                file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Device not found for serial: $serialNumber\n", FILE_APPEND);
                tr069_log("Device not found for serial: $serialNumber", "ERROR");
                return;
            }
            
            $deviceId = $device['id'];
            $connectedDevices = (int) ($device['connected_devices'] ?? 0);
            
            if ($connectedDevices < 0) {
                file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Invalid connected_devices count: $connectedDevices for device $serialNumber\n", FILE_APPEND);
                tr069_log("Invalid connected_devices count: $connectedDevices for device $serialNumber", "ERROR");
                return;
            }
            
            $countStmt = $db->prepare("SELECT COUNT(*) as count FROM connected_clients WHERE device_id = :device_id");
            $countStmt->execute([':device_id' => $deviceId]);
            $currentHostCount = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($connectedDevices < $currentHostCount || $connectedDevices === 0) {
                $deleteStmt = $db->prepare("DELETE FROM connected_clients WHERE device_id = :device_id");
                $deleteResult = $deleteStmt->execute([':device_id' => $deviceId]);
                file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Deleted $currentHostCount hosts for device_id $deviceId (new connected_devices: $connectedDevices)\n", FILE_APPEND);
                tr069_log("Deleted $currentHostCount hosts for device $serialNumber (new connected_devices: $connectedDevices)", "INFO");
            }
            
            if ($connectedDevices === 0) {
                file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] No hosts to process (connected_devices: 0)\n", FILE_APPEND);
                tr069_log("No hosts to process for device $serialNumber (connected_devices: 0)", "INFO");
                return;
            }
            
            $hostSuccess = 0;
            $hostCount = min(count($hosts), $connectedDevices);
            
            for ($i = 1; $i <= $hostCount; $i++) {
                if (!isset($hosts[$i])) {
                    file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Skipping host $i: no data available\n", FILE_APPEND);
                    continue;
                }
                
                $host = $hosts[$i];
                if (empty($host['ip_address']) && empty($host['mac_address'])) {
                    file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Skipping host $i: no IP or MAC address\n", FILE_APPEND);
                    continue;
                }
                
                $checkStmt = $db->prepare("SELECT id FROM connected_clients WHERE device_id = :device_id AND (mac_address = :mac_address OR ip_address = :ip_address)");
                $checkStmt->execute([
                    ':device_id' => $deviceId,
                    ':mac_address' => $host['mac_address'] ?: '',
                    ':ip_address' => $host['ip_address'] ?: ''
                ]);
                $existingHost = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingHost) {
                    $updateStmt = $db->prepare("UPDATE connected_clients SET 
                        ip_address = :ip_address,
                        hostname = :hostname,
                        mac_address = :mac_address,
                        is_active = :is_active
                        WHERE id = :id");
                    $updateResult = $updateStmt->execute([
                        ':ip_address' => $host['ip_address'],
                        ':hostname' => $host['hostname'],
                        ':mac_address' => $host['mac_address'],
                        ':is_active' => $host['is_active'],
                        ':id' => $existingHost['id']
                    ]);
                    
                    if ($updateResult) {
                        $hostSuccess++;
                        file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Updated host $i for device_id $deviceId: ip_address={$host['ip_address']}, mac_address={$host['mac_address']}\n", FILE_APPEND);
                    } else {
                        file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Failed to update host $i for device_id $deviceId\n", FILE_APPEND);
                    }
                } else {
                    $insertStmt = $db->prepare("INSERT INTO connected_clients (
                        device_id, ip_address, hostname, mac_address, is_active
                    ) VALUES (
                        :device_id, :ip_address, :hostname, :mac_address, :is_active
                    )");
                    $insertResult = $insertStmt->execute([
                        ':device_id' => $deviceId,
                        ':ip_address' => $host['ip_address'],
                        ':hostname' => $host['hostname'],
                        ':mac_address' => $host['mac_address'],
                        ':is_active' => $host['is_active']
                    ]);
                    
                    if ($insertResult) {
                        $hostSuccess++;
                        file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Inserted host $i for device_id $deviceId: ip_address={$host['ip_address']}, mac_address={$host['mac_address']}\n", FILE_APPEND);
                    } else {
                        file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Failed to insert host $i for device_id $deviceId\n", FILE_APPEND);
                    }
                }
            }
            
            file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Updated $hostSuccess hosts for device $serialNumber\n", FILE_APPEND);
            tr069_log("Updated $hostSuccess hosts for device $serialNumber", "INFO");
        } catch (Exception $e) {
            file_put_contents($GLOBALS['retrieve_log'], "[$timestamp] Database error (connected_clients): " . $e->getMessage() . "\n", FILE_APPEND);
            tr069_log("Error updating connected_clients for device $serialNumber: " . $e->getMessage(), "ERROR");
        }
    }
}

?>