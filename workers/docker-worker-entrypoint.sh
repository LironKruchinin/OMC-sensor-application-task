#!/bin/bash
set -e

echo "DB_HOST is: $DB_HOST"
echo "DB_PORT is: $DB_PORT"
echo "DB_NAME is: $DB_NAME"
echo "DB_USER is: $DB_USER"

echo "Waiting for PostgreSQL to be ready..."
until pg_isready -h "$DB_HOST" -p "$DB_PORT"; do
  echo "Database not ready, waiting..."
  sleep 1
done

# Export the password so psql can authenticate
export PGPASSWORD="$DB_PWD"

echo "Database is up. Running migrations..."
php /var/www/workers/migrate.php

echo "Migrations complete. Checking for the sensors table..."
until psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "SELECT 1 FROM public.sensors LIMIT 1;" > /dev/null 2>&1; do
  echo "Sensors table not found. Waiting for migrations to run..."
  sleep 1
done

echo "Sensors table exists. Starting sensor worker..."
php /var/www/workers/sensor_worker.php
