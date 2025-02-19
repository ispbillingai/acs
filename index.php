
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

// Initialize database connection
try {
    $database = new Database();
    $db = $database->getConnection();
    error_log("Database connection successful");
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

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

// Wrap main logic in try-catch for debugging
try {
    $devices = getDevices($db);
    error_log("Successfully fetched devices: " . count($devices));

    // Calculate device statistics with 10-minute threshold
    $tenMinutesAgo = date('Y-m-d H:i:s', strtotime('-10 minutes'));
    $totalDevices = count($devices);
    $onlineDevices = count(array_filter($devices, function($device) use ($tenMinutesAgo) {
        return strtotime($device['lastContact']) >= strtotime($tenMinutesAgo);
    }));
    $offlineDevices = $totalDevices - $onlineDevices;

    error_log("Statistics calculated - Total: $totalDevices, Online: $onlineDevices, Offline: $offlineDevices");
} catch (Exception $e) {
    error_log("Error in main logic: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    die("An error occurred: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ACS Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50">
    <div class="p-6">
        <div class="max-w-7xl mx-auto space-y-8">
            <div class="space-y-2">
                <h1 class="text-3xl font-semibold tracking-tight">
                    ACS Dashboard
                </h1>
                <p class="text-gray-600">
                    Monitor and manage your TR-069 devices
                </p>
            </div>

            <!-- Device Stats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-white p-6 rounded-lg shadow">
                    <h3 class="text-sm font-medium text-gray-500">Total Devices</h3>
                    <p class="text-2xl font-semibold"><?php echo $totalDevices; ?></p>
                </div>
                <div class="bg-white p-6 rounded-lg shadow">
                    <h3 class="text-sm font-medium text-gray-500">Online Devices</h3>
                    <p class="text-2xl font-semibold text-green-600"><?php echo $onlineDevices; ?></p>
                </div>
                <div class="bg-white p-6 rounded-lg shadow">
                    <h3 class="text-sm font-medium text-gray-500">Offline Devices</h3>
                    <p class="text-2xl font-semibold text-red-600"><?php echo $offlineDevices; ?></p>
                </div>
            </div>

            <!-- Device List -->
            <div>
                <h2 class="text-xl font-semibold mb-4">Connected Devices</h2>
                <?php if (empty($devices)): ?>
                    <div class="bg-blue-50 border border-blue-200 text-blue-700 p-4 rounded">
                        No devices connected yet. Devices will appear here when they connect to the ACS.
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($devices as $device): 
                            $isOnline = strtotime($device['lastContact']) >= strtotime($tenMinutesAgo);
                        ?>
                            <div class="bg-white p-6 rounded-lg shadow">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-semibold">
                                        <?php echo htmlspecialchars($device['manufacturer'] ?: 'Unknown Manufacturer'); ?>
                                    </h3>
                                    <span class="px-2 py-1 text-sm rounded-full <?php echo $isOnline ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
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
                                       class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                        View Details →
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
