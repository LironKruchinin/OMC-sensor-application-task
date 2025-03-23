<?php

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/Database.php';

use Slim\App;
use App\Database\Database;
use App\Repositories\SensorRepository;
use App\Controllers\SensorController;

$pdo = Database::getInstance()->getConnection();

/**
 * Check if the sensor worker daemon is running.
 *
 * @return bool
 */
function isWorkerRunning()
{
    $output = [];
    // Check for any process running sensor_worker.php
    exec("pgrep -f sensor_worker.php", $output);
    return !empty($output);
}

// Start sensor worker daemon if not already running
if (!isWorkerRunning()) {
    // Ensure the logs directory exists.
    $logsDir = __DIR__ . '/../logs';
    if (!is_dir($logsDir)) {
        mkdir($logsDir, 0755, true);
    }
    // Run sensor_worker.php in the background and redirect output to logs/worker.log
    exec("nohup php " . __DIR__ . "/../workers/sensor_worker.php > " . $logsDir . "/worker.log 2>&1 &");
    error_log("Sensor worker daemon started.");
} else {
    error_log("Sensor worker daemon already running.");
}

$app = new App();

require_once __DIR__ . '/../src/Controllers/SensorController.php';

$sensorRepository = new SensorRepository($pdo);
$sensorController = new SensorController($sensorRepository);

$app->get('/sensors', [$sensorController, 'index']);
$app->get('/sensors/{id}', [$sensorController, 'get']);
$app->post('/sensors', [$sensorController, 'create']);
$app->put('/sensors/{id}', [$sensorController, 'update']);
$app->delete('/sensors/{id}', [$sensorController, 'delete']);

$app->get('/', function ($request, $response, $args) {
    $response->getBody()->write("Welcome to the Sensor API!");
    return $response;
});

ob_clean();
$app->run();
