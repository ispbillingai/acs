
<?php
// Enable error reporting to device.log
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('error_log', __DIR__ . '/device.log');

require_once __DIR__ . '/backend/config/database.php';

// Add logging function
function debug_log($message) {
    error_log("[DEBUG] " . print_r($message, true));
}

try {
    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();
    debug_log("Database connection established");

    // Get device ID from URL
    $deviceId = isset($_GET['id']) ? $_GET['id'] : null;
    debug_log("Device ID from URL: " . $deviceId);

    if (!$deviceId) {
        header('Location: index.php');
        exit;
    }

    function getDevice($db, $deviceId) {
        $sql = "SELECT * FROM devices WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => $deviceId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    function countConnectedClients($db, $deviceId) {
        $sql = "SELECT COUNT(*) FROM connected_clients WHERE device_id = :device_id AND is_active = 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':device_id' => $deviceId]);
        return $stmt->fetchColumn();
    }

    function getConnectedClients($db, $deviceId) {
        $sql = "SELECT * FROM connected_clients WHERE device_id = :device_id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':device_id' => $deviceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $device = getDevice($db, $deviceId);
    debug_log("Initial device data: " . print_r($device, true));

    if (!$device) {
        debug_log("Device not found for ID: " . $deviceId);
        header('Location: index.php');
        exit;
    }

    // Get connected clients count from database
    $connectedClientsCount = countConnectedClients($db, $deviceId);
    debug_log("Connected clients count from function: " . $connectedClientsCount);
    
    // Check both connected_clients and connected_devices columns
    $deviceConnectedClients = isset($device['connected_clients']) ? (int)$device['connected_clients'] : 0;
    $deviceConnectedDevices = isset($device['connected_devices']) ? (int)$device['connected_devices'] : 0;
    
    debug_log("Current device connected_clients value: " . $deviceConnectedClients);
    debug_log("Current device connected_devices value: " . $deviceConnectedDevices);

    // Determine which value to use (connected_devices has priority)
    $currentDeviceCount = $deviceConnectedDevices > 0 ? $deviceConnectedDevices : $deviceConnectedClients;
    debug_log("Current device count determined as: " . $currentDeviceCount);

    // Update device with connected clients count if different
    if ($currentDeviceCount != $connectedClientsCount) {
        debug_log("Updating connected clients count from {$currentDeviceCount} to {$connectedClientsCount}");
        
        // Update both columns to maintain consistency
        $updateSql = "UPDATE devices SET connected_clients = :count, connected_devices = :count WHERE id = :id";
        $updateStmt = $db->prepare($updateSql);
        $result = $updateStmt->execute([
            ':count' => $connectedClientsCount,
            ':id' => $deviceId
        ]);
        
        debug_log("Update result: " . ($result ? "success" : "failed"));
        
        // Update the local device array for display
        $device['connected_clients'] = $connectedClientsCount;
        $device['connected_devices'] = $connectedClientsCount;
        
        debug_log("Device data after update: " . print_r($device, true));
    } else {
        debug_log("No update needed. Current count matches database count: " . $currentDeviceCount);
    }

    // Get connected clients details
    $connectedClients = getConnectedClients($db, $deviceId);
    debug_log("Connected clients details: " . print_r($connectedClients, true));
    debug_log("Total connected clients found: " . count($connectedClients));

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Device Details</title>
    </head>
    <body>
        <h1>Device Details</h1>
        <?php if ($device): ?>
            <p>Serial Number: <?= htmlspecialchars($device['serial_number']) ?></p>
            <p>Manufacturer: <?= htmlspecialchars($device['manufacturer']) ?></p>
            <p>Model: <?= htmlspecialchars($device['model_name']) ?></p>
            <p>Connected Clients: <?= htmlspecialchars($device['connected_devices'] ?? $device['connected_clients'] ?? '0') ?></p>

            <h2>Connected Clients</h2>
            <?php if ($connectedClients): ?>
                <ul>
                    <?php foreach ($connectedClients as $client): ?>
                        <li><?= htmlspecialchars($client['hostname'] ?? 'Unknown') ?> - <?= htmlspecialchars($client['ip_address']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No connected clients found.</p>
            <?php endif; ?>
        <?php else: ?>
            <p>Device not found.</p>
        <?php endif; ?>
    </body>
    </html>
    <?php

} catch (Exception $e) {
    debug_log("Exception: " . $e->getMessage());
}
?>
