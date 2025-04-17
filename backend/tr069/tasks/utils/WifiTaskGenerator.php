<?php

class WifiTaskGenerator
{
    /** @var object  must expose ->logToFile(string $msg)  */
    private $logger;

    // ---------- constructor --------------------------------------------------
    public function __construct($logger)
    {
        $this->logger = $logger;   // same pattern as your other generators
    }

    // ---------- public API ---------------------------------------------------
    /**
     * Build parameter list for a Wi‑Fi update.
     *
     * Expected $data (decoded JSON from API):
     *   {
     *     "ssid"        : "MySSID",                 // required
     *     "password"    : "secretPass",             // optional (open if missing)
     *     "instance_24g": 1,                        // defaults to 1
     *     "instance_5g" : 5                         // optional – add if present
     *   }
     *
     * Returns:
     *   [
     *     'method'     => 'SetParameterValues+Commit',
     *     'parameters' => [ ParameterValueStruct[] ]
     *   ]
     */
    public function generateParameters(array $data)
    {
        $ssid      = trim($data['ssid'] ?? '');
        $password  = (string) ($data['password'] ?? '');
        $inst24    = (int)   ($data['instance_24g'] ?? 1);
        $inst5     = isset($data['instance_5g']) ? (int) $data['instance_5g'] : null;

        $this->log("=== WiFi task generator start ===");
        $this->log('Incoming payload: ' . json_encode($data, JSON_UNESCAPED_SLASHES));

        if ($ssid === '') {
            $this->log('SSID missing ‑‑ aborting task build');
            return null;
        }

        // ------- build ParameterValueStructs --------------------------------
        $params = [];
        $this->addRadioParams($params, $inst24, $ssid, $password);
        if ($inst5) {
            $this->addRadioParams($params, $inst5, $ssid, $password);
        }

        // ------- log result --------------------------------------------------
        $this->log('Parameter list (' . count($params) . ' entries):');
        foreach ($params as $p) {
            $this->log("  {$p['name']} = {$p['value']} ({$p['type']})");
        }

        $task = [
            'method'     => 'SetParameterValues+Commit',  // handler looks for +Commit
            'parameters' => $params
        ];

        $this->log('Task JSON returned: ' . json_encode($task));
        $this->log('=== WiFi task generator end ===');

        return $task;
    }

    // ---------- helpers ------------------------------------------------------
    private function addRadioParams(array &$params, int $instance, string $ssid, string $password): void
    {
        $this->log("Building params for WLANConfiguration instance $instance");

        // SSID
        $params[] = [
            'name'  => "InternetGatewayDevice.LANDevice.1.WLANConfiguration.$instance.SSID",
            'value' => $ssid,
            'type'  => 'xsd:string'
        ];

        // ensure radio enabled
        $params[] = [
            'name'  => "InternetGatewayDevice.LANDevice.1.WLANConfiguration.$instance.Enable",
            'value' => 'true',
            'type'  => 'xsd:boolean'
        ];

        // password & security
        if ($password !== '') {
            $params[] = [
                'name'  => "InternetGatewayDevice.LANDevice.1.WLANConfiguration.$instance.PreSharedKey.1.PreSharedKey",
                'value' => $password,
                'type'  => 'xsd:string'
            ];
            $this->log("Using PreSharedKey path for instance $instance");
        } else {
            // open network
            $params[] = [
                'name'  => "InternetGatewayDevice.LANDevice.1.WLANConfiguration.$instance.X_HW_SecurityMode",
                'value' => 'None',
                'type'  => 'xsd:string'
            ];
        }
    }

    private function log(string $msg): void
    {
        $this->logger->logToFile("WifiTaskGenerator " . $msg);
    }
}
