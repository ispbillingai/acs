
<?php
// This is a simple script to simulate TR-069 router data for testing
// Usage: php router_simulator.php [serial_number] [ip_address]

$serialNumber = $argv[1] ?? '48575443F2D61173';
$ipAddress = $argv[2] ?? '192.168.1.138';

// Initialize with error logging
error_log("[ROUTER_SIM] Starting TR-069 Router Simulator");
error_log("[ROUTER_SIM] Serial Number: $serialNumber");
error_log("[ROUTER_SIM] IP Address: $ipAddress");

echo "Starting TR-069 Router Simulator\n";
echo "Serial Number: $serialNumber\n";
echo "IP Address: $ipAddress\n";

// Create simulated router data
$routerData = [
    'raw_parameters' => [
        [
            'name' => 'InternetGatewayDevice.DeviceInfo.SerialNumber',
            'value' => $serialNumber
        ],
        [
            'name' => 'InternetGatewayDevice.DeviceInfo.Manufacturer',
            'value' => 'Huawei Technologies Co., Ltd'
        ],
        [
            'name' => 'InternetGatewayDevice.DeviceInfo.ModelName',
            'value' => 'HG8546M'
        ],
        [
            'name' => 'InternetGatewayDevice.DeviceInfo.HardwareVersion',
            'value' => '10C7.A'
        ],
        [
            'name' => 'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'value' => 'V5R019C10S125'
        ],
        [
            'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'value' => 'TR069 Final'
        ],
        [
            'name' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
            'value' => '0702242476'
        ],
        [
            'name' => 'InternetGatewayDevice.DeviceInfo.UpTime',
            'value' => rand(60000, 100000)
        ],
        [
            'name' => 'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries',
            'value' => 4
        ]
    ]
];

// Add some connected hosts
for ($i = 1; $i <= 4; $i++) {
    $routerData['raw_parameters'][] = [
        'name' => "InternetGatewayDevice.LANDevice.1.Hosts.Host.$i.IPAddress",
        'value' => "192.168.1." . (100 + $i)
    ];
    
    $routerData['raw_parameters'][] = [
        'name' => "InternetGatewayDevice.LANDevice.1.Hosts.Host.$i.HostName",
        'value' => "Device-$i"
    ];
    
    $routerData['raw_parameters'][] = [
        'name' => "InternetGatewayDevice.LANDevice.1.Hosts.Host.$i.PhysAddress",
        'value' => sprintf("AA:BB:CC:DD:EE:%02X", $i)
    ];
    
    $routerData['raw_parameters'][] = [
        'name' => "InternetGatewayDevice.LANDevice.1.Hosts.Host.$i.Active",
        'value' => "1"
    ];
}

// Prepare the data to send
$jsonData = json_encode($routerData);

// Determine the correct path to store_tr069_data.php
$scriptDir = __DIR__;
$apiDir = dirname($scriptDir) . '/api';
$targetScript = $apiDir . '/store_tr069_data.php';

error_log("[ROUTER_SIM] Sending data to: $targetScript");
echo "Sending data to: $targetScript\n";

// Create temporary file with the data
$tempFile = tempnam(sys_get_temp_dir(), 'router_data_');
file_put_contents($tempFile, $jsonData);

// Now send the data using curl
$cmd = "curl -X POST --data-binary @$tempFile $targetScript";
error_log("[ROUTER_SIM] Executing: $cmd");
echo "Executing: $cmd\n";
exec($cmd, $output, $returnCode);

error_log("[ROUTER_SIM] Return code: $returnCode");
error_log("[ROUTER_SIM] Output: " . implode("\n", $output));

echo "Return code: $returnCode\n";
echo "Output:\n";
print_r($output);

// Clean up
unlink($tempFile);
error_log("[ROUTER_SIM] Simulation complete");
echo "Done\n";
