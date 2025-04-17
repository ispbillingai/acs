
<?php

class RebootTaskGenerator {
    
    private $logger;
    
    public function __construct($logger) {
        $this->logger = $logger;
    }
    
    public function generateParameters($data) {
        // Get the reboot reason from the task data or use a default
        $reason = $data['reboot_reason'] ?? 'User initiated reboot';
        
        // Generate a unique command key with timestamp and current date
        $timestamp = time();
        $date = date('Ymd', $timestamp);
        $commandKey = "manual-reboot-{$date}-" . substr(md5($timestamp), 0, 8);
        
        $this->logger->logToFile("Generated Reboot command with reason: $reason, CommandKey: $commandKey");
        
        return [
            'method' => 'Reboot',
            'commandKey' => $commandKey
        ];
    }
}
