
<?php

class WifiTaskGenerator
{
    /**
     * Logger object – must expose logToFile(string $msg)
     * @var object
     */
    private $logger;

    public function __construct($logger)
    {
        $this->logger = $logger;
    }

    /**
     * Build ParameterValueStruct array for Wi‑Fi config.
     *
     * Expected JSON (already decoded to array):
     * {
     *   "ssid"        : "MySSID",        // required
     *   "password"    : "secretPass",    // optional
     *   "instance_24g": 1,                // defaults 1
     *   "instance_5g" : 5                 // optional
     * }
     */
    public function generateParameters(array $data)
    {
        $this->log('===== WifiTaskGenerator START =====');
        $this->log('Incoming payload: ' . json_encode($data, JSON_UNESCAPED_SLASHES));

        $ssid     = trim($data['ssid'] ?? '');
        $password = (string) ($data['password'] ?? '');
        $inst24   = (int) ($data['instance_24g'] ?? 1);
        $inst5    = isset($data['instance_5g']) ? (int) $data['instance_5g'] : null;

        if ($ssid === '') {
            $this->log('SSID missing – aborting');
            return null;
        }

        // Build ParameterValueStruct list
        $params = [];
        $this->addRadioParams($params, $inst24, $ssid, $password);
        if ($inst5) {
            $this->addRadioParams($params, $inst5, $ssid, $password);
        }

        // Dump final list for traceability
        $this->log('--- FINAL PARAM LIST (' . count($params) . ' entries) ---');
        foreach ($params as $p) {
            $this->log("{$p['name']} = {$p['value']} ({$p['type']})");
        }

        $task = [
            'method'     => 'SetParameterValues',  // Changed to actual RPC name
            'parameters' => $params,
            'requires_commit' => true              // Flag to indicate Commit is needed
        ];

        $this->log('Task JSON returned to handler: ' . json_encode($task));
        $this->log('===== WifiTaskGenerator END =====');

        return $task;
    }

    // ---------------------------------------------------------------------
    private function addRadioParams(array &$params, int $instance, string $ssid, string $password): void
    {
        $this->log("Building params for WLANConfiguration instance $instance");

        // SSID
        $params[] = [
            'name'  => "InternetGatewayDevice.LANDevice.1.WLANConfiguration.$instance.SSID",
            'value' => $ssid,
            'type'  => 'xsd:string',
        ];

        // Enable radio
        $params[] = [
            'name'  => "InternetGatewayDevice.LANDevice.1.WLANConfiguration.$instance.Enable",
            'value' => 'true',
            'type'  => 'xsd:boolean',
        ];

        // Password handling
        if ($password !== '') {
            // Primary Huawei path
            $pskPath = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.$instance.PreSharedKey.1.PreSharedKey";
            $this->log("Using PSK path: $pskPath (length " . strlen($password) . ' chars)');

            $params[] = [
                'name'  => $pskPath,
                'value' => $password,
                'type'  => 'xsd:string',
            ];
        } else {
            $this->log('No password supplied – leaving network OPEN for instance ' . $instance);
        }
    }

    private function log(string $msg): void
    {
        $this->logger->logToFile('WifiTaskGenerator: ' . $msg);
    }
}
