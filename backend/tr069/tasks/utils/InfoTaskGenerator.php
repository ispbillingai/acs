<?php
/**
 * Build a GetParameterValues RPC to pull key facts from the CPE.
 *
 *  task_type  :  "info"
 *  task_data  :  {
 *       "names": [                     // optional
 *           "InternetGatewayDevice.DeviceInfo.SoftwareVersion",
 *           ...
 *       ]
 *  }
 */
class InfoTaskGenerator
{
    private $logger;
    public function __construct($logger) { $this->logger = $logger; }

    public function generateParameters(array $data)
    {
        $names = $data['names'] ?? [
            // --- Device basics ---
            'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'InternetGatewayDevice.DeviceInfo.HardwareVersion',

            // --- IP / WAN status ---
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',

            // --- Wireless SSID (2.4 GHz) ---
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',

            // --- Uptime (Huawei) ---
            'InternetGatewayDevice.DeviceInfo.UpTime'
        ];

        $this->logger->logToFile(
            'InfoTaskGenerator: building GetParameterValues for '.count($names).' names'
        );

        return [
            'method'         => 'GetParameterValues',
            'parameterNames' => $names,
            'requires_commit'=> false
        ];
    }
}
