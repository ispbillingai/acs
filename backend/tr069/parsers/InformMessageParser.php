
<?php
class InformMessageParser {
    private $parameterMap = [
        // Standard Device Info Parameters 
        'Device.DeviceInfo.Manufacturer' => 'manufacturer',
        'InternetGatewayDevice.DeviceInfo.Manufacturer' => 'manufacturer',
        'Device.DeviceInfo.ModelName' => 'modelName',
        'InternetGatewayDevice.DeviceInfo.ModelName' => 'modelName',
        'Device.DeviceInfo.SerialNumber' => 'serialNumber',
        'InternetGatewayDevice.DeviceInfo.SerialNumber' => 'serialNumber',
        'Device.DeviceInfo.HardwareVersion' => 'hardwareVersion',
        'InternetGatewayDevice.DeviceInfo.HardwareVersion' => 'hardwareVersion',
        'Device.DeviceInfo.SoftwareVersion' => 'softwareVersion',
        'InternetGatewayDevice.DeviceInfo.SoftwareVersion' => 'softwareVersion',
        'Device.DeviceInfo.ProductClass' => 'productClass',
        'Device.DeviceInfo.Description' => 'description',
        'Device.DeviceInfo.ManufacturerOUI' => 'manufacturerOUI',
        
        // MikroTik Specific Parameters
        'Device.DeviceInfo.X_MIKROTIK_SystemIdentity' => 'systemIdentity',
        'Device.DeviceInfo.X_MIKROTIK_ArchName' => 'archName',
        
        // Network Interfaces
        'Device.LAN.MACAddress' => 'macAddress',
        'Device.Ethernet.Interface.1.MACAddress' => 'macAddress',
        'Device.Interface.ether1.MACAddress' => 'macAddress',
        'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1.MACAddress' => 'macAddress',
        
        // IP Configuration
        'Device.DHCPv4.Client.1.IPAddress' => 'dhcpIpAddress',
        'Device.IP.Interface.1.IPv4Address.1.IPAddress' => 'ipAddress',
        
        // System Stats
        'Device.DeviceInfo.UpTime' => 'uptime',
        'Device.Hosts.HostNumberOfEntries' => 'dhcpHostCount',
        'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries' => 'dhcpHostCount',
        
        // WiFi Parameters (may be null for non-WiFi models)
        'Device.WiFi.SSID.1.SSID' => 'ssid1',
        'Device.WiFi.SSID.2.SSID' => 'ssid2',
        'Device.WiFi.SSID.1.MACAddress' => 'wifiMac1',
        'Device.WiFi.SSID.2.MACAddress' => 'wifiMac2',
        'Device.WiFi.AccessPoint.1.Security.KeyPassphrase' => 'ssidPassword1',
        'Device.WiFi.AccessPoint.2.Security.KeyPassphrase' => 'ssidPassword2',
        'Device.WiFi.AccessPoint.1.Security.ModeEnabled' => 'securityMode1',
        'Device.WiFi.AccessPoint.2.Security.ModeEnabled' => 'securityMode2',
        'Device.WiFi.AccessPoint.1.AssociatedDeviceNumberOfEntries' => 'connectedClients1',
        'Device.WiFi.AccessPoint.2.AssociatedDeviceNumberOfEntries' => 'connectedClients2'
    ];

    // Map for extracting host information with dynamic indices
    private $hostParameterPattern = [
        'InternetGatewayDevice.LANDevice.1.Hosts.Host.(\d+).IPAddress' => 'hostIpAddress',
        'InternetGatewayDevice.LANDevice.1.Hosts.Host.(\d+).HostName' => 'hostName',
        'InternetGatewayDevice.LANDevice.1.Hosts.Host.(\d+).PhysAddress' => 'hostMacAddress',
        'InternetGatewayDevice.LANDevice.1.Hosts.Host.(\d+).Active' => 'hostActive',
        'Device.Hosts.Host.(\d+).IPAddress' => 'hostIpAddress',
        'Device.Hosts.Host.(\d+).HostName' => 'hostName',
        'Device.Hosts.Host.(\d+).PhysAddress' => 'hostMacAddress',
        'Device.Hosts.Host.(\d+).Active' => 'hostActive'
    ];

