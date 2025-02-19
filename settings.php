
<?php
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/auth/login.php';

// Initialize login handler
$loginHandler = new LoginHandler();

// Check if user is logged in
if (!$loginHandler->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Update user settings
        $stmt = $db->prepare("UPDATE users SET 
            username = ?, 
            timezone = ?,
            display_name = ? 
            WHERE id = ?");
            
        $stmt->execute([
            $_POST['username'],
            $_POST['timezone'],
            $_POST['display_name'],
            $_SESSION['user_id']
        ]);

        // If password is being updated
        if (!empty($_POST['new_password'])) {
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$_POST['new_password'], $_SESSION['user_id']]);
        }

        $_SESSION['success_message'] = "Settings updated successfully!";
        header('Location: settings.php');
        exit;
    } catch (Exception $e) {
        $error = "Error updating settings: " . $e->getMessage();
    }
}

// Get current user settings
try {
    $database = new Database();
    $db = $database->getConnection();
    $stmt = $db->prepare("SELECT username, timezone, display_name FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Error fetching user settings: " . $e->getMessage();
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
                <h1 class="text-xl font-semibold text-gray-800">Settings</h1>
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

            <div class="bg-white rounded-lg shadow-sm p-6">
                <form method="POST" class="space-y-6">
                    <!-- Display Name -->
                    <div>
                        <label for="display_name" class="block text-sm font-medium text-gray-700">Display Name</label>
                        <input type="text" name="display_name" id="display_name" 
                               value="<?php echo htmlspecialchars($user['display_name'] ?? ''); ?>"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <!-- Username -->
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                        <input type="text" name="username" id="username" 
                               value="<?php echo htmlspecialchars($user['username']); ?>"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <!-- Timezone -->
                    <div>
                        <label for="timezone" class="block text-sm font-medium text-gray-700">Timezone</label>
                        <select name="timezone" id="timezone" 
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <?php
                            $timezones = DateTimeZone::listIdentifiers();
                            foreach ($timezones as $tz) {
                                $selected = ($tz === ($user['timezone'] ?? 'Africa/Nairobi')) ? 'selected' : '';
                                echo "<option value=\"$tz\" $selected>$tz</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <!-- New Password -->
                    <div>
                        <label for="new_password" class="block text-sm font-medium text-gray-700">New Password (leave blank to keep current)</label>
                        <input type="password" name="new_password" id="new_password"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div class="pt-4">
                        <button type="submit" 
                                class="inline-flex justify-center rounded-md border border-transparent bg-blue-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </main>

        <?php include __DIR__ . '/backend/templates/footer.php'; ?>
    </div>
</div>
