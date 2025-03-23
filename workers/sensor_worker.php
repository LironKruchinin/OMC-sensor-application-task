<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/Database.php';

use App\Database\Database;
use App\Repositories\SensorDataRepository;
use App\Repositories\SensorRepository;
use PDOException;

$pdo = Database::getInstance()->getConnection();

// Ensure the sensors table exists before proceeding.
try {
    $pdo->query("SELECT 1 FROM public.sensors LIMIT 1;");
} catch (PDOException $e) {
    error_log("Sensors table not found. Exiting sensor_worker.php.");
    exit(1);
}

define('TARGET_SENSOR_COUNT', 10000);

/**
 * Generate all missing sensors at once in a single transaction.
 * If the sensors count is already equal to TARGET_SENSOR_COUNT, skip processing.
 */
function generateMissingSensors(PDO $pdo)
{
    // Quick count check.
    $countStmt = $pdo->query("SELECT COUNT(*) FROM sensors");
    $currentCount = (int)$countStmt->fetchColumn();
    if ($currentCount >= TARGET_SENSOR_COUNT) {
        return;
    }

    // Retrieve all existing sensor codes.
    $stmt = $pdo->query("SELECT sensor_code FROM sensors");
    $existingCodes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Create a lookup array for quick existence checks.
    $existingCodesLookup = array_flip($existingCodes);

    $missingCodes = [];
    // Determine which sensor codes between 1 and TARGET_SENSOR_COUNT are missing.
    for ($i = 1; $i <= TARGET_SENSOR_COUNT; $i++) {
        if (!isset($existingCodesLookup[$i])) {
            $missingCodes[] = $i;
        }
    }

    if (empty($missingCodes)) {
        return;
    }

    $faces = ['north', 'east', 'south', 'west'];
    $missing = count($missingCodes);
    echo "Generating $missing sensors all at once...\n";

    // Bulk insert missing sensors in a transaction.
    $pdo->beginTransaction();
    $insertSensorStmt = $pdo->prepare("
        INSERT INTO sensors (sensor_code, face, installed_at, status)
        VALUES (:sensor_code, :face, :installed_at, :status)
    ");

    foreach ($missingCodes as $code) {
        $face = $faces[array_rand($faces)];
        $installed_at = time();
        $status = 'active';

        $insertSensorStmt->bindValue(':sensor_code', $code, PDO::PARAM_INT);
        $insertSensorStmt->bindValue(':face', $face, PDO::PARAM_STR);
        $insertSensorStmt->bindValue(':installed_at', $installed_at, PDO::PARAM_STR);
        $insertSensorStmt->bindValue(':status', $status, PDO::PARAM_STR);
        $insertSensorStmt->execute();
    }
    $pdo->commit();

    echo "Sensor generation complete. Total sensors: " . TARGET_SENSOR_COUNT . "\n";
}

/**
 * Bulk delete malfunctioning sensors for a given face.
 * This function deletes sensors whose average temperature (computed over sensor_data)
 * is outside the allowed range. With cascade deletes enabled, associated sensor_data rows
 * are automatically removed.
 *
 * @param PDO $pdo
 * @param string $face
 * @param float $allowedMin
 * @param float $allowedMax
 * @return int The number of sensors deleted.
 */
function deleteMalfunctioningSensorsByFace(PDO $pdo, string $face, float $allowedMin, float $allowedMax): int
{
    $sql = "
        DELETE FROM sensors 
        WHERE face = :face
          AND sensor_code IN (
            SELECT sub.sensor_code FROM (
              SELECT s.id, s.sensor_code, AVG(sd.temperature_value) AS sensor_avg
              FROM sensors s 
              JOIN sensor_data sd ON sd.sensor_id = s.sensor_code
              WHERE s.face = :face
              GROUP BY s.id, s.sensor_code
            ) AS sub
            WHERE sub.sensor_avg < :allowedMin OR sub.sensor_avg > :allowedMax
          )
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':face', $face, PDO::PARAM_STR);
    $stmt->bindValue(':allowedMin', $allowedMin);
    $stmt->bindValue(':allowedMax', $allowedMax);
    $stmt->execute();
    return $stmt->rowCount();
}

// Initial sensor generation.
generateMissingSensors($pdo);

$sensorStmt = $pdo->query("SELECT sensor_code FROM sensors ORDER BY sensor_code ASC");
$sensors = $sensorStmt->fetchAll(PDO::FETCH_COLUMN);

if (!$sensors) {
    die("No sensors found. Please ensure the sensors table is populated.\n");
}

echo "Starting sensor worker for " . count($sensors) . " sensors.\n";

// Designate 1% of sensors as malfunctioning permanently.
$malfunctionSensors = [];
$malfunctionCount = max(1, floor(count($sensors) * 0.01));
$randomKeys = array_rand($sensors, $malfunctionCount);
if ($malfunctionCount === 1) {
    $malfunctionSensors = [$sensors[$randomKeys]];
} else {
    foreach ($randomKeys as $key) {
        $malfunctionSensors[] = $sensors[$key];
    }
}
echo "Designated " . count($malfunctionSensors) . " sensors as malfunctioning.\n";

