
<?php

class RebootTaskGenerator {
    
    private $logger;
    
    public function __construct($logger) {
        $this->logger = $logger;
    }
    
    public function generateParameters($data) {
        $reason = $data['reboot_reason'] ?? 'User initiated reboot';
        $commandKey = 'Reboot-' . substr(md5(time()), 0, 8);
        
        $this->logger->logToFile("Generated Reboot command with reason: $reason, CommandKey: $commandKey");
        
        return [
            'method' => 'Reboot',
            'commandKey' => $commandKey
        ];
    }
}
