
<?php
header('Content-Type: text/plain');

// Check if the SSIDs file exists
$ssidsFile = __DIR__ . '/../router_ssids.txt';

if (file_exists($ssidsFile)) {
    // Read and output the SSIDs
    echo file_get_contents($ssidsFile);
} else {
    // Return empty if no SSIDs found
    echo "No SSIDs discovered yet.";
}
