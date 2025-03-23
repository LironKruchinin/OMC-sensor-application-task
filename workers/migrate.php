<?php
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/Database.php';

use App\Database\Database;

$pdo = Database::getInstance()->getConnection();

function checkAndCreateTable(PDO $pdo, string $tableName, string $createQuery)
{
    $stmt = $pdo->query("SELECT to_regclass('public.$tableName') AS table_exists");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row['table_exists']) {
        echo "Creating table $tableName...\n";
        try {
            $pdo->exec($createQuery);
            echo "Table '$tableName' created successfully.\n";
        } catch (PDOException $e) {
            echo "Error creating table '$tableName': " . $e->getMessage() . "\n";
        }
    } else {
        echo "Table '$tableName' already exists.\n";
    }
}

$sensorsTableQuery = "
    CREATE TABLE sensors (
        id SERIAL PRIMARY KEY,
        sensor_code INTEGER UNIQUE NOT NULL,
        face VARCHAR(10) NOT NULL,
        installed_at BIGINT NOT NULL,
        status VARCHAR(20) DEFAULT 'active'
    );
";

$sensorDataTableQuery = "
    CREATE TABLE sensor_data (
        id SERIAL PRIMARY KEY,
        sensor_id INTEGER NOT NULL,
        timestamp BIGINT NOT NULL,
        temperature_value DOUBLE PRECISION NOT NULL,
        FOREIGN KEY (sensor_id) REFERENCES sensors(sensor_code) ON DELETE CASCADE
    );
";

checkAndCreateTable($pdo, "sensors", $sensorsTableQuery);
checkAndCreateTable($pdo, "sensor_data", $sensorDataTableQuery);
