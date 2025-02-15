
<?php
require_once __DIR__ . '/tr069/server.php';

// Initialize and run the TR-069 server
$server = new TR069Server();
$server->handleRequest();
