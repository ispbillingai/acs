
<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../functions/device_functions.php';

$response = ['success' => false, 'message' => 'Invalid request'];

try {
    $database = new Database();
    $db = $database->getConnection();

    $deviceId = $_POST['device_id'] ?? null;
    $action = $_POST['action'] ?? null;

    if (!$deviceId) {
        throw new Exception("Device ID is required");
    }

    $logFile = __DIR__ . '/../../logs/configure.log';

    switch ($action) {
        case 'wifi':
            $ssid = $_POST['ssid'] ?? '';
            $password = $_POST['password'] ?? '';

            // Log WiFi configuration change
            $logEntry = date('Y-m-d H:i:s') . " - Device $deviceId: WiFi configuration changed. SSID: $ssid\n";
            file_put_contents($logFile, $logEntry, FILE_APPEND);

            $response = ['success' => true, 'message' => 'WiFi settings updated'];
            break;

        case 'wan':
            $ipAddress = $_POST['ip_address'] ?? '';
            $gateway = $_POST['gateway'] ?? '';

            // Log WAN configuration change
            $logEntry = date('Y-m-d H:i:s') . " - Device $deviceId: WAN configuration changed. IP: $ipAddress, Gateway: $gateway\n";
            file_put_contents($logFile, $logEntry, FILE_APPEND);

            $response = ['success' => true, 'message' => 'WAN settings updated'];
            break;

        case 'reboot':
            // Log device reboot
            $logEntry = date('Y-m-d H:i:s') . " - Device $deviceId: Reboot initiated\n";
            file_put_contents($logFile, $logEntry, FILE_APPEND);

            $response = ['success' => true, 'message' => 'Reboot command sent'];
            break;

        default:
            throw new Exception("Invalid action");
    }
} catch (Exception $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($response);
exit;
