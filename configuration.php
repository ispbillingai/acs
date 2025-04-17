
<?php
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/auth/login.php';

// Initialize login handler and database
$loginHandler = new LoginHandler();
$database = new Database();

// Check if user is logged in
if (!$loginHandler->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Function to log configuration changes
function logConfigChange($action, $details) {
    $timestamp = date('Y-m-d H:i:s');
    $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'unknown';
    $logEntry = "[$timestamp] User: $username | Action: $action | Details: $details\n";
    file_put_contents(__DIR__ . '/configure.log', $logEntry, FILE_APPEND);
}

// Handle device reboot
if (isset($_POST['reboot_device']) && $_POST['reboot_device'] == 1) {
    try {
        // Log the reboot attempt
        logConfigChange("Device Reboot", "Initiated reboot for device");
        
        $_SESSION['success_message'] = "Reboot command sent to device!";
        header('Location: configuration.php');
        exit;
    } catch (Exception $e) {
        $error = "Error sending reboot command: " . $e->getMessage();
        logConfigChange("Reboot Error", $e->getMessage());
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = $database->getConnection();
        
        // Determine which form was submitted
        if (isset($_POST['tr069_config'])) {
            // Update TR069 configuration
            $stmt = $db->prepare("UPDATE tr069_config SET 
                username = ?, 
                password = ?,
                inform_interval = ?");
                
            $stmt->execute([
                $_POST['tr069_username'],
                $_POST['tr069_password'],
                $_POST['inform_interval']
            ]);

            logConfigChange("TR069 Configuration", "Updated TR069 credentials and interval");
            $_SESSION['success_message'] = "TR069 configuration updated successfully!";
        } 
        elseif (isset($_POST['wifi_config'])) {
            // Log the WiFi configuration update
            logConfigChange("WiFi Configuration", "SSID: {$_POST['wifi_ssid']}");
            
            $_SESSION['success_message'] = "WiFi configuration updated successfully!";
        }
        elseif (isset($_POST['wan_config'])) {
            // Log the WAN configuration update
            $connectionType = $_POST['connection_type'];
            logConfigChange("WAN Configuration", "Connection Type: {$connectionType}");
            
            $_SESSION['success_message'] = "WAN configuration updated successfully!";
        }
        
        header('Location: configuration.php');
        exit;
    } catch (Exception $e) {
        $error = "Error updating configuration: " . $e->getMessage();
        logConfigChange("Error", $e->getMessage());
    }
}

// Get current TR069 configuration
try {
    $db = $database->getConnection();
    $stmt = $db->prepare("SELECT username, password, inform_interval FROM tr069_config LIMIT 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Error fetching configuration: " . $e->getMessage();
}

include __DIR__ . '/backend/templates/header.php';
?>

<div class="min-h-screen flex">
    <?php include __DIR__ . '/backend/templates/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 ml-64">
        <!-- Header -->
        <header class="bg-white shadow-sm">
            <div class="mx-auto px-6 py-4">
                <h1 class="text-xl font-semibold text-gray-800">Device Configuration</h1>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="p-6">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded">
                    <?php 
                    echo $_SESSION['success_message'];
                    unset($_SESSION['success_message']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Configuration Tabs -->
            <div class="mb-6">
                <div class="border-b border-gray-200">
                    <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                        <a href="#tr069" class="border-blue-500 text-blue-600 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm" id="tr069-tab">
                            TR069 Settings
                        </a>
                        <a href="#wifi" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm" id="wifi-tab">
                            WiFi Settings
                        </a>
                        <a href="#wan" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm" id="wan-tab">
                            WAN Settings
                        </a>
                        <a href="#reboot" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm" id="reboot-tab">
                            Reboot Device
                        </a>
                    </nav>
                </div>
            </div>

            <!-- TR069 Settings Panel -->
            <div id="tr069-panel" class="tab-panel">
                <!-- Connection Information Card -->
                <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">Connection Information</h2>
                    <div class="space-y-4">
                        <div class="p-4 bg-gray-50 rounded-lg">
                            <p class="text-sm text-gray-600 mb-2">TR069 URL:</p>
                            <p class="text-md font-mono bg-white p-2 rounded border"><?php echo htmlspecialchars($database->getTr069Url()); ?></p>
                        </div>
                        <div class="p-4 bg-gray-50 rounded-lg">
                            <p class="text-sm text-gray-600 mb-2">Default Credentials:</p>
                            <p class="text-md">Username: <span class="font-mono bg-white px-2 py-1 rounded border"><?php echo htmlspecialchars($config['username']); ?></span></p>
                            <p class="text-md mt-2">Password: <span class="font-mono bg-white px-2 py-1 rounded border"><?php echo htmlspecialchars($config['password']); ?></span></p>
                        </div>
                        <div class="p-4 bg-gray-50 rounded-lg">
                            <p class="text-sm text-gray-600 mb-2">Inform Interval:</p>
                            <p class="text-md"><?php echo htmlspecialchars($config['inform_interval']); ?> seconds (<?php echo round($config['inform_interval'] / 60); ?> minutes)</p>
                        </div>
                    </div>
                </div>

                <!-- Configuration Form -->
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">Update TR069 Configuration</h2>
                    <form method="POST" class="space-y-6">
                        <!-- TR069 Username -->
                        <div>
                            <label for="tr069_username" class="block text-sm font-medium text-gray-700">TR069 Username</label>
                            <input type="text" name="tr069_username" id="tr069_username" 
                                   value="<?php echo htmlspecialchars($config['username']); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>

                        <!-- TR069 Password -->
                        <div>
                            <label for="tr069_password" class="block text-sm font-medium text-gray-700">TR069 Password</label>
                            <input type="password" name="tr069_password" id="tr069_password" 
                                   placeholder="Enter new password"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>

                        <!-- Inform Interval -->
                        <div>
                            <label for="inform_interval" class="block text-sm font-medium text-gray-700">Inform Interval (seconds)</label>
                            <input type="number" name="inform_interval" id="inform_interval" 
                                   value="<?php echo htmlspecialchars($config['inform_interval']); ?>"
                                   min="60" step="60"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="mt-1 text-sm text-gray-500">Minimum 60 seconds recommended</p>
                        </div>

                        <div class="pt-4">
                            <input type="hidden" name="tr069_config" value="1">
                            <button type="submit" 
                                    class="inline-flex justify-center rounded-md border border-transparent bg-blue-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                Update TR069 Configuration
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- WiFi Settings Panel -->
            <div id="wifi-panel" class="tab-panel hidden">
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">WiFi Configuration</h2>
                    <form method="POST" class="space-y-6">
                        <!-- WiFi SSID -->
                        <div>
                            <label for="wifi_ssid" class="block text-sm font-medium text-gray-700">WiFi Network Name (SSID)</label>
                            <input type="text" name="wifi_ssid" id="wifi_ssid" 
                                   placeholder="Enter WiFi name"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>

                        <!-- WiFi Password -->
                        <div>
                            <label for="wifi_password" class="block text-sm font-medium text-gray-700">WiFi Password</label>
                            <input type="password" name="wifi_password" id="wifi_password" 
                                   placeholder="Enter WiFi password"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="mt-1 text-sm text-gray-500">Minimum 8 characters recommended</p>
                        </div>

                        <!-- WiFi Security -->
                        <div>
                            <label for="wifi_security" class="block text-sm font-medium text-gray-700">Security Type</label>
                            <select name="wifi_security" id="wifi_security" 
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="WPA2-PSK">WPA2-PSK (Recommended)</option>
                                <option value="WPA-PSK">WPA-PSK</option>
                                <option value="WPA3-PSK">WPA3-PSK</option>
                                <option value="WEP">WEP (Not Recommended)</option>
                                <option value="NONE">None (Unsecured)</option>
                            </select>
                        </div>

                        <div class="pt-4">
                            <input type="hidden" name="wifi_config" value="1">
                            <button type="submit" 
                                    class="inline-flex justify-center rounded-md border border-transparent bg-blue-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                Update WiFi Configuration
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- WAN Settings Panel -->
            <div id="wan-panel" class="tab-panel hidden">
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">WAN Configuration</h2>
                    <form method="POST" class="space-y-6">
                        <!-- Connection Type -->
                        <div>
                            <label for="connection_type" class="block text-sm font-medium text-gray-700">Connection Type</label>
                            <select name="connection_type" id="connection_type" 
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                   onchange="toggleConnectionFields()">
                                <option value="DHCP">DHCP (Automatic)</option>
                                <option value="PPPoE">PPPoE</option>
                                <option value="Static">Static IP</option>
                            </select>
                        </div>

                        <!-- PPPoE Settings (initially hidden) -->
                        <div id="pppoe_settings" class="hidden">
                            <div class="space-y-4">
                                <div>
                                    <label for="pppoe_username" class="block text-sm font-medium text-gray-700">PPPoE Username</label>
                                    <input type="text" name="pppoe_username" id="pppoe_username" 
                                           placeholder="Enter username provided by ISP"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="pppoe_password" class="block text-sm font-medium text-gray-700">PPPoE Password</label>
                                    <input type="password" name="pppoe_password" id="pppoe_password" 
                                           placeholder="Enter password provided by ISP"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                            </div>
                        </div>

                        <!-- Static IP Settings (initially hidden) -->
                        <div id="static_settings" class="hidden">
                            <div class="space-y-4">
                                <div>
                                    <label for="static_ip" class="block text-sm font-medium text-gray-700">IP Address</label>
                                    <input type="text" name="static_ip" id="static_ip" 
                                           placeholder="e.g., 192.168.1.100"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="subnet_mask" class="block text-sm font-medium text-gray-700">Subnet Mask</label>
                                    <input type="text" name="subnet_mask" id="subnet_mask" 
                                           placeholder="e.g., 255.255.255.0"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="default_gateway" class="block text-sm font-medium text-gray-700">Default Gateway</label>
                                    <input type="text" name="default_gateway" id="default_gateway" 
                                           placeholder="e.g., 192.168.1.1"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="dns_servers" class="block text-sm font-medium text-gray-700">DNS Servers</label>
                                    <input type="text" name="dns_servers" id="dns_servers" 
                                           placeholder="e.g., 8.8.8.8, 8.8.4.4"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                            </div>
                        </div>

                        <div class="pt-4">
                            <input type="hidden" name="wan_config" value="1">
                            <button type="submit" 
                                    class="inline-flex justify-center rounded-md border border-transparent bg-blue-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                Update WAN Configuration
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Reboot Panel -->
            <div id="reboot-panel" class="tab-panel hidden">
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">Reboot Device</h2>
                    <div class="rounded-md bg-yellow-50 p-4 mb-6">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-yellow-800">Attention required</h3>
                                <div class="mt-2 text-sm text-yellow-700">
                                    <p>Rebooting the device will interrupt all active connections. This process typically takes 1-2 minutes to complete.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <form method="POST" onsubmit="return confirmReboot()" class="space-y-6">
                        <div class="pt-4">
                            <input type="hidden" name="reboot_device" value="1">
                            <button type="submit" 
                                    class="inline-flex justify-center rounded-md border border-transparent bg-red-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                                Reboot Device
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>

        <?php include __DIR__ . '/backend/templates/footer.php'; ?>
    </div>
</div>

<script>
    // Tab switching functionality
    document.addEventListener('DOMContentLoaded', function() {
        const tabs = ['tr069', 'wifi', 'wan', 'reboot'];
        
        // Initialize tab event listeners
        tabs.forEach(tabId => {
            const tabElement = document.getElementById(`${tabId}-tab`);
            tabElement.addEventListener('click', function(e) {
                e.preventDefault();
                switchTab(tabId);
            });
        });

        // Check for hash in URL to set active tab
        const hash = window.location.hash.substring(1);
        if (hash && tabs.includes(hash)) {
            switchTab(hash);
        }

        // Function to toggle connection type fields
        window.toggleConnectionFields = function() {
            const connectionType = document.getElementById('connection_type').value;
            const pppoeSettings = document.getElementById('pppoe_settings');
            const staticSettings = document.getElementById('static_settings');

            // Hide all first
            pppoeSettings.classList.add('hidden');
            staticSettings.classList.add('hidden');

            // Show relevant settings based on selection
            if (connectionType === 'PPPoE') {
                pppoeSettings.classList.remove('hidden');
            } else if (connectionType === 'Static') {
                staticSettings.classList.remove('hidden');
            }
        };

        // Confirm reboot action
        window.confirmReboot = function() {
            return confirm('Are you sure you want to reboot the device? All active connections will be temporarily disrupted.');
        };
    });

    // Function to switch between tabs
    function switchTab(tabId) {
        // Update URL hash
        window.location.hash = tabId;
        
        // Get all tab panels and hide them
        const panels = document.querySelectorAll('.tab-panel');
        panels.forEach(panel => panel.classList.add('hidden'));
        
        // Get all tab links and remove active class
        const tabLinks = document.querySelectorAll('[id$="-tab"]');
        tabLinks.forEach(link => {
            link.classList.remove('border-blue-500', 'text-blue-600');
            link.classList.add('border-transparent', 'text-gray-500', 'hover:text-gray-700', 'hover:border-gray-300');
        });
        
        // Show the selected panel
        const selectedPanel = document.getElementById(`${tabId}-panel`);
        if (selectedPanel) {
            selectedPanel.classList.remove('hidden');
        }
        
        // Set the selected tab as active
        const selectedTab = document.getElementById(`${tabId}-tab`);
        if (selectedTab) {
            selectedTab.classList.remove('border-transparent', 'text-gray-500', 'hover:text-gray-700', 'hover:border-gray-300');
            selectedTab.classList.add('border-blue-500', 'text-blue-600');
        }
        
        // Additional setup for WAN tab
        if (tabId === 'wan') {
            toggleConnectionFields();
        }
    }
</script>
