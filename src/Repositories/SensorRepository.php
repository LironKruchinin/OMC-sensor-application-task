<?php

namespace App\Repositories;
use PDO;

class SensorRepository
{
    protected $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get all sensors.
     *
     * @return array
     */
    public function getAllSensors(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM sensors");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a sensor by ID.
     *
     * @param int $id
     * @return array|false
     */
    public function getSensorById(int $id)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM sensors WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Create a new sensor.
     *
     * @param int $sensor_code
     * @param string $face
     * @param string|null $installed_at
     * @param string $status
     * @return mixed The new sensor id.
     */
    public function createSensor(int $sensor_code, string $face, ?string $installed_at, string $status = 'active')
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO sensors (sensor_code, face, installed_at, status) 
             VALUES (:sensor_code, :face, :installed_at, :status) RETURNING id"
        );
        $stmt->bindValue(':sensor_code', $sensor_code, PDO::PARAM_INT);
        $stmt->bindValue(':face', $face, PDO::PARAM_STR);
        if ($installed_at === null) {
            $stmt->bindValue(':installed_at', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':installed_at', $installed_at, PDO::PARAM_STR);
        }
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    /**
     * Update an existing sensor.
     *
     * @param int $id
     * @param int $sensor_code
     * @param string $face
     * @param string|null $installed_at
     * @param string $status
     * @return bool
     */
    public function updateSensor(int $id, int $sensor_code, string $face, ?string $installed_at, string $status): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sensors 
             SET sensor_code = :sensor_code, face = :face, installed_at = :installed_at, status = :status 
             WHERE id = :id"
        );
        $stmt->bindValue(':sensor_code', $sensor_code, PDO::PARAM_INT);
        $stmt->bindValue(':face', $face, PDO::PARAM_STR);
        if ($installed_at === null) {
            $stmt->bindValue(':installed_at', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':installed_at', $installed_at, PDO::PARAM_STR);
        }
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    /**
     * Delete a sensor.
     *
     * @param int $id
     * @return bool
     */
    public function deleteSensor(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM sensors WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }
}
