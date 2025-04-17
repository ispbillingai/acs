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
                    'connectedClients' => $row['connected_clients']
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

    // Count active connected clients
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
    
    // Always get the latest connected clients count
    $connectedClientsCount = countConnectedClients($db, $deviceId);
    $device['connectedClients'] = $connectedClientsCount;
    
    // Update the device record with the latest values
    $updateSql = "UPDATE devices SET 
                    uptime = :uptime, 
                    connected_clients = :connectedClients 
                WHERE id = :id";
    $updateStmt = $db->prepare($updateSql);
    $updateStmt->execute([
        ':uptime' => $device['uptime'],
        ':connectedClients' => $device['connectedClients'],
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
    <title>Device Details - ACS Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.0.7/css/boxicons.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fd;
        }
        .sidebar {
            background: linear-gradient(180deg, #2c3e50 0%, #1a252f 100%);
            min-height: 100vh;
        }
        .nav-link {
            color: rgba(255,255,255,0.7);
            margin-bottom: 5px;
            border-radius: 5px;
            transition: all 0.3s;
        }
        .nav-link:hover, .nav-link.active {
            background-color: rgba(255,255,255,0.1);
            color: white;
        }
        .card {
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            border: none;
            transition: all 0.3s;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .device-status {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        .device-status.online {
            background-color: #10b981;
        }
        .device-status.offline {
            background-color: #ef4444;
        }
        .navbar {
            background: linear-gradient(90deg, #3498db 0%, #2c3e50 100%);
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .info-card {
            background: linear-gradient(135deg, #ffffff 0%, #f0f4fd 100%);
        }
        .table-card {
            background: white;
        }
        .table th {
            border-top: none;
            border-bottom: 2px solid #e2e8f0;
            color: #64748b;
            font-weight: 600;
            padding: 12px 16px;
        }
        .table td {
            padding: 12px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
        }
        .navbar-brand {
            font-weight: 700;
            color: white;
        }
        .btn-primary {
            background-color: #3b82f6;
            border-color: #3b82f6;
        }
        .btn-primary:hover {
            background-color: #2563eb;
            border-color: #2563eb;
        }
        .dropdown-item:active {
            background-color: #3b82f6;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1050;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            border-radius: 10px;
            width: 80%;
            max-width: 600px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .close-modal {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close-modal:hover {
            color: #000;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 col-lg-2 d-md-block sidebar collapse p-0">
                <div class="position-sticky">
                    <div class="d-flex align-items-center justify-content-center p-3 border-bottom border-dark">
                        <h4 class="text-white m-0">ACS Dashboard</h4>
                    </div>
                    <ul class="nav flex-column mt-3 p-2">
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center" href="index.php">
                                <i class='bx bx-home me-2'></i>
                                <span>Dashboard</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active d-flex align-items-center" href="devices.php">
                                <i class='bx bx-devices me-2'></i>
                                <span>Devices</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center" href="#" data-bs-toggle="collapse" data-bs-target="#competitionSubmenu">
                                <i class='bx bx-trophy me-2'></i>
                                <span>Competition</span>
                                <i class='bx bx-chevron-down ms-auto'></i>
                            </a>
                            <div class="collapse" id="competitionSubmenu">
                                <ul class="nav flex-column ms-3 mt-2">
                                    <li class="nav-item">
                                        <a class="nav-link py-1 d-flex align-items-center" href="#">
                                            <i class='bx bx-line-chart me-2'></i>
                                            <span>Analytics</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link py-1 d-flex align-items-center" href="#">
                                            <i class='bx bx-group me-2'></i>
                                            <span>Teams</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center" href="configuration.php">
                                <i class='bx bx-cog me-2'></i>
                                <span>Configuration</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center" href="settings.php">
                                <i class='bx bx-slider-alt me-2'></i>
                                <span>Settings</span>
                            </a>
                        </li>
                        <li class="nav-item mt-auto">
                            <a class="nav-link d-flex align-items-center" href="logout.php">
                                <i class='bx bx-log-out me-2'></i>
                                <span>Logout</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main content -->
            <div class="col-md-10 col-lg-10 ms-sm-auto p-4">
                <!-- Top navbar -->
                <nav class="navbar navbar-expand-lg mb-4 rounded-3">
                    <div class="container-fluid">
                        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                            <span class="navbar-toggler-icon"></span>
                        </button>
                        <a class="navbar-brand" href="index.php">Device Management</a>
                        <div class="collapse navbar-collapse" id="navbarNav">
                            <ul class="navbar-nav ms-auto">
                                <li class="nav-item dropdown">
                                    <a class="nav-link dropdown-toggle text-white" href="#" role="button" data-bs-toggle="dropdown">
                                        <i class='bx bx-user-circle me-1'></i> Admin
                                    </a>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="#"><i class='bx bx-user me-2'></i>Profile</a></li>
                                        <li><a class="dropdown-item" href="#"><i class='bx bx-cog me-2'></i>Settings</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item" href="logout.php"><i class='bx bx-log-out me-2'></i>Logout</a></li>
                                    </ul>
                                </li>
                            </ul>
                        </div>
                    </div>
                </nav>

                <!-- Page title and breadcrumb -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                    <div>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Dashboard</a></li>
                                <li class="breadcrumb-item"><a href="devices.php" class="text-decoration-none">Devices</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Device Details</li>
                            </ol>
                        </nav>
                        <h1 class="h2 mb-0 d-flex align-items-center">
                            Device Details 
                            <span class="ms-3 badge <?php echo $device['status'] === 'online' ? 'bg-success' : 'bg-danger'; ?>">
                                <?php echo ucfirst($device['status']); ?>
                            </span>
                        </h1>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="refresh-data-btn"><i class='bx bx-refresh me-1'></i>Refresh</button>
                            <button type="button" class="btn btn-sm btn-outline-primary"><i class='bx bx-edit me-1'></i>Edit</button>
                        </div>
                        <a href="configure_device.php?id=<?php echo $deviceId; ?>" class="btn btn-sm btn-primary"><i class='bx bx-cog me-1'></i>Configure</a>
                    </div>
                </div>

                <!-- Device Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stats-card p-3 h-100">
                            <div class="card-body">
                                <h5 class="card-title">Status</h5>
                                <div class="d-flex align-items-center mt-3">
                                    <div class="device-status <?php echo $device['status']; ?>"></div>
                                    <h2 class="display-6 mb-0"><?php echo ucfirst($device['status']); ?></h2>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card p-3 h-100">
                            <div class="card-body">
                                <h5 class="card-title">Connected Clients</h5>
                                <div class="d-flex align-items-center mt-3">
                                    <i class='bx bx-laptop me-2 fs-1'></i>
                                    <h2 class="display-6 mb-0"><?php echo htmlspecialchars($device['connectedClients'] ?: '0'); ?></h2>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card p-3 h-100">
                            <div class="card-body">
                                <h5 class="card-title">Last Contact</h5>
                                <div class="d-flex align-items-center mt-3">
                                    <i class='bx bx-time me-2 fs-1'></i>
                                    <h2 class="display-6 mb-0 fs-5"><?php echo date('H:i:s', strtotime($device['lastContact'])); ?></h2>
                                </div>
                                <div class="text-white-50"><?php echo date('Y-m-d', strtotime($device['lastContact'])); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card p-3 h-100">
                            <div class="card-body">
                                <h5 class="card-title">Uptime</h5>
                                <div class="d-flex align-items-center mt-3">
                                    <i class='bx bx-timer me-2 fs-1'></i>
                                    <h2 class="display-6 mb-0 fs-6"><?php echo htmlspecialchars($device['formattedUptime'] ?: 'N/A'); ?></h2>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Device Information -->
                <div class="card info-card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class='bx bx-info-circle me-2'></i>Device Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label text-muted mb-1">Manufacturer</label>
                                    <p class="fw-medium"><?php echo htmlspecialchars($device['manufacturer'] ?: 'N/A'); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted mb-1">Model</label>
                                    <p class="fw-medium"><?php echo htmlspecialchars($device['model'] ?: 'N/A'); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted mb-1">Serial Number</label>
                                    <p class="fw-medium"><?php echo htmlspecialchars($device['serialNumber'] ?: 'N/A'); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted mb-1">IP Address</label>
                                    <p class="fw-medium"><?php echo htmlspecialchars($device['ipAddress'] ?: 'N/A'); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted mb-1">SSID</label>
                                    <p class="fw-medium"><?php echo htmlspecialchars($device['ssid'] ?: 'N/A'); ?></p>
                                </div>
                                <?php if (!empty($device['txPower'])): ?>
                                <div class="mb-3">
                                    <label class="form-label text-muted mb-1">TX Power</label>
                                    <p class="fw-medium"><?php echo htmlspecialchars($device['txPower']); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label text-muted mb-1">Software Version</label>
                                    <p class="fw-medium"><?php echo htmlspecialchars($device['softwareVersion'] ?: 'N/A'); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted mb-1">Hardware Version</label>
                                    <p class="fw-medium"><?php echo htmlspecialchars($device['hardwareVersion'] ?: 'N/A'); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted mb-1">Uptime</label>
                                    <p class="fw-medium"><?php echo htmlspecialchars($device['formattedUptime'] ?: 'N/A'); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted mb-1">Connected Clients</label>
                                    <p class="fw-medium"><?php echo htmlspecialchars($device['connectedClients'] ?: '0'); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted mb-1">Last Contact</label>
                                    <p class="fw-medium"><?php echo date('Y-m-d H:i:s', strtotime($device['lastContact'])); ?></p>
                                </div>
                                <?php if (!empty($device['rxPower'])): ?>
                                <div class="mb-3">
                                    <label class="form-label text-muted mb-1">RX Power</label>
                                    <p class="fw-medium"><?php echo htmlspecialchars($device['rxPower']); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Optical Signal Readings -->
                <div class="card info-card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class='bx bx-signal-5 me-2'></i>Optical Signal Readings</h5>
                        <button class="btn btn-sm btn-outline-primary" id="refresh-optical">
                            <i class='bx bx-refresh me-1'></i> Refresh Optical Readings
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card bg-gradient-to-r from-green-100 to-green-50 p-4 h-100 shadow-sm rounded-lg border border-green-200">
                                    <h6 class="text-green-700 font-semibold mb-3">
                                        <i class='bx bx-upload me-2'></i>TX Power (Transmit)
                                    </h6>
                                    <div class="d-flex align-items-center">
                                        <span class="display-6 me-2 text-success"><?php echo htmlspecialchars($device['txPower'] ?? 'N/A'); ?></span>
                                    </div>
                                    <p class="text-muted small mt-2">Signal strength from device to network</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card bg-gradient-to-r from-blue-100 to-blue-50 p-4 h-100 shadow-sm rounded-lg border border-blue-200">
                                    <h6 class="text-blue-700 font-semibold mb-3">
                                        <i class='bx bx-download me-2'></i>RX Power (Receive)
                                    </h6>
                                    <div class="d-flex align-items-center">
                                        <span class="display-6 me-2 text-primary"><?php echo htmlspecialchars($device['rxPower'] ?? 'N/A'); ?></span>
                                    </div>
                                    <p class="text-muted small mt-2">Signal strength from network to device</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4 small">
                            <div class="alert alert-info py-2">
                                <i class='bx bx-info-circle me-2'></i>
                                Optical power readings may not be available for all device models. Click refresh to attempt retrieving the latest readings.
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($connectedClients)): ?>
                <!-- Connected Clients -->
                <div class="card table-card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class='bx bx-wifi me-2'></i>Connected Clients</h5>
                        <span class="badge bg-primary"><?php echo count($connectedClients); ?> Active</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Device</th>
                                        <th>Hostname</th>
                                        <th>IP Address</th>
                                        <th>MAC Address</th>
                                        <th>Status</th>
                                        <th>Last Seen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($connectedClients as $client): ?>
                                    <tr>
                                        <td>
                                            <?php
                                            $hostname = strtolower($client['hostname'] ?: '');
                                            if (strpos($hostname, 'galaxy') !== false || strpos($hostname, 'android') !== false || 
                                                strpos($hostname, 'a04s') !== false || strpos($hostname, 'iphone') !== false) {
                                                echo '<i class="bx bx-mobile text-primary fs-5"></i>';
                                            } elseif (strpos($hostname, 'pc') !== false || strpos($hostname, 'laptop') !== false ||
                                                      strpos($hostname, 'desktop') !== false || strpos($hostname, 'mac') !== false) {
                                                echo '<i class="bx bx-laptop text-info fs-5"></i>';
                                            } else {
                                                echo '<i class="bx bx-devices text-secondary fs-5"></i>';
                                            }
                                            ?>
                                        </td>
                                        <td class="fw-medium"><?php echo htmlspecialchars($client['hostname'] ?: 'Unknown Device'); ?></td>
                                        <td><?php echo htmlspecialchars($client['ip_address']); ?></td>
                                        <td><?php echo htmlspecialchars($client['mac_address'] ?: 'N/A'); ?></td>
                                        <td>
                                            <?php if ($client['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('Y-m-d H:i:s', strtotime($client['last_seen'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });
        
        // Toggle sidebar on small screens
        document.addEventListener('DOMContentLoaded', function() {
            const mediaQuery = window.matchMedia('(max-width: 768px)');
            if (mediaQuery.matches) {
                const sidebar = document.querySelector('.sidebar');
                if (sidebar) {
                    sidebar.classList.add('collapse');
                }
            }
            
            // Add optical readings refresh handler
            const refreshOpticalBtn = document.getElementById('refresh-optical');
            if (refreshOpticalBtn) {
                refreshOpticalBtn.addEventListener('click', function() {
                    this.disabled = true;
                    this.innerHTML = '<i class="bx bx-loader-alt bx-spin me-1"></i> Refreshing...';
                    
                    // Create an AJAX request to refresh optical readings
                    const xhr = new XMLHttpRequest();
                    xhr.open('GET', 'backend/api/refresh_optical.php?id=<?php echo $deviceId; ?>', true);
                    xhr.onload = function() {
                        if (this.status >= 200 && this.status < 300) {
                            // Reload the page to show updated data
                            window.location.reload();
                        } else {
                            alert('Error refreshing optical readings');
                            refreshOpticalBtn.disabled = false;
                            refreshOpticalBtn.innerHTML = '<i class="bx bx-refresh me-1"></i> Refresh Optical Readings';
                        }
                    };
                    xhr.onerror = function() {
                        alert('Network error while refreshing optical readings');
                        refreshOpticalBtn.disabled = false;
                        refreshOpticalBtn.innerHTML = '<i class="bx bx-refresh me-1"></i> Refresh Optical Readings';
                    };
                    xhr.send();
                });
            }
            
            // Add main data refresh handler
            const refreshDataBtn = document.getElementById('refresh-data-btn');
            if (refreshDataBtn) {
                refreshDataBtn.addEventListener('click', function() {
                    this.disabled = true;
                    this.innerHTML = '<i class="bx bx-loader-alt bx-spin me-1"></i> Refreshing...';
                    
                    // Simple reload to get the latest data
                    window.location.reload();
                });
            }
            
            // Set auto-refresh every 60 seconds to keep data current
            setTimeout(function() {
                window.location.reload();
            }, 60000); // 60 seconds
        });
    </script>
</body>
</html>
