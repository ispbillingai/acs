<?php

class WifiTaskGenerator
{
    private $logger;

    public function __construct($logger)
    {
        $this->logger = $logger;
    }

    public function generateParameters($data)
    {
        // ---------- START‑OF‑FUNCTION marker ----------
        $this->logger->logToFile('WifiTaskGenerator START  ▶  payload: ' .
            json_encode($data, JSON_UNESCAPED_SLASHES));

        $ssid     = $data['ssid']     ?? null;
        $password = $data['password'] ?? null;

        if (!$ssid) {
            $this->logger->logToFile('SSID is required for WiFi configuration → abort');
            return null;
        }

        $parameters = [
            [
                'name'  => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                'value' => $ssid,
                'type'  => 'xsd:string',
            ],
            [
                'name'  => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable',
                'value' => 'true',
                'type'  => 'xsd:boolean',
            ],
        ];

        if ($password !== null && $password !== '') {
            $parameters[] = [
                'name'  => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
                'value' => $password,
                'type'  => 'xsd:string',
            ];
            $parameters[] = [
                'name'  => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',
                'value' => 'WPAand11i',
                'type'  => 'xsd:string',
            ];
            $parameters[] = [
                'name'  => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.WPAEncryptionModes',
                'value' => 'AESEncryption',
                'type'  => 'xsd:string',
            ];
        }

        // ---------- PARAM‑LIST dump ----------
        $this->logger->logToFile(
            'WifiTaskGenerator PARAM LIST (' . count($parameters) . ' total) → ' .
            json_encode($parameters, JSON_UNESCAPED_SLASHES)
        );

        // Final summary
        $this->logger->logToFile(
            "Generated WiFi parameters – SSID: $ssid, Password length: " .
            ($password ? strlen($password) : 0) . ' chars'
        );

        return [
            'method'     => 'SetParameterValues', // add +Commit in handler if needed
            'parameters' => $parameters,
        ];
    }
}
