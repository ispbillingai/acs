<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('error_log', __DIR__ . '/acs.log');

require_once __DIR__ . '/backend/config/database.php';

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

    // Calculate device statistics
    $totalDevices = count($devices);
    $onlineDevices = count(array_filter($devices, function($device) {
        return $device['status'] === 'online';
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
                        <?php foreach ($devices as $device): ?>
                            <div class="bg-white p-6 rounded-lg shadow">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-semibold">
                                        <?php echo htmlspecialchars($device['manufacturer']); ?>
                                    </h3>
                                    <span class="px-2 py-1 text-sm rounded-full <?php echo $device['status'] === 'online' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                        <?php echo ucfirst($device['status']); ?>
                                    </span>
                                </div>
                                <div class="space-y-2 text-sm text-gray-600">
                                    <p>
                                        <span class="font-medium">Model:</span>
                                        <?php echo htmlspecialchars($device['model']); ?>
                                    </p>
                                    <p>
                                        <span class="font-medium">Serial Number:</span>
                                        <?php echo htmlspecialchars($device['serialNumber']); ?>
                                    </p>
                                    <p>
                                        <span class="font-medium">IP Address:</span>
                                        <?php echo htmlspecialchars($device['ipAddress']); ?>
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
