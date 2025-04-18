<?php
// Enable error reporting to device.log
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('error_log', __DIR__ . '/device.log');

require_once __DIR__ . '/backend/config/database.php';

try {
    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();

    // Get device ID from URL
    $deviceId = isset($_GET['id']) ? $_GET['id'] : null;

    if (!$deviceId) {
        header('Location: index.php');
        exit;
    }

    // Fetch device details
    function getDevice($db, $id) {
        try {
            $sql = "SELECT * FROM devices WHERE id = :id";
            
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
                    'connectedClients' => $row['connected_devices'] // Use connected_devices column directly
                ];
                return $device;
            }
            
            return null;
        } catch (PDOException $e) {
            return null;
        }
    }

    // Get parameter value from parameters table
    function getParameterValue($db, $deviceId, $paramName) {
        try {
            $sql = "SELECT param_value FROM parameters WHERE device_id = :deviceId AND param_name LIKE :paramName LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':deviceId' => $deviceId,
                ':paramName' => "%$paramName%"
            ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return $result['param_value'];
            }
            return null;
        } catch (PDOException $e) {
            return null;
        }
    }
    
    // Format uptime from seconds to a more readable format
    function formatUptime($seconds) {
        if (!$seconds) return null;
        
        $seconds = intval($seconds);
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        if ($days > 0) {
            return "$days days, $hours hours, $minutes minutes";
        } else if ($hours > 0) {
            return "$hours hours, $minutes minutes";
        } else if ($minutes > 0) {
            return "$minutes minutes";
        } else {
            return "$seconds seconds";
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
            return [];
        }
    }

    // Count active connected clients - Keep this function even if not used
    function countConnectedClients($db, $deviceId) {
        try {
            $sql = "SELECT COUNT(*) as count FROM connected_clients WHERE device_id = :deviceId AND is_active = 1";
            $stmt = $db->prepare($sql);
            $stmt->execute([':deviceId' => $deviceId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? intval($result['count']) : 0;
        } catch (PDOException $e) {
            return 0;
        }
    }

    $device = getDevice($db, $deviceId);

    if (!$device) {
        header('Location: index.php');
        exit;
    }

    // Always get the latest uptime value from parameters table
    $latestUptime = getParameterValue($db, $deviceId, 'UpTime');
    if ($latestUptime) {
        $device['uptime'] = $latestUptime;
    }
    
    // Do not override the connected_clients value from the database
    // This is the key change - we're keeping the value from connected_devices
    
    // Update the device record with the latest uptime value only, not connected clients
    $updateSql = "UPDATE devices SET uptime = :uptime WHERE id = :id";
    $updateStmt = $db->prepare($updateSql);
    $updateStmt->execute([
        ':uptime' => $device['uptime'],
        ':id' => $deviceId
    ]);
    
    // Check for missing values in device table but exist in parameters
    $keysToCheck = [
        ['deviceKey' => 'ssid', 'paramName' => 'SSID', 'dbColumn' => 'ssid'],
        ['deviceKey' => 'softwareVersion', 'paramName' => 'SoftwareVersion', 'dbColumn' => 'software_version'],
        ['deviceKey' => 'hardwareVersion', 'paramName' => 'HardwareVersion', 'dbColumn' => 'hardware_version'],
        ['deviceKey' => 'manufacturer', 'paramName' => 'Manufacturer', 'dbColumn' => 'manufacturer']
    ];
    
    $needsUpdate = false;
    $updateValues = [];
    
    foreach ($keysToCheck as $keyInfo) {
        if (empty($device[$keyInfo['deviceKey']])) {
            $paramValue = getParameterValue($db, $deviceId, $keyInfo['paramName']);
            if ($paramValue) {
                $device[$keyInfo['deviceKey']] = $paramValue;
                $updateValues[$keyInfo['dbColumn']] = $paramValue;
                $needsUpdate = true;
            }
        }
    }
    
    // Also check for TX and RX power
    $txPower = getParameterValue($db, $deviceId, 'TXPower');
    $rxPower = getParameterValue($db, $deviceId, 'RXPower');
    
    if ($txPower) {
        $device['txPower'] = $txPower;
    }
    
    if ($rxPower) {
        $device['rxPower'] = $rxPower;
    }
    
    // If we found values in parameters that aren't in the device table, update the device table
    if ($needsUpdate) {
        $updateSql = "UPDATE devices SET ";
        $updateParams = [];
        
        foreach ($updateValues as $column => $value) {
            $updateSql .= "$column = :$column, ";
            $updateParams[":$column"] = $value;
        }
        
        // Remove trailing comma and space
        $updateSql = rtrim($updateSql, ", ");
        $updateSql .= " WHERE id = :id";
        $updateParams[':id'] = $deviceId;
        
        try {
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute($updateParams);
        } catch (PDOException $e) {
            // Continue silently
        }
    }
    
    // Format the uptime for display
    if (!empty($device['uptime'])) {
        $device['formattedUptime'] = formatUptime($device['uptime']);
    } else {
        $device['formattedUptime'] = 'N/A';
    }
    
    // Get connected clients
    $connectedClients = getConnectedClients($db, $deviceId);

    // Check if device is online (last contact within 10 minutes)
    $tenMinutesAgo = date('Y-m-d H:i:s', strtotime('-10 minutes'));
    $isOnline = strtotime($device['lastContact']) >= strtotime($tenMinutesAgo);
    $device['status'] = $isOnline ? 'online' : 'offline';

} catch (Exception $e) {
    die("An error occurred");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Device Details</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/styles.css">
    <script src="js/jquery-3.6.0.min.js"></script>
    <script src="js/bootstrap.bundle.min.js"></script>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12">
                <h1>Device Details</h1>
                <a href="index.php" class="btn btn-secondary mb-3">Back to Devices</a>
                
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <?php echo htmlspecialchars($device['manufacturer'] . ' ' . $device['model']); ?>
                            <span class="badge <?php echo $device['status'] === 'online' ? 'bg-success' : 'bg-danger'; ?>">
                                <?php echo ucfirst($device['status']); ?>
                            </span>
                        </h5>
                        <div>
                            <a href="edit_device.php?id=<?php echo $deviceId; ?>" class="btn btn-primary btn-sm">Edit</a>
                            <a href="reboot_device.php?id=<?php echo $deviceId; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Are you sure you want to reboot this device?')">Reboot</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Basic Information</h6>
                                <table class="table table-sm">
                                    <tr>
                                        <th>Serial Number</th>
                                        <td><?php echo htmlspecialchars($device['serialNumber']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Model</th>
                                        <td><?php echo htmlspecialchars($device['model']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Manufacturer</th>
                                        <td><?php echo htmlspecialchars($device['manufacturer']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>IP Address</th>
                                        <td><?php echo htmlspecialchars($device['ipAddress']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Software Version</th>
                                        <td><?php echo htmlspecialchars($device['softwareVersion'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Hardware Version</th>
                                        <td><?php echo htmlspecialchars($device['hardwareVersion'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Last Contact</th>
                                        <td><?php echo htmlspecialchars($device['lastContact']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Uptime</th>
                                        <td><?php echo htmlspecialchars($device['formattedUptime']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Connected Clients</th>
                                        <td><?php echo htmlspecialchars($device['connectedClients'] ?? '0'); ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6>Wireless Information</h6>
                                <table class="table table-sm">
                                    <tr>
                                        <th>SSID</th>
                                        <td><?php echo htmlspecialchars($device['ssid'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>SSID Password</th>
                                        <td>
                                            <?php if (!empty($device['ssidPassword'])): ?>
                                                <span class="password-mask">********</span>
                                                <button class="btn btn-sm btn-outline-secondary toggle-password" data-password="<?php echo htmlspecialchars($device['ssidPassword']); ?>">Show</button>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>TX Power</th>
                                        <td><?php echo htmlspecialchars($device['txPower'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>RX Power</th>
                                        <td><?php echo htmlspecialchars($device['rxPower'] ?? 'N/A'); ?></td>
                                    </tr>
                                </table>
                                
                                <h6 class="mt-4">Authentication</h6>
                                <table class="table table-sm">
                                    <tr>
                                        <th>TR-069 Password</th>
                                        <td>
                                            <?php if (!empty($device['tr069Password'])): ?>
                                                <span class="password-mask">********</span>
                                                <button class="btn btn-sm btn-outline-secondary toggle-password" data-password="<?php echo htmlspecialchars($device['tr069Password']); ?>">Show</button>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Local Admin Password</th>
                                        <td>
                                            <?php if (!empty($device['localAdminPassword'])): ?>
                                                <span class="password-mask">********</span>
                                                <button class="btn btn-sm btn-outline-secondary toggle-password" data-password="<?php echo htmlspecialchars($device['localAdminPassword']); ?>">Show</button>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Connected Clients</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($connectedClients)): ?>
                            <p class="text-muted">No connected clients found.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Hostname</th>
                                            <th>IP Address</th>
                                            <th>MAC Address</th>
                                            <th>Last Seen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($connectedClients as $client): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($client['hostname'] ?: 'Unknown'); ?></td>
                                                <td><?php echo htmlspecialchars($client['ip_address']); ?></td>
                                                <td><?php echo htmlspecialchars($client['mac_address']); ?></td>
                                                <td><?php echo htmlspecialchars($client['last_seen']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Device Parameters</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <a href="refresh_parameters.php?id=<?php echo $deviceId; ?>" class="btn btn-primary">Refresh Parameters</a>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-striped" id="parametersTable">
                                <thead>
                                    <tr>
                                        <th>Parameter</th>
                                        <th>Value</th>
                                        <th>Last Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    try {
                                        $paramsSql = "SELECT param_name, param_value, updated_at FROM parameters WHERE device_id = :deviceId ORDER BY param_name";
                                        $paramsStmt = $db->prepare($paramsSql);
                                        $paramsStmt->execute([':deviceId' => $deviceId]);
                                        $parameters = $paramsStmt->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        if (empty($parameters)): ?>
                                            <tr>
                                                <td colspan="3" class="text-center">No parameters found. Click "Refresh Parameters" to retrieve them.</td>
                                            </tr>
                                        <?php else:
                                            foreach ($parameters as $param): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($param['param_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($param['param_value']); ?></td>
                                                    <td><?php echo htmlspecialchars($param['updated_at']); ?></td>
                                                </tr>
                                            <?php endforeach;
                                        endif;
                                    } catch (PDOException $e) {
                                        echo '<tr><td colspan="3" class="text-center">Error retrieving parameters</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        $(document).ready(function() {
            // Toggle password visibility
            $('.toggle-password').click(function() {
                var passwordField = $(this).prev('.password-mask');
                var passwordText = $(this).data('password');
                
                if (passwordField.text() === '********') {
                    passwordField.text(passwordText);
                    $(this).text('Hide');
                } else {
                    passwordField.text('********');
                    $(this).text('Show');
                }
            });
            
            // Initialize DataTable for parameters if available
            if ($.fn.DataTable) {
                $('#parametersTable').DataTable({
                    "pageLength": 25,
                    "order": [[0, "asc"]]
                });
            }
        });
    </script>
</body>
</html>
