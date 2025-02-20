
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = $database->getConnection();
        
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

        $_SESSION['success_message'] = "TR069 configuration updated successfully!";
        header('Location: configuration.php');
        exit;
    } catch (Exception $e) {
        $error = "Error updating configuration: " . $e->getMessage();
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
                <h1 class="text-xl font-semibold text-gray-800">TR069 Configuration</h1>
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
                        <button type="submit" 
                                class="inline-flex justify-center rounded-md border border-transparent bg-blue-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                            Update Configuration
                        </button>
                    </div>
                </form>
            </div>
        </main>

        <?php include __DIR__ . '/backend/templates/footer.php'; ?>
    </div>
</div>
