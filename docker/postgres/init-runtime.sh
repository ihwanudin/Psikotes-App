#!/bin/sh
set -eu

psql --set=ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    --file /docker-entrypoint-initdb.d/10-postgres-roles.sql

psql --set=ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    --set=runtime_password="$DB_RUNTIME_PASSWORD" <<'SQL'
ALTER ROLE psikotes_runtime LOGIN PASSWORD :'runtime_password';
SQL
