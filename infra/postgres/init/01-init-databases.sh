#!/bin/sh
# =============================================================================
# Script de inicializacion de PostgreSQL (se ejecuta UNA sola vez, cuando el
# volumen de datos esta vacio). docker-entrypoint-initdb.d se ejecuta con
# superusuario y con las variables POSTGRES_* ya definidas.
# =============================================================================
set -eu

NOTIFICATIONS_DB="${POSTGRES_NOTIFICATIONS_DB:-clinic_notifications}"
MAIN_DB="${POSTGRES_DB:-clinic_app}"

echo "==> [init] Base de datos principal: ${MAIN_DB}"
echo "==> [init] Base de datos de notificaciones: ${NOTIFICATIONS_DB}"

# -----------------------------------------------------------------------------
# 1) Base de datos secundaria del microservicio FastAPI
#    (una instancia de PostgreSQL, dos bases de datos = aislamiento logico
#     sin necesidad de un segundo contenedor para la demo)
# -----------------------------------------------------------------------------
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "postgres" <<EOSQL
SELECT 'CREATE DATABASE "${NOTIFICATIONS_DB}"'
 WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${NOTIFICATIONS_DB}')\gexec
EOSQL

# -----------------------------------------------------------------------------
# 2) Extension btree_gist en la base principal.
#    Necesaria para poder combinar la restriction de exclusion de PostgreSQL
#    (btree_gist) con rangos de tiempo (gist sobre tstzrange).
#    Es la garantia de base de datos que respalda la regla de "no traslape".
# -----------------------------------------------------------------------------
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "${MAIN_DB}" <<EOSQL
CREATE EXTENSION IF NOT EXISTS btree_gist;
EOSQL

# -----------------------------------------------------------------------------
# 3) Parametrizacion por base de datos
# -----------------------------------------------------------------------------
for db in "${MAIN_DB}" "${NOTIFICATIONS_DB}"; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "${db}" <<EOSQL
ALTER DATABASE "${db}" SET timezone TO 'UTC';
ALTER DATABASE "${db}" SET statement_timeout TO '15000';
EOSQL
done

echo "==> [init] PostgreSQL listo."
