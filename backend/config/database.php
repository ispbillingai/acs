
<?php
class Database {
    private $host = "localhost";
    private $db_name = "acs";
    private $username = "acs";
    private $password = "acs";
    public $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            error_log("Attempting database connection to: {$this->host}");
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            error_log("Database connection established successfully");
            return $this->conn;
        } catch(PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            error_log("Connection details: host={$this->host}, db={$this->db_name}, user={$this->username}");
            throw $e;
        }
    }
}
