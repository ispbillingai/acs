
<?php
require_once __DIR__ . '/backend/auth/login.php';

$loginHandler = new LoginHandler();
$loginHandler->logout();

header('Location: index.php');
exit;
