<?php

class RebootTaskGenerator {
    
    private $logger;
    private $useHuaweiVendorRpc = false;
    
    public function __construct($logger) {
        $this->logger = $logger;
        $this->logger->logToFile("RebootTaskGenerator initialized");
    }
    
    public function generateParameters($data) {
        // Validate input data
        if (!is_array($data)) {
            $this->logger->logToFile("Error: Invalid task data for reboot task");
            throw new Exception("Invalid task data for reboot task");
        }
        
        // Get the reboot reason from the task data or use a default
        $reason = $data['reboot_reason'] ?? 'User initiated reboot';
        
        // Generate a shorter CommandKey (≤ 30 ASCII characters)
        $commandKey = 'reboot-' . bin2hex(random_bytes(4));
        
        // Detect if we should use the Huawei vendor-specific reboot RPC
        $this->useHuaweiVendorRpc = isset($data['use_vendor_rpc']) && $data['use_vendor_rpc'] === true;
        
        $rpcMethod = $this->useHuaweiVendorRpc ? 'X_HW_DelayReboot' : 'Reboot';
        
        $this->logger->logToFile("Generating reboot parameters: Reason: $reason, CommandKey: $commandKey, Method: $rpcMethod");
        
        $parameters = [
            'method' => $rpcMethod,
            'commandKey' => $commandKey
        ];
        
        if ($this->useHuaweiVendorRpc) {
            $parameters['delay'] = 0; // Immediate reboot
            $this->logger->logToFile("Using Huawei vendor-specific reboot with delay: 0");
        }
        
        return $parameters;
    }
}
?>