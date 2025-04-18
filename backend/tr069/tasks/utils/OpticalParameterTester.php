
<?php

class OpticalParameterTester {
    private $logger;
    private $logFile;

    public function __construct($logger) {
        $this->logger = $logger;
        $this->logFile = $_SERVER['DOCUMENT_ROOT'] . '/optical_test.log';
    }

    public function generateTestParameters() {
        // Common parameter paths to test
        $opticalParams = [
            // GPON parameters
            'InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.TXPower',
            'InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.RXPower',
            
            // EPON parameters
            'InternetGatewayDevice.WANDevice.1.X_EponInterfaceConfig.TXPower',
            'InternetGatewayDevice.WANDevice.1.X_EponInterfaceConfig.RXPower',
            
            // Alternative paths
            'InternetGatewayDevice.Device.Optical.Interface.1.CurrentTXPower',
            'InternetGatewayDevice.Device.Optical.Interface.1.CurrentRXPower',
            
            // Huawei specific paths
            'InternetGatewayDevice.X_HW_SmartAP.PowerManagement.OpticalTXPower',
            'InternetGatewayDevice.X_HW_SmartAP.PowerManagement.OpticalRXPower',
            
            // Additional vendor specific paths
            'InternetGatewayDevice.WANDevice.1.WANPONInterfaceConfig.OpticalSignalLevel',
            'InternetGatewayDevice.WANDevice.1.X_OpticalInterface.TransmitOpticalLevel',
            'InternetGatewayDevice.WANDevice.1.X_OpticalInterface.ReceiveOpticalLevel'
        ];

        // Log the test execution
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] Testing optical parameters:\n";
        foreach ($opticalParams as $param) {
            $logEntry .= "- $param\n";
        }
        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
        
        // Log to TR-069 logger as well
        $this->logger->logToFile("OpticalParameterTester: Starting test with " . count($opticalParams) . " parameters");

        return [
            'method' => 'GetParameterValues',
            'parameterNames' => $opticalParams
        ];
    }

    public function logResults($results) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "\n[$timestamp] Test Results:\n";
        
        foreach ($results as $param => $value) {
            $logEntry .= "$param = $value\n";
        }
        
        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
        $this->logger->logToFile("OpticalParameterTester: Results logged to optical_test.log");
    }
}

