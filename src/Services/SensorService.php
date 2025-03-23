<?php

namespace App\Services;

use PDO;

class SensorService
{
    protected $pdo;
    protected $sensorRepository;

    public function __construct(PDO $pdo, $sensorRepository)
    {
        $this->pdo = $pdo;
        $this->sensorRepository = $sensorRepository;
    }

    /**
     * Calculate hourly average temperatures for a given face.
     *
     * @param string $face (e.g., "north", "east", "south", "west")
     * @return array List of hours and their average temperatures.
     */
    public function getHourlyAveragesForFace(string $face): array
    {
        $stmt = $this->pdo->prepare("
            SELECT date_trunc('hour', to_timestamp(sd.timestamp)) AS hour,
                   AVG(sd.temperature_value) AS avg_temp
            FROM sensor_data sd
            JOIN sensors s ON sd.sensor_id = s.sensor_code
            WHERE s.face = :face
            GROUP BY hour
            ORDER BY hour
        ");
        $stmt->bindValue(':face', $face, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Identify malfunctioning sensors based on a threshold deviation.
     *
     * A sensor is considered malfunctioning if its average temperature deviates by more than
     * the given threshold (default 20%) from the overall average for sensors on the same face.
     *
     * @param float $threshold
     * @return array
     */
    public function getMalfunctioningSensors(float $threshold = 0.2): array
    {
        // Get overall average temperature for each face.
        $stmt = $this->pdo->prepare("
            SELECT s.face, AVG(sd.temperature_value) AS overall_avg
            FROM sensor_data sd
            JOIN sensors s ON sd.sensor_id = s.sensor_code
            GROUP BY s.face
        ");
        $stmt->execute();
        $faceAverages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $faceAvgMap = [];
        foreach ($faceAverages as $row) {
            $faceAvgMap[$row['face']] = $row['overall_avg'];
        }

        // Get average temperature per sensor.
        $stmt = $this->pdo->prepare("
            SELECT s.sensor_code, s.face, AVG(sd.temperature_value) AS sensor_avg
            FROM sensor_data sd
            JOIN sensors s ON sd.sensor_id = s.sensor_code
            GROUP BY s.sensor_code, s.face
        ");
        $stmt->execute();
        $sensorAverages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $malfunctioning = [];

        foreach ($sensorAverages as $sensor) {
            $face = $sensor['face'];
            if (!isset($faceAvgMap[$face])) {
                continue;
            }
            $overallAvg = $faceAvgMap[$face];
            $sensorAvg = $sensor['sensor_avg'];
            $deviation = abs($sensorAvg - $overallAvg) / $overallAvg;
            if ($deviation > $threshold) {
                $malfunctioning[] = [
                    'sensor_code' => $sensor['sensor_code'],
                    'face' => $face,
                    'sensor_avg' => $sensorAvg,
                    'overall_avg' => $overallAvg,
                    'deviation' => $deviation,
                ];
            }
        }
        return $malfunctioning;
    }
}
