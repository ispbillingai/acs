
<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('error_log', __DIR__ . '/acs.log');

require_once __DIR__ . '/backend/config/database.php';

try {
    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();
    error_log("Database connection successful in device.php");

    // Get device ID from URL
    $deviceId = isset($_GET['id']) ? $_GET['id'] : null;
    error_log("Requested device ID: " . $deviceId);

    if (!$deviceId) {
        error_log("No device ID provided, redirecting to index");
        header('Location: index.php');
        exit;
    }

    // Fetch device details
    function getDevice($db, $id) {
        try {
            $sql = "SELECT * FROM devices WHERE id = :id";
            
            error_log("Executing device query: " . $sql . " with ID: " . $id);
            
            $stmt = $db->prepare($sql);
            $stmt->execute([':id' => $id]);
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row) {
                $device = [
                    'id' => $row['id'],
                    'serialNumber' => $row['serial_number'],
                    'manufacturer' => $row['manufacturer'],
                    'model' => $row['model_name'],
                    'status' => $row['status'],
                    'lastContact' => $row['last_contact'],
                    'ipAddress' => $row['ip_address'],
                    'softwareVersion' => $row['software_version'],
                    'hardwareVersion' => $row['hardware_version'],
                    'ssid' => $row['ssid'],
                    'ssidPassword' => $row['ssid_password'],
                    'uptime' => $row['uptime'],
                    'localAdminPassword' => $row['local_admin_password'],
                    'tr069Password' => $row['tr069_password'],
                    'connectedClients' => $row['connected_clients']
                ];
                error_log("Device details: " . print_r($device, true));
                return $device;
            }
            
            return null;
        } catch (PDOException $e) {
            error_log("Database error in getDevice: " . $e->getMessage());
            error_log("SQL State: " . $e->getCode());
            return null;
        }
    }

    // Get SSID from parameters table
    function getSSID($db, $deviceId) {
        try {
            $sql = "SELECT param_value FROM parameters WHERE device_id = :deviceId AND param_name LIKE '%SSID%' LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute([':deviceId' => $deviceId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return $result['param_value'];
            }
            return null;
        } catch (PDOException $e) {
            error_log("Database error in getSSID: " . $e->getMessage());
            return null;
        }
    }
    
    // Get software version from parameters table
    function getSoftwareVersion($db, $deviceId) {
        try {
            $sql = "SELECT param_value FROM parameters WHERE device_id = :deviceId AND param_name LIKE '%SoftwareVersion%' LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute([':deviceId' => $deviceId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return $result['param_value'];
            }
            return null;
        } catch (PDOException $e) {
            error_log("Database error in getSoftwareVersion: " . $e->getMessage());
            return null;
        }
    }
    
    // Get uptime from parameters table
    function getUptime($db, $deviceId) {
        try {
            $sql = "SELECT param_value FROM parameters WHERE device_id = :deviceId AND param_name LIKE '%UpTime%' LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute([':deviceId' => $deviceId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return $result['param_value'];
            }
            return null;
        } catch (PDOException $e) {
            error_log("Database error in getUptime: " . $e->getMessage());
            return null;
        }
    }
    
    // Get connected clients with details
    function getConnectedClients($db, $deviceId) {
        try {
            $sql = "SELECT * FROM connected_clients WHERE device_id = :deviceId AND is_active = 1";
            $stmt = $db->prepare($sql);
            $stmt->execute([':deviceId' => $deviceId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Database error in getConnectedClients: " . $e->getMessage());
            return [];
        }
    }

    $device = getDevice($db, $deviceId);
    error_log("Device retrieval result: " . ($device ? "success" : "failed"));

    if (!$device) {
        error_log("Device not found, redirecting to index");
        header('Location: index.php');
        exit;
    }

    // Check if SSID is empty in device table but exists in parameters
    if (empty($device['ssid'])) {
        $ssid = getSSID($db, $deviceId);
        if ($ssid) {
            $device['ssid'] = $ssid;
            // Update the device table with the SSID
            $updateSql = "UPDATE devices SET ssid = :ssid WHERE id = :id";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute([
                ':ssid' => $ssid,
                ':id' => $deviceId
            ]);
            error_log("Updated device SSID to: " . $ssid);
        }
    }
    
    // Check if software version is empty in device table but exists in parameters
    if (empty($device['softwareVersion'])) {
        $softwareVersion = getSoftwareVersion($db, $deviceId);
        if ($softwareVersion) {
            $device['softwareVersion'] = $softwareVersion;
            // Update the device table with the software version
            $updateSql = "UPDATE devices SET software_version = :softwareVersion WHERE id = :id";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute([
                ':softwareVersion' => $softwareVersion,
                ':id' => $deviceId
            ]);
            error_log("Updated device software version to: " . $softwareVersion);
        }
    }
    
    // Check if uptime is empty in device table but exists in parameters
    if (empty($device['uptime'])) {
        $uptime = getUptime($db, $deviceId);
        if ($uptime) {
            $device['uptime'] = $uptime;
            // Update the device table with the uptime
            $updateSql = "UPDATE devices SET uptime = :uptime WHERE id = :id";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute([
                ':uptime' => $uptime,
                ':id' => $deviceId
            ]);
            error_log("Updated device uptime to: " . $uptime);
        }
    }
    
    // Get connected clients
    $connectedClients = getConnectedClients($db, $deviceId);
    error_log("Retrieved " . count($connectedClients) . " connected clients");

    // Check if device is online (last contact within 10 minutes)
    $tenMinutesAgo = date('Y-m-d H:i:s', strtotime('-10 minutes'));
    $isOnline = strtotime($device['lastContact']) >= strtotime($tenMinutesAgo);
    $device['status'] = $isOnline ? 'online' : 'offline';

} catch (Exception $e) {
    error_log("Critical error in device.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    die("An error occurred: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Device Details - ACS Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50">
    <div class="p-6">
        <div class="max-w-7xl mx-auto space-y-8">
            <div class="flex items-center justify-between">
                <div>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">← Back to Dashboard</a>
                    <h1 class="text-3xl font-semibold tracking-tight mt-2">
                        Device Details
                    </h1>
                </div>
                <span class="px-3 py-1 text-sm rounded-full <?php echo $device['status'] === 'online' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                    <?php echo ucfirst($device['status']); ?>
                </span>
            </div>

            <!-- Device Information -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold mb-4">Device Information</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="text-sm text-gray-600">
                            <span class="font-medium">Manufacturer:</span>
                            <?php echo htmlspecialchars($device['manufacturer'] ?: 'Huawei'); ?>
                        </p>
                        <p class="text-sm text-gray-600">
                            <span class="font-medium">Model:</span>
                            <?php echo htmlspecialchars($device['model'] ?: 'N/A'); ?>
                        </p>
                        <p class="text-sm text-gray-600">
                            <span class="font-medium">Serial Number:</span>
                            <?php echo htmlspecialchars($device['serialNumber'] ?: 'N/A'); ?>
                        </p>
                        <p class="text-sm text-gray-600">
                            <span class="font-medium">IP Address:</span>
                            <?php echo htmlspecialchars($device['ipAddress'] ?: 'N/A'); ?>
                        </p>
                        <p class="text-sm text-gray-600">
                            <span class="font-medium">SSID:</span>
                            <?php echo htmlspecialchars($device['ssid'] ?: 'N/A'); ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">
                            <span class="font-medium">Software Version:</span>
                            <?php echo htmlspecialchars($device['softwareVersion'] ?: 'N/A'); ?>
                        </p>
                        <p class="text-sm text-gray-600">
                            <span class="font-medium">Hardware Version:</span>
                            <?php echo htmlspecialchars($device['hardwareVersion'] ?: 'N/A'); ?>
                        </p>
                        <p class="text-sm text-gray-600">
                            <span class="font-medium">Uptime:</span>
                            <?php echo htmlspecialchars($device['uptime'] ?: 'N/A'); ?>
                        </p>
                        <p class="text-sm text-gray-600">
                            <span class="font-medium">Connected Clients:</span>
                            <?php echo htmlspecialchars($device['connectedClients'] ?: '0'); ?>
                        </p>
                        <p class="text-sm text-gray-600">
                            <span class="font-medium">Last Contact:</span>
                            <?php echo date('Y-m-d H:i:s', strtotime($device['lastContact'])); ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($connectedClients)): ?>
            <!-- Connected Clients -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold mb-4">Connected Clients</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP Address</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hostname</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">MAC Address</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Seen</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($connectedClients as $client): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($client['ip_address']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($client['hostname'] ?: 'N/A'); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($client['mac_address'] ?: 'N/A'); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo date('Y-m-d H:i:s', strtotime($client['last_seen'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
