
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

    // Get device data from database
    $sql = "SELECT * FROM devices WHERE id = :id";
    $stmt = $db->prepare($sql);
    $stmt->execute([':id' => $deviceId]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    debug_log("Device data: " . print_r($device, true));

    if (!$device) {
        debug_log("Device not found for ID: " . $deviceId);
        header('Location: index.php');
        exit;
    }

    // Get connected clients details
    $clientsSql = "SELECT * FROM connected_clients WHERE device_id = :device_id";
    $clientsStmt = $db->prepare($clientsSql);
    $clientsStmt->execute([':device_id' => $deviceId]);
    $connectedClients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);
    debug_log("Connected clients details: " . print_r($connectedClients, true));

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
            <p>Connected Clients: <?= htmlspecialchars($device['connected_devices']) ?></p>

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
