# OMC Sensor Application

This project is a solution to the OMC Developer Challenge. The goal is to monitor and manage 10,000 temperature sensors installed on a commercial tower ("A-Tower"). Each sensor sends one temperature sample per second. The system aggregates and averages temperature data by configurable intervals (e.g., hourly, daily, or per minute), identifies malfunctioning sensors (those deviating more than 20% from the average of sensors on the same face), and automatically replaces malfunctioning sensors.

## Architecture & Components

The solution is built using Docker and Docker Compose and consists of three main services:

- **db**: A PostgreSQL database that stores sensor metadata and sensor readings.
- **server**: A PHP Slim Framework-based web server that provides HTTP endpoints for sensor reporting.
- **worker**: A background sensor worker process that:
    - Generates any missing sensors to maintain 10,000 sensors.
    - Simulates temperature readings for each sensor.
    - Aggregates temperature data by a configurable interval.
    - Identifies malfunctioning sensors and removes them (then regenerates sensors to maintain the total count).

## Prerequisites

- [Docker](https://www.docker.com/) and [Docker Compose](https://docs.docker.com/compose/) must be installed on your system.
- A Unix-like environment is recommended for running shell commands. Windows users may use PowerShell with appropriate adjustments.

## Setup

1. **Environment Variables**

   Create a `.env` file in the same directory as your `docker-compose.yml` file with the following content (adjust values as needed):

   ```dotenv
   DB_HOST=db
   DB_PORT=5432
   DB_NAME=omc
   DB_USER=user
   DB_PWD=admin
   ```
   
2. **Build and Start the Containers**

   To build the project and start all services, run:
    
    ```
    docker compose --env-file .env up --build
    ```

   For subsequent runs (without rebuilding), use:

    ```
    docker compose --env-file .env up
    ```

## Usage

- **Web Server Endpoints**
  - The web server runs on http://localhost:8000. Endpoints (such as /sensors and any reporting endpoints you add) allow you to view sensor data and reports.
- **Worker Process**
  
    The sensor worker continuously:
  - Generates sensor readings.
  - Runs a configurable aggregation check (default is every 60 seconds, configurable via ```$aggregationInterval```).
  - Removes malfunctioning sensors (sensors with temperature averages deviating >20% from the overall face average) and regenerates missing sensors.

```
docker compose logs worker
```

## Code Overview
- **SensorRepository:**
    Manages CRUD operations for the ``sensors`` table.
- **SensorDataRepository:**
  Handles inserting temperature readings, aggregating temperature data by any specified interval (hour, day, minute, etc.), and detecting malfunctioning sensors.
- **sensor_worker.php:**
  The main worker script that:
  - Ensures 10,000 sensors exist.
  - Simulates temperature readings and inserts them into the database.
  - Every configurable interval (e.g., every 60 seconds by default), aggregates temperature data by face, calculates overall averages, detects malfunctioning sensors, deletes them (using the SensorRepository delete function by id), and regenerates missing sensors.
- **Index.php & Server:**
  -  Uses the PHP Slim Framework to expose HTTP endpoints for sensor reporting.

## Troubleshooting
- **Environment Variables:**
  Ensure that the `.env` file is in the same directory as `docker-compose.yml` and that the variables are correctly passed into the containers (check with `docker compose exec <service> env`).
- **Database Connectivity:**
  If the database is not connecting, verify that PostgreSQL is running and that the DB credentials in your `.env` file match those expected by the container.
- **Logs:**
  Use the following commands to check logs for troubleshooting:
    ``` 
    docker compose logs server
    docker compose logs worker
    docker compose logs db
    ```
