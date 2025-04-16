
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
                            <button type="button" class="btn btn-sm btn-outline-primary"><i class='bx bx-refresh me-1'></i>Refresh</button>
                            <button type="button" class="btn btn-sm btn-outline-primary"><i class='bx bx-edit me-1'></i>Edit</button>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary"><i class='bx bx-cog me-1'></i>Configure</button>
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
                                    <h2 class="display-6 mb-0"><?php echo htmlspecialchars($device['uptime'] ?: 'N/A'); ?></h2>
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
                                    <p class="fw-medium"><?php echo htmlspecialchars($device['manufacturer'] ?: 'Huawei'); ?></p>
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
                                    <p class="fw-medium"><?php echo htmlspecialchars($device['uptime'] ?: 'N/A'); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted mb-1">Connected Clients</label>
                                    <p class="fw-medium"><?php echo htmlspecialchars($device['connectedClients'] ?: '0'); ?></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted mb-1">Last Contact</label>
                                    <p class="fw-medium"><?php echo date('Y-m-d H:i:s', strtotime($device['lastContact'])); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($connectedClients)): ?>
                <!-- Connected Clients -->
                <div class="card table-card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class='bx bx-wifi me-2'></i>Connected Clients</h5>
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
        });
    </script>
</body>
</html>