// Create repository instances.
$sensorDataRepo = new SensorDataRepository($pdo);
$sensorRepo = new SensorRepository($pdo);

$debug = true;
// Set the aggregation interval (in seconds).
$aggregationInterval = 1;
$lastAggregationCheck = time();

while (true) {
    $startTime = microtime(true);
    $insertCount = 0;

    // Bulk insert readings for all sensors in one transaction.
    $pdo->beginTransaction();
    foreach ($sensors as $sensorId) {
        $timestamp = time();
        // If the sensor is designated as malfunctioning, generate an abnormal reading.
        if (in_array($sensorId, $malfunctionSensors)) {
            // Randomly choose a low or high abnormal temperature.
            if (mt_rand(0, 1) === 0) {
                // Low abnormal temperature (e.g., 10.0 - 14.0°C).
                $temperature = mt_rand(100, 140) / 10;
            } else {
                // High abnormal temperature (e.g., 30.0 - 35.0°C).
                $temperature = mt_rand(300, 350) / 10;
            }
        } else {
            // Normal temperature reading (e.g., 15.0 - 30.0°C).
            $temperature = mt_rand(150, 300) / 10;
        }
        $sensorDataRepo->createReading($sensorId, $timestamp, $temperature);
        $insertCount++;
    }
    $pdo->commit();

    if ($debug) {
        echo "[" . date("Y-m-d H:i:s") . "] Inserted $insertCount temperature readings in this iteration.\n";
    }

    // Run the aggregation and deletion check when the interval has elapsed.
    if ((time() - $lastAggregationCheck) >= $aggregationInterval) {
        $startAggregation = date("Y-m-d H:i:s", time() - $aggregationInterval);
        $endAggregation = date("Y-m-d H:i:s", time());
        echo "[" . date("Y-m-d H:i:s") . "] Running aggregation check from $startAggregation to $endAggregation...\n";

        // Get overall aggregated averages for each face using 'minute' grouping.
        $overallAverages = $sensorDataRepo->getOverallAggregatedAverage('minute', $startAggregation, $endAggregation);
        foreach ($overallAverages as $face => $avg) {
            echo "[" . date("Y-m-d H:i:s") . "] Face: $face - Overall Average Temperature: $avg\n";
        }

        // For each face, set allowed range as ±20% of the overall average.
        foreach ($overallAverages as $face => $avg) {
            $allowedMin = $avg * 0.80;
            $allowedMax = $avg * 1.20;
            echo "[" . date("Y-m-d H:i:s") . "] For face $face, allowed temperature range: [$allowedMin, $allowedMax]\n";

            // First, check if there are any malfunctioning sensors to delete.
            $countSql = "
                SELECT COUNT(*) FROM (
                    SELECT s.sensor_code, AVG(sd.temperature_value) AS sensor_avg
                    FROM sensors s 
                    JOIN sensor_data sd ON sd.sensor_id = s.sensor_code
                    WHERE s.face = :face
                    GROUP BY s.sensor_code
                    HAVING AVG(sd.temperature_value) < :allowedMin OR AVG(sd.temperature_value) > :allowedMax
                ) AS sub
            ";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->bindValue(':face', $face, PDO::PARAM_STR);
            $countStmt->bindValue(':allowedMin', $allowedMin);
            $countStmt->bindValue(':allowedMax', $allowedMax);
            $countStmt->execute();
            $malfunctioningCount = (int)$countStmt->fetchColumn();

            if ($malfunctioningCount > 0) {
                $deletedCount = deleteMalfunctioningSensorsByFace($pdo, $face, $allowedMin, $allowedMax);
                echo "[" . date("Y-m-d H:i:s") . "] Deleted $deletedCount malfunctioning sensors on face $face.\n";
            } else {
                echo "[" . date("Y-m-d H:i:s") . "] No malfunctioning sensors to delete on face $face.\n";
            }
        }

        // Regenerate any missing sensors if the total count is below TARGET_SENSOR_COUNT.
        $sensorCountStmt = $pdo->query("SELECT COUNT(*) FROM sensors");
        $totalSensors = (int)$sensorCountStmt->fetchColumn();
        if ($totalSensors < TARGET_SENSOR_COUNT) {
            generateMissingSensors($pdo);
            // Refresh the sensor list.
            $sensorStmt = $pdo->query("SELECT sensor_code FROM sensors ORDER BY sensor_code ASC");
            $sensors = $sensorStmt->fetchAll(PDO::FETCH_COLUMN);
            echo "[" . date("Y-m-d H:i:s") . "] Sensor list updated. Total sensors: " . count($sensors) . "\n";
        } else {
            echo "[" . date("Y-m-d H:i:s") . "] Total sensors remain at " . TARGET_SENSOR_COUNT . ". No regeneration needed.\n";
        }

        $lastAggregationCheck = time();
    }

    $elapsed = microtime(true) - $startTime;
    if ($elapsed < 1) {
        usleep((1 - $elapsed) * 1000000);
    }
}
