
<aside class="w-64 bg-gray-800 shadow-lg fixed h-full">
    <div class="p-4 border-b border-gray-700">
        <h2 class="text-xl font-bold text-white">ACS Dashboard</h2>
    </div>
    <nav class="mt-4">
        <a href="index.php" class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
            </svg>
            Dashboard
        </a>
        
        <!-- Devices Menu -->
        <div class="px-4 py-2">
            <div class="flex items-center text-gray-300 mb-2">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path>
                </svg>
                <span class="font-medium">Devices</span>
            </div>
            <div class="ml-8 space-y-2">
                <a href="devices.php" class="block text-sm text-gray-400 hover:text-gray-200 hover:bg-gray-700 px-2 py-1 rounded">
                    All Devices
                </a>
                <a href="devices.php?status=online" class="block text-sm text-gray-400 hover:text-gray-200 hover:bg-gray-700 px-2 py-1 rounded">
                    Online Devices
                </a>
                <a href="devices.php?status=offline" class="block text-sm text-gray-400 hover:text-gray-200 hover:bg-gray-700 px-2 py-1 rounded">
                    Offline Devices
                </a>
            </div>
        </div>

        <a href="settings.php" class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
            </svg>
            Settings
        </a>

        <a href="logout.php" class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
            </svg>
            Logout
        </a>
    </nav>
</aside>
