<?php

namespace App\Database;

use PDO;
use PDOException;
use Dotenv\Dotenv;

class Database
{
    private static $instance = null;
    private $pdo;

    private function __construct()
    {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../', '.env');
        $dotenv->load();

        $host = getenv('DB_HOST');
        $port = getenv('DB_PORT');
        $dbname = getenv('DB_NAME');
        $username = getenv('DB_USER');
        $password = getenv('DB_PWD');

        if (!$host || !$port || !$dbname || !$username) {
            die("Database environment variables are not set correctly.");
        }

        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;user=$username;password=$password";

        try {
            $this->pdo = new PDO($dsn);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Error connecting to DB: " . $e->getMessage());
        }
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }
}
