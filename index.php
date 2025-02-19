<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('error_log', __DIR__ . '/acs.log');

require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/auth/login.php';

// Initialize login handler
$loginHandler = new LoginHandler();

// Check if user is logged in
if (!$loginHandler->isLoggedIn()) {
    // If it's a login attempt
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['password'])) {
        $result = $loginHandler->login($_POST['username'], $_POST['password']);
        if (!$result['success']) {
            $error = $result['message'];
        } else {
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
    
    // Show login form if not logged in
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ACS Login</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="min-h-screen bg-gray-50 flex items-center justify-center">
        <div class="max-w-md w-full space-y-8 p-8 bg-white rounded-lg shadow">
            <div>
                <h2 class="text-center text-3xl font-extrabold text-gray-900">
                    ACS Dashboard Login
                </h2>
            </div>
            <?php if (isset($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <form class="mt-8 space-y-6" method="POST">
                <div class="rounded-md shadow-sm space-y-4">
                    <div>
                        <label for="username" class="sr-only">Username</label>
                        <input id="username" name="username" type="text" required 
                               class="appearance-none rounded relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 focus:outline-none focus:ring-blue-500 focus:border-blue-500 focus:z-10 sm:text-sm" 
                               placeholder="Username">
                    </div>
                    <div>
                        <label for="password" class="sr-only">Password</label>
                        <input id="password" name="password" type="password" required 
                               class="appearance-none rounded relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 focus:outline-none focus:ring-blue-500 focus:border-blue-500 focus:z-10 sm:text-sm" 
                               placeholder="Password">
                    </div>
                </div>

                <div>
                    <button type="submit" 
                            class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Sign in
                    </button>
                </div>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Initialize database connection and fetch devices
try {
    $database = new Database();
    $db = $database->getConnection();
    error_log("Database connection successful");

    // Fetch devices
    function getDevices($db) {
        try {
            $sql = "SELECT 
                    id,
                    serial_number as serialNumber,
                    manufacturer,
                    model_name as model,
                    status,
                    last_contact as lastContact,
                    ip_address as ipAddress,
                    software_version as softwareVersion,
                    hardware_version as hardwareVersion
                    FROM devices 
                    ORDER BY last_contact DESC";
            
            error_log("Executing SQL query: " . $sql);
            
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("Found " . count($devices) . " devices");
            error_log("Devices data: " . print_r($devices, true));
            
            return $devices;
        } catch (PDOException $e) {
            error_log("Database error in getDevices: " . $e->getMessage());
            error_log("SQL State: " . $e->getCode());
            return [];
        }
    }

    // Fetch devices and calculate statistics
    $devices = getDevices($db);
    $tenMinutesAgo = date('Y-m-d H:i:s', strtotime('-10 minutes'));
    $totalDevices = count($devices);
    $onlineDevices = count(array_filter($devices, function($device) use ($tenMinutesAgo) {
        return strtotime($device['lastContact']) >= strtotime($tenMinutesAgo);
    }));
    $offlineDevices = $totalDevices - $onlineDevices;

} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ACS Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex">
        <!-- Sidebar -->
        <aside class="w-64 bg-white shadow-lg fixed h-full">
            <div class="p-4 border-b">
                <h2 class="text-xl font-bold text-gray-800">ACS Dashboard</h2>
            </div>
            <nav class="mt-4">
                <a href="#" class="flex items-center px-4 py-3 text-gray-700 bg-gray-100">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                    </svg>
                    Dashboard
                </a>
                <a href="#" class="flex items-center px-4 py-3 text-gray-600 hover:bg-gray-100">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    Statistics
                </a>
                <a href="logout.php" class="flex items-center px-4 py-3 text-gray-600 hover:bg-gray-100">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                    </svg>
                    Logout
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 ml-64">
            <!-- Header -->
            <header class="bg-white shadow-sm">
                <div class="mx-auto px-6 py-4 flex justify-between items-center">
                    <h1 class="text-xl font-semibold text-gray-800">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></h1>
                    <div class="flex items-center">
                        <span class="text-sm text-gray-600"><?php echo date('F j, Y'); ?></span>
                    </div>
                </div>
            </header>

            <!-- Main Content Area -->
            <main class="p-6">
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-white rounded-lg shadow-sm p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100 text-blue-500">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                </svg>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-gray-500 text-sm">Total Devices</h3>
                                <p class="text-2xl font-semibold text-gray-800"><?php echo $totalDevices; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow-sm p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-100 text-green-500">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-gray-500 text-sm">Online Devices</h3>
                                <p class="text-2xl font-semibold text-green-600"><?php echo $onlineDevices; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow-sm p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-red-100 text-red-500">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-gray-500 text-sm">Offline Devices</h3>
                                <p class="text-2xl font-semibold text-red-600"><?php echo $offlineDevices; ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div class="bg-white rounded-lg shadow-sm p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Device Status Distribution</h3>
                        <canvas id="deviceStatusChart" height="200"></canvas>
                    </div>
                    <div class="bg-white rounded-lg shadow-sm p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Device Activity Timeline</h3>
                        <canvas id="deviceActivityChart" height="200"></canvas>
                    </div>
                </div>

                <!-- Devices List -->
                <div class="bg-white rounded-lg shadow-sm">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Connected Devices</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <?php foreach ($devices as $device): 
                                $isOnline = strtotime($device['lastContact']) >= strtotime($tenMinutesAgo);
                            ?>
                                <div class="bg-gray-50 rounded-lg p-6 border border-gray-200">
                                    <div class="flex items-center justify-between mb-4">
                                        <h4 class="text-lg font-semibold text-gray-800">
                                            <?php echo htmlspecialchars($device['manufacturer'] ?: 'Unknown Manufacturer'); ?>
                                        </h4>
                                        <span class="px-3 py-1 text-sm rounded-full <?php echo $isOnline ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                            <?php echo $isOnline ? 'Online' : 'Offline'; ?>
                                        </span>
                                    </div>
                                    <div class="space-y-2 text-sm text-gray-600">
                                        <p>
                                            <span class="font-medium">Model:</span>
                                            <?php echo htmlspecialchars($device['model'] ?: 'Unknown Model'); ?>
                                        </p>
                                        <p>
                                            <span class="font-medium">Serial Number:</span>
                                            <?php echo htmlspecialchars($device['serialNumber'] ?: 'N/A'); ?>
                                        </p>
                                        <p>
                                            <span class="font-medium">IP Address:</span>
                                            <?php echo htmlspecialchars($device['ipAddress'] ?: 'N/A'); ?>
                                        </p>
                                        <p>
                                            <span class="font-medium">Last Contact:</span>
                                            <?php echo date('Y-m-d H:i:s', strtotime($device['lastContact'])); ?>
                                        </p>
                                    </div>
                                    <div class="mt-4">
                                        <a href="device.php?id=<?php echo urlencode($device['id']); ?>" 
                                           class="text-blue-600 hover:text-blue-800 text-sm font-medium inline-flex items-center">
                                            View Details
                                            <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                            </svg>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </main>

            <!-- Footer -->
            <footer class="bg-white border-t mt-auto">
                <div class="mx-auto px-6 py-4">
                    <p class="text-center text-sm text-gray-600">
                        © <?php echo date('Y'); ?> ACS Dashboard. All rights reserved.
                    </p>
                </div>
            </footer>
        </div>
    </div>

    <!-- Charts Initialization -->
    <script>
        // Device Status Chart
        const statusCtx = document.getElementById('deviceStatusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Online', 'Offline'],
                datasets: [{
                    data: [<?php echo $onlineDevices; ?>, <?php echo $offlineDevices; ?>],
                    backgroundColor: ['#10B981', '#EF4444'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Device Activity Chart
        const activityCtx = document.getElementById('deviceActivityChart').getContext('2d');
        new Chart(activityCtx, {
            type: 'line',
            data: {
                labels: ['6h ago', '5h ago', '4h ago', '3h ago', '2h ago', '1h ago', 'Now'],
                datasets: [{
                    label: 'Active Devices',
                    data: [65, 59, 80, 81, 56, 55, <?php echo $onlineDevices; ?>],
                    fill: true,
                    borderColor: '#6366F1',
                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>
</body>
</html>
