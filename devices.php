
<?php
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/auth/login.php';
require_once __DIR__ . '/backend/functions/device_functions.php';

// Initialize login handler
$loginHandler = new LoginHandler();

// Check if user is logged in
if (!$loginHandler->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Get status filter
$status = isset($_GET['status']) ? $_GET['status'] : 'all';

// Initialize database connection and fetch devices
try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Fetch devices
    $devices = getDevices($db);
    $tenMinutesAgo = date('Y-m-d H:i:s', strtotime('-10 minutes'));
    
    // Update device status based on last contact time
    foreach ($devices as $key => $device) {
        $isOnline = strtotime($device['lastContact']) >= strtotime($tenMinutesAgo);
        $devices[$key]['status'] = $isOnline ? 'online' : 'offline';
        
        // Also update status in database to ensure consistency
        $updateStmt = $db->prepare("UPDATE devices SET status = :status WHERE id = :id");
        $updateStmt->execute([
            ':status' => $isOnline ? 'online' : 'offline',
            ':id' => $device['id']
        ]);
    }
    
    // Count online and offline devices for footer stats
    $onlineDevices = count(array_filter($devices, function($d) use ($tenMinutesAgo) {
        return strtotime($d['lastContact']) >= strtotime($tenMinutesAgo);
    }));
    
    $offlineDevices = count($devices) - $onlineDevices;
    
    // Filter devices based on status
    if ($status !== 'all') {
        $devices = array_filter($devices, function($device) use ($status, $tenMinutesAgo) {
            $isOnline = strtotime($device['lastContact']) >= strtotime($tenMinutesAgo);
            return ($status === 'online') ? $isOnline : !$isOnline;
        });
    }

} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

include __DIR__ . '/backend/templates/header.php';
?>

<div class="min-h-screen flex">
    <?php include __DIR__ . '/backend/templates/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 ml-64">
        <!-- Header -->
        <header class="bg-white shadow-sm">
            <div class="mx-auto px-6 py-4 flex justify-between items-center">
                <h1 class="text-xl font-semibold text-gray-800">
                    <?php 
                    echo ucfirst($status) . ' Devices';
                    ?>
                </h1>
                <div class="flex items-center">
                    <span class="text-sm text-gray-600"><?php echo date('F j, Y'); ?></span>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="p-6">
            <div class="bg-white rounded-lg shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Manufacturer</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Model</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Serial Number</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP Address</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Contact</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($devices as $device): 
                                $isOnline = strtotime($device['lastContact']) >= strtotime($tenMinutesAgo);
                            ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $isOnline ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <?php echo $isOnline ? 'Online' : 'Offline'; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($device['manufacturer'] ?: 'Unknown'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($device['model'] ?: 'Unknown'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($device['serialNumber']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($device['ipAddress']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('Y-m-d H:i:s', strtotime($device['lastContact'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <a href="device.php?id=<?php echo urlencode($device['id']); ?>" 
                                           class="text-blue-600 hover:text-blue-900">View Details</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>

        <?php include __DIR__ . '/backend/templates/footer.php'; ?>
    </div>
</div>
