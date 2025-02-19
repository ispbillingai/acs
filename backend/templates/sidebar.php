
<aside class="w-64 bg-white shadow-lg fixed h-full">
    <div class="p-4 border-b">
        <h2 class="text-xl font-bold text-gray-800">ACS Dashboard</h2>
    </div>
    <nav class="mt-4">
        <a href="index.php" class="flex items-center px-4 py-3 text-gray-700 hover:bg-gray-100">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
            </svg>
            Dashboard
        </a>
        
        <!-- Devices Menu -->
        <div class="px-4 py-2">
            <div class="flex items-center text-gray-600 mb-2">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path>
                </svg>
                <span class="font-medium">Devices</span>
            </div>
            <div class="ml-8 space-y-2">
                <a href="devices.php" class="block text-sm text-gray-600 hover:text-gray-800 hover:bg-gray-100 px-2 py-1 rounded">
                    All Devices
                </a>
                <a href="devices.php?status=online" class="block text-sm text-gray-600 hover:text-gray-800 hover:bg-gray-100 px-2 py-1 rounded">
                    Online Devices
                </a>
                <a href="devices.php?status=offline" class="block text-sm text-gray-600 hover:text-gray-800 hover:bg-gray-100 px-2 py-1 rounded">
                    Offline Devices
                </a>
            </div>
        </div>

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
