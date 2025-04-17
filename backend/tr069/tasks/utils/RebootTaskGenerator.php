
<?php

class RebootTaskGenerator {
    
    private $logger;
    private $useHuaweiVendorRpc = false;
    
    public function __construct($logger) {
        $this->logger = $logger;
    }
    
    public function generateParameters($data) {
        // Get the reboot reason from the task data or use a default
        $reason = $data['reboot_reason'] ?? 'User initiated reboot';
        
        // Generate a shorter CommandKey (≤ 30 ASCII characters)
        $commandKey = 'reboot-' . bin2hex(random_bytes(4));
        
        // Detect if we should use the Huawei vendor-specific reboot RPC based on data
        $this->useHuaweiVendorRpc = isset($data['use_vendor_rpc']) && $data['use_vendor_rpc'] === true;
        
        $this->logger->logToFile("Generated Reboot command with reason: $reason, CommandKey: $commandKey, Vendor RPC: " . ($this->useHuaweiVendorRpc ? 'Yes' : 'No'));
        
        if ($this->useHuaweiVendorRpc) {
            return [
                'method' => 'X_HW_DelayReboot',
                'commandKey' => $commandKey,
                'delay' => 0
            ];
        }
        
        return [
            'method' => 'Reboot',
            'commandKey' => $commandKey
        ];
    }
}
