<?php

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Repositories\SensorRepository;

class SensorController
{
    protected $sensorRepo;

    public function __construct(SensorRepository $sensorRepo)
    {
        $this->sensorRepo = $sensorRepo;
    }

    // GET /sensors - List all sensors.
    public function index(Request $request, Response $response, array $args): Response
    {
        $sensors = $this->sensorRepo->getAllSensors();
        $payload = json_encode($sensors);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    // GET /sensors/{id} - Retrieve a specific sensor.
    public function get(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $sensor = $this->sensorRepo->getSensorById($id);
        if (!$sensor) {
            $payload = json_encode(['error' => 'Sensor not found']);
            $response->getBody()->write($payload);
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        $payload = json_encode($sensor);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    // POST /sensors - Create a new sensor.
    public function create(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody();

        $sensor_code = isset($data['sensor_code']) ? (int)$data['sensor_code'] : null;
        $face = isset($data['face']) ? trim($data['face']) : null;
        $installed_at = isset($data['installed_at']) ? trim($data['installed_at']) : null;
        $status = isset($data['status']) ? trim($data['status']) : 'active';

        $id = $this->sensorRepo->createSensor($sensor_code, $face, $installed_at, $status);
        $payload = json_encode(['id' => $id]);
        $response->getBody()->write($payload);
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    // PUT /sensors/{id} - Update an existing sensor.
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();

        $sensor_code = isset($data['sensor_code']) ? (int)$data['sensor_code'] : null;
        $face = isset($data['face']) ? trim($data['face']) : null;
        $installed_at = isset($data['installed_at']) ? trim($data['installed_at']) : null;
        $status = isset($data['status']) ? trim($data['status']) : 'active';

        $this->sensorRepo->updateSensor($id, $sensor_code, $face, $installed_at, $status);
        $payload = json_encode(['message' => 'Sensor updated successfully']);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    // DELETE /sensors/{id} - Delete a sensor.
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $this->sensorRepo->deleteSensor($id);
        $payload = json_encode(['message' => 'Sensor deleted successfully']);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }
}
