<?php
namespace App\Models;
class Sensor
{
    private int $_id;
    public string $_sensor_position;
    public float $temperature;
    public int $timestamp;

    public function __construct(int $id, string $sensor_position, float $temperature, int $timestamp) {
        $this->_id = $id;
        $this->_sensor_position = $sensor_position;
        $this->temperature = $temperature;
        $this->timestamp = $timestamp;
    }

}