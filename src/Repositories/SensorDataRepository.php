<?php

namespace App\Repositories;

use PDO;

class SensorDataRepository
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Insert a new temperature reading for a sensor.
     *
     * @param int $sensorId The sensor's numeric ID (sensor_code).
     * @param int $timestamp Unix timestamp for the reading.
     * @param float $temperature The temperature value in Celsius.
     * @return int                 The new sensor_data row ID.
     */
    public function createReading(int $sensorId, int $timestamp, float $temperature): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO sensor_data (sensor_id, timestamp, temperature_value)
            VALUES (:sensor_id, :timestamp, :temperature_value)
            RETURNING id
        ");

        $stmt->bindValue(':sensor_id', $sensorId, PDO::PARAM_INT);
        $stmt->bindValue(':timestamp', $timestamp, PDO::PARAM_INT);
        $stmt->bindValue(':temperature_value', $temperature, PDO::PARAM_STR);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /**
     * Fetch readings for a given sensor.
     *
     * @param int $sensorId
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getReadingsBySensor(int $sensorId, int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * 
            FROM sensor_data
            WHERE sensor_id = :sensor_id
            ORDER BY timestamp DESC
            LIMIT :limit OFFSET :offset
        ");

        $stmt->bindValue(':sensor_id', $sensorId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the most recent reading for a sensor.
     *
     * @param int $sensorId
     * @return array|false
     */
    public function getLatestReadingBySensor(int $sensorId)
    {
        $stmt = $this->pdo->prepare("
            SELECT * 
            FROM sensor_data
            WHERE sensor_id = :sensor_id
            ORDER BY timestamp DESC
            LIMIT 1
        ");

        $stmt->bindValue(':sensor_id', $sensorId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get aggregated average temperature readings over a specified interval.
     *
     * This method groups temperature readings by the given interval and sensor face.
     *
     * @param string $interval The aggregation interval. Allowed values: "hour", "day", "minute", "week", "month".
     * @param string|null $start Optional start date/time (format recognized by PostgreSQL, e.g. "2025-03-22 00:00:00").
     * @param string|null $end Optional end date/time.
     * @return array                Aggregated results with columns: period, face, avg_temperature.
     *
     * @throws \InvalidArgumentException if the interval is not allowed.
     */
    public function getAggregatedAverage(string $interval, ?string $start = null, ?string $end = null): array
    {
        $allowedIntervals = ['hour', 'day', 'minute', 'week', 'month'];
        if (!in_array($interval, $allowedIntervals)) {
            throw new \InvalidArgumentException(
                "Invalid interval: $interval. Allowed intervals: " . implode(', ', $allowedIntervals)
            );
        }

        $sql = "SELECT date_trunc('$interval', to_timestamp(sd.timestamp)) AS period,
                       s.face,
                       AVG(sd.temperature_value) AS avg_temperature
                FROM sensor_data sd
                JOIN sensors s ON sd.sensor_id = s.sensor_code";

        $conditions = [];
        $params = [];
        if ($start !== null) {
            $conditions[] = "to_timestamp(sd.timestamp) >= :start";
            $params[':start'] = $start;
        }
        if ($end !== null) {
            $conditions[] = "to_timestamp(sd.timestamp) <= :end";
            $params[':end'] = $end;
        }
        if (count($conditions) > 0) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        $sql .= " GROUP BY period, s.face ORDER BY period, s.face";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the overall aggregated average temperature for each face.
     *
     * This method first aggregates the sensor data by the specified interval and then calculates
     * a single overall average temperature for each sensor face over the aggregated periods.
     *
     * @param string $interval The aggregation interval ("hour", "day", "minute", "week", "month").
     * @param string|null $start Optional start date/time.
     * @param string|null $end Optional end date/time.
     * @return array                An associative array where keys are faces and values are overall averages.
     *
     * @throws \InvalidArgumentException if the interval is not allowed.
     */
    public function getOverallAggregatedAverage(string $interval, ?string $start = null, ?string $end = null): array
    {
        $aggregates = $this->getAggregatedAverage($interval, $start, $end);

        $overall = [];
        foreach ($aggregates as $row) {
            $face = $row['face'];
            $avgTemp = (float)$row['avg_temperature'];
            if (!isset($overall[$face])) {
                $overall[$face] = ['total' => 0.0, 'count' => 0];
            }
            $overall[$face]['total'] += $avgTemp;
            $overall[$face]['count']++;
        }

        $result = [];
        foreach ($overall as $face => $data) {
            $result[$face] = $data['total'] / $data['count'];
        }

        return $result;
    }

    /**
     * Get malfunctioning sensors based on a deviation threshold.
     *
     * A sensor is considered malfunctioning if its average temperature deviates more than the given percentage
     * from the average temperature of all sensors on the same face.
     *
     * @param float $threshold The deviation threshold (e.g., 0.20 for 20%).
     * @param string|null $start Optional start date/time.
     * @param string|null $end Optional end date/time.
     * @return array                 List of malfunctioning sensors with their id, sensor_code, face, sensor_avg, and face_avg.
     */
    public function getMalfunctioningSensors(float $threshold = 0.20, ?string $start = null, ?string $end = null): array
    {
        $conditions = [];
        $params = [];
        if ($start !== null) {
            $conditions[] = "to_timestamp(sd.timestamp) >= :start";
            $params[':start'] = $start;
        }
        if ($end !== null) {
            $conditions[] = "to_timestamp(sd.timestamp) <= :end";
            $params[':end'] = $end;
        }
        $whereClause = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";

        $sql = "
        WITH face_averages AS (
            SELECT s.face, AVG(sd.temperature_value) AS face_avg
            FROM sensor_data sd
            JOIN sensors s ON sd.sensor_id = s.sensor_code
            $whereClause
            GROUP BY s.face
        ),
        sensor_averages AS (
            SELECT s.id, s.sensor_code, s.face, AVG(sd.temperature_value) AS sensor_avg
            FROM sensor_data sd
            JOIN sensors s ON sd.sensor_id = s.sensor_code
            $whereClause
            GROUP BY s.id, s.sensor_code, s.face
        )
        SELECT sa.id, sa.sensor_code, sa.face, sa.sensor_avg, fa.face_avg
        FROM sensor_averages sa
        JOIN face_averages fa ON sa.face = fa.face
        WHERE ABS(sa.sensor_avg - fa.face_avg) / fa.face_avg > :threshold
    ";

        $params[':threshold'] = $threshold;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

}