    public function parseInform($request) {
        try {
            $deviceInfo = [
                'manufacturer' => '',
                'modelName' => '',
                'serialNumber' => '',
                'status' => 'online',
                'macAddress' => '',
                'softwareVersion' => '',
                'hardwareVersion' => '',
                'productClass' => '',
                'description' => '',
                'manufacturerOUI' => '',
                'systemIdentity' => '',
                'archName' => '',
                'dhcpIpAddress' => '',
                'ipAddress' => '',
                'uptime' => 0,
                'dhcpHostCount' => 0,
                'ssid1' => null,
                'ssid2' => null,
                'wifiMac1' => null,
                'wifiMac2' => null,
                'ssidPassword1' => null,
                'ssidPassword2' => null,
                'securityMode1' => null,
                'securityMode2' => null,
                'connectedClients1' => 0,
                'connectedClients2' => 0,
                'tr069Password' => '',
                'localAdminPassword' => '',
                'connectedHosts' => [] // Array to store connected host information
            ];

            if (!$request) {
                throw new Exception("Empty request received");
            }

            // Register namespaces
            $namespaces = [
                'soapenv' => 'http://schemas.xmlsoap.org/soap/envelope/',
                'cwmp' => 'urn:dslforum-org:cwmp-1-0'
            ];

            foreach ($namespaces as $prefix => $ns) {
                $request->registerXPathNamespace($prefix, $ns);
            }

            // Get Inform section
            $inform = $request->xpath('//cwmp:Inform');

            if (!empty($inform)) {
                $inform = $inform[0];

                // Extract DeviceId information
                $deviceId = $inform->xpath('.//DeviceId')[0];
                if ($deviceId) {
                    $deviceInfo['manufacturer'] = (string)$deviceId->Manufacturer;
                    $deviceInfo['modelName'] = (string)$deviceId->ProductClass;
                    $deviceInfo['serialNumber'] = (string)$deviceId->SerialNumber;
                }

                // Extract parameters
                $parameters = $inform->xpath('.//ParameterList/ParameterValueStruct');

                if ($parameters) {
                    // Parse regular parameters
                    foreach ($parameters as $param) {
                        $name = (string)$param->Name;
                        $value = (string)$param->Value;

                        // Enhanced parameter logging
                        if (strpos($name, 'Device.DeviceInfo') !== false ||
                            strpos($name, 'Device.Ethernet') !== false ||
                            strpos($name, 'Device.WiFi') !== false ||
                            strpos($name, 'Device.IP') !== false ||
                            strpos($name, 'Device.DHCPv4') !== false ||
                            strpos($name, 'Device.Hosts') !== false ||
                            strpos($name, 'InternetGatewayDevice.LANDevice.1.Hosts') !== false) {
                            error_log("TR-069 Parameter - $name: $value");
                        }

                        // Map standard parameters
                        if (isset($this->parameterMap[$name])) {
                            $key = $this->parameterMap[$name];
                            switch ($key) {
                                case 'uptime':
                                case 'connectedClients1':
                                case 'connectedClients2':
                                case 'dhcpHostCount':
                                    $deviceInfo[$key] = empty($value) ? 0 : (int)$value;
                                    break;
                                default:
                                    $deviceInfo[$key] = empty($value) ? null : $value;
                            }
                        }

                        // Check for host-related parameters with dynamic indices
                        else {
                            foreach ($this->hostParameterPattern as $pattern => $fieldName) {
                                if (preg_match('/' . $pattern . '/', $name, $matches)) {
                                    if (isset($matches[1])) { // This contains the host index
                                        $hostIndex = $matches[1];
                                        
                                        if (!isset($deviceInfo['connectedHosts'][$hostIndex])) {
                                            $deviceInfo['connectedHosts'][$hostIndex] = [
                                                'index' => $hostIndex,
                                                'ipAddress' => '',
                                                'hostName' => '',
                                                'macAddress' => '',
                                                'active' => false
                                            ];
                                        }
                                        
                                        switch ($fieldName) {
                                            case 'hostIpAddress':
                                                $deviceInfo['connectedHosts'][$hostIndex]['ipAddress'] = $value;
                                                break;
                                            case 'hostName':
                                                $deviceInfo['connectedHosts'][$hostIndex]['hostName'] = $value;
                                                break;
                                            case 'hostMacAddress':
                                                $deviceInfo['connectedHosts'][$hostIndex]['macAddress'] = $value;
                                                break;
                                            case 'hostActive':
                                                $deviceInfo['connectedHosts'][$hostIndex]['active'] = ($value === '1' || strtolower($value) === 'true');
                                                break;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                // Enhanced logging
                error_log("TR-069 Device Parameters Summary:");
                error_log("- Device Identity: " . ($deviceInfo['systemIdentity'] ?: 'Not provided'));
                error_log("- Model: " . $deviceInfo['modelName']);
                error_log("- Product Class: " . ($deviceInfo['productClass'] ?: 'Not provided'));
                error_log("- MAC Address: " . ($deviceInfo['macAddress'] ?: 'Not provided'));
                error_log("- IP Address: " . ($deviceInfo['ipAddress'] ?: 'Not provided'));
                error_log("- DHCP IP: " . ($deviceInfo['dhcpIpAddress'] ?: 'Not provided'));
                error_log("- Uptime: " . $deviceInfo['uptime'] . " seconds");
                error_log("- SSID 1: " . ($deviceInfo['ssid1'] ?: 'Not provided'));
                error_log("- SSID 2: " . ($deviceInfo['ssid2'] ?: 'Not provided'));
                error_log("- WiFi Clients 1: " . $deviceInfo['connectedClients1']);
                error_log("- WiFi Clients 2: " . $deviceInfo['connectedClients2']);
                error_log("- DHCP Hosts: " . $deviceInfo['dhcpHostCount']);
                
                // Log connected hosts
                if (!empty($deviceInfo['connectedHosts'])) {
                    error_log("- Connected Hosts: " . count($deviceInfo['connectedHosts']));
                    foreach ($deviceInfo['connectedHosts'] as $hostIndex => $host) {
                        error_log("  - Host #{$hostIndex}: {$host['hostName']} ({$host['ipAddress']})");
                    }
                }
            }

            if (empty($deviceInfo['serialNumber'])) {
                throw new Exception("Missing required field: serialNumber");
            }

            return $deviceInfo;

        } catch (Exception $e) {
            error_log("Error parsing Inform message: " . $e->getMessage());
            throw $e;
        }
    }
}
