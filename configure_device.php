
<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
                    'ssidPassword' => $row['ssid_password']
                ];
                return $device;
            }
            
            return null;
        } catch (PDOException $e) {
            return null;
        }
    }

    $device = getDevice($db, $deviceId);

    if (!$device) {
        header('Location: index.php');
        exit;
    }

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
    <title>Configure Device - ACS Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
                                <li class="breadcrumb-item"><a href="device.php?id=<?php echo $deviceId; ?>" class="text-decoration-none">Device Details</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Configure Device</li>
                            </ol>
                        </nav>
                        <h1 class="h2 mb-0 d-flex align-items-center">
                            Configure Device 
                            <span class="ms-3 badge <?php echo $device['status'] === 'online' ? 'bg-success' : 'bg-danger'; ?>">
                                <?php echo ucfirst($device['status']); ?>
                            </span>
                        </h1>
                        <p class="text-muted mt-2">
                            <?php echo htmlspecialchars($device['manufacturer'] . ' ' . $device['model']); ?> 
                            (S/N: <?php echo htmlspecialchars($device['serialNumber']); ?>)
                        </p>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="device.php?id=<?php echo $deviceId; ?>" class="btn btn-sm btn-outline-primary">
                            <i class='bx bx-arrow-back me-1'></i>Back to Device
                        </a>
                    </div>
                </div>

                <!-- Configuration Panel -->
                <div class="card mb-4">
                    <div class="card-header bg-white py-3">
                        <h5 class="card-title mb-0"><i class='bx bx-cog me-2'></i>Device Configuration</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($device['status'] === 'offline'): ?>
                        <div class="alert alert-warning" role="alert">
                            <i class='bx bx-error-circle me-2'></i>
                            This device is currently offline. Configuration changes may not take effect until the device is back online.
                        </div>
                        <?php endif; ?>
                        
                        <!-- Configuration Panel Integration -->
                        <div id="configuration-panel">
                            <!-- WiFi Configuration -->
                            <div class="mb-4">
                                <h3 class="h5 mb-3 d-flex align-items-center">
                                    <i class='bx bx-wifi me-2 text-primary'></i>WiFi Configuration
                                </h3>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="wifi-ssid" class="form-label">WiFi Network Name (SSID)</label>
                                        <input type="text" class="form-control" id="wifi-ssid" value="<?php echo htmlspecialchars($device['ssid'] ?? ''); ?>" placeholder="Enter network name">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="wifi-password" class="form-label">WiFi Password</label>
                                        <input type="password" class="form-control" id="wifi-password" value="<?php echo htmlspecialchars($device['ssidPassword'] ?? ''); ?>" placeholder="Enter password">
                                    </div>
                                    <div class="col-12">
                                        <button type="button" class="btn btn-primary" id="update-wifi-btn">
                                            <i class='bx bx-save me-1'></i>Update WiFi
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- WAN Configuration -->
                            <div class="mb-4">
                                <h3 class="h5 mb-3 d-flex align-items-center">
                                    <i class='bx bx-globe me-2 text-primary'></i>WAN Configuration
                                </h3>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="wan-ip" class="form-label">IP Address</label>
                                        <input type="text" class="form-control" id="wan-ip" value="<?php echo htmlspecialchars($device['ipAddress'] ?? ''); ?>" placeholder="e.g., 192.168.1.1">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="wan-gateway" class="form-label">Gateway</label>
                                        <input type="text" class="form-control" id="wan-gateway" placeholder="e.g., 192.168.1.254">
                                    </div>
                                    <div class="col-12">
                                        <button type="button" class="btn btn-primary" id="update-wan-btn">
                                            <i class='bx bx-save me-1'></i>Update WAN
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Device Control -->
                            <div>
                                <h3 class="h5 mb-3 d-flex align-items-center">
                                    <i class='bx bx-power-off me-2 text-danger'></i>Device Control
                                </h3>
                                <div class="alert alert-warning mb-3">
                                    <i class='bx bx-error-circle me-2'></i>
                                    Rebooting the device will temporarily disconnect all users. This process typically takes 1-2 minutes.
                                </div>
                                <button type="button" class="btn btn-danger" id="reboot-device-btn">
                                    <i class='bx bx-refresh me-1'></i>Reboot Device
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const deviceId = '<?php echo $deviceId; ?>';
            
            // Update WiFi configuration
            document.getElementById('update-wifi-btn').addEventListener('click', function() {
                const ssid = document.getElementById('wifi-ssid').value;
                const password = document.getElementById('wifi-password').value;
                
                if (!ssid) {
                    alert('Please enter a WiFi network name (SSID)');
                    return;
                }
                
                makeConfigRequest('wifi', { ssid, password });
            });
            
            // Update WAN configuration
            document.getElementById('update-wan-btn').addEventListener('click', function() {
                const ipAddress = document.getElementById('wan-ip').value;
                const gateway = document.getElementById('wan-gateway').value;
                
                if (!ipAddress) {
                    alert('Please enter an IP address');
                    return;
                }
                
                makeConfigRequest('wan', { ip_address: ipAddress, gateway });
            });
            
            // Reboot device
            document.getElementById('reboot-device-btn').addEventListener('click', function() {
                if (confirm('Are you sure you want to reboot this device? All connections will be temporarily disrupted.')) {
                    makeConfigRequest('reboot', {});
                }
            });
            
            // Function to make configuration request
            function makeConfigRequest(action, data) {
                // Create form data
                const formData = new FormData();
                formData.append('device_id', deviceId);
                formData.append('action', action);
                
                // Add all data properties to form data
                Object.entries(data).forEach(([key, value]) => {
                    formData.append(key, value);
                });
                
                // Show loading state
                const button = document.getElementById(`update-${action}-btn`) || document.getElementById('reboot-device-btn');
                const originalText = button.innerHTML;
                button.disabled = true;
                button.innerHTML = '<i class="bx bx-loader-alt bx-spin me-1"></i> Processing...';
                
                // Make API request
                fetch('/backend/api/device_configure.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    // Show success or error message
                    if (result.success) {
                        alert(result.message);
                    } else {
                        alert('Error: ' + (result.message || 'Configuration failed'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error: Configuration request failed');
                })
                .finally(() => {
                    // Restore button state
                    button.disabled = false;
                    button.innerHTML = originalText;
                });
            }
        });
    </script>
</body>
</html>
