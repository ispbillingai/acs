
<?php
class AuthenticationHandler {
    private $validUsername;
    private $validPassword;

    public function __construct($username = "admin", $password = "admin") {
        $this->validUsername = $username;
        $this->validPassword = $password;
    }

    public function authenticate() {
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
            return false;
        }

        $username = $_SERVER['PHP_AUTH_USER'];
        $password = $_SERVER['PHP_AUTH_PW'];
        
        return ($username === $this->validUsername && $password === $this->validPassword);
    }
}
