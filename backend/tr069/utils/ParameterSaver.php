<?php
/**
 * Extract <ParameterValueStruct> pairs and update the devices table.
 */
class ParameterSaver
{
    private $db, $logger;
    public function __construct($pdo, $logger){ $this->db=$pdo; $this->logger=$logger; }

    public function save($serial, $rawSoap)
    {
        // map TR‑069 path → column
        $map = [
            'ExternalIPAddress' => 'ip_address',
            'SoftwareVersion'   => 'software_version',
            'HardwareVersion'   => 'hardware_version',
            'UpTime'            => 'uptime',
            '.SSID'             => 'ssid'             // substring match
        ];

        preg_match_all(
            '/<ParameterValueStruct>.*?<Name>(.*?)<\/Name>.*?<Value[^>]*>(.*?)<\/Value>/s',
            $rawSoap, $m, PREG_SET_ORDER
        );

        if (!$m) { $this->logger->logToFile("ParameterSaver: nothing matched"); return; }

        $cols = [];
        $params = [':serial' => $serial];

        foreach ($m as $pair) {
            [$full,$name,$val] = $pair;

            foreach ($map as $needle => $col) {
                if (strpos($name,$needle)!==false) {
                    $cols[] = "$col = :$col";
                    $params[":$col"] = trim($val);
                    $this->logger->logToFile("ParameterSaver: $name → $col = $val");
                }
            }
        }

        if ($cols) {
            $sql = "UPDATE devices SET ".implode(', ',$cols).
                   ", updated_at = NOW() WHERE serial_number = :serial";
            $this->db->prepare($sql)->execute($params);
            $this->logger->logToFile("ParameterSaver: updated devices for $serial");
        }
    }
}
