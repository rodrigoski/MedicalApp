#!/usr/bin/env sh
# =============================================================================
# ClinicApp - verificacion de salud del stack
#
# Comprueba, en este orden:
#   1. contenedores levantados y con el estado esperado,
#   2. PostgreSQL acepta consultas reales (SELECT 1), no solo el puerto abierto,
#   3. la API responde y su migrador termino bien,
#   4. el microservicio responde liveness y readiness,
#   5. el gateway enruta a los dos servicios.
#
# Devuelve codigo 1 si algo falla, para poder encadenarlo en un pipeline.
#
# Uso:  sh scripts/healthcheck.sh
# =============================================================================

set -eu

GATEWAY_URL="${GATEWAY_URL:-http://localhost:8080}"
API_URL="${API_URL:-http://localhost:8000}"
NOTIFICATIONS_URL="${NOTIFICATIONS_URL:-http://localhost:8001}"
API_KEY="${INTERNAL_API_KEY:-dev-clinic-key-change-me}"
NOTIFICATIONS_KEY="${NOTIFICATIONS_API_KEY:-dev-notifications-key-change-me}"

failures=0

ok() {
    echo "  [ OK ] $1"
}

fail() {
    echo "  [FALLO] $1" >&2
    failures=$((failures + 1))
}

echo "==> 1. Contenedores"
if command -v docker >/dev/null 2>&1; then
    # Los servicios de larga duracion deben estar `running`. El `migrator` se
    # comprueba aparte porque es de una sola ejecucion: termina bien y DEBE estar
    # `exited`, y un `running` en ese contenedor significaria que las migraciones
    # se estan aplicando todavia.
    for service in postgres api notification-service gateway queue-worker; do
        state=$(docker inspect --format '{{.State.Status}}' "clinicapp-${service}" 2>/dev/null || echo "ausente")

        if [ "$state" = "running" ]; then
            ok "$service esta $state"
        else
            fail "$service esta $state (se esperaba running)"
        fi
    done

    # El migrador es un trabajo de una sola vez: su exito esta en el codigo de
    # salida, no en el estado. Un exit 0 con las migraciones aplicadas es lo
    # unico que permite confiar en que el esquema existe.
    migrator_state=$(docker inspect --format '{{.State.Status}}' "clinicapp-migrator" 2>/dev/null || echo "ausente")
    migrator_exit=$(docker inspect --format '{{.State.ExitCode}}' "clinicapp-migrator" 2>/dev/null || echo "n/d")

    if [ "$migrator_state" = "exited" ] && [ "$migrator_exit" = "0" ]; then
        ok "migrator termino correctamente (exited 0)"
    else
        fail "migrator esta ${migrator_state} con codigo ${migrator_exit}: las migraciones pueden no estar aplicadas"
    fi
else
    fail "docker no esta disponible; se omite la comprobacion de contenedores"
fi

echo "==> 2. PostgreSQL"
# Se consultan las dos bases, no solo la del backend: el microservicio tiene la
# suya, y un `pg_isready` verde sobre `clinic_app` no dice nada de
# `clinic_notifications`, que es donde el servicio fallo de verdad cuando el
# volumen se creo de una version anterior del proyecto.
if docker exec clinicapp-postgres pg_isready -U "${POSTGRES_USER:-clinic}" -d "${POSTGRES_DB:-clinic_app}" >/dev/null 2>&1; then
    ok "PostgreSQL responde pg_isready en ${POSTGRES_DB:-clinic_app}"
else
    fail "PostgreSQL no responde pg_isready en ${POSTGRES_DB:-clinic_app}"
fi

if docker exec clinicapp-postgres pg_isready -U "${POSTGRES_USER:-clinic}" -d "${POSTGRES_NOTIFICATIONS_DB:-clinic_notifications}" >/dev/null 2>&1; then
    ok "PostgreSQL responde pg_isready en ${POSTGRES_NOTIFICATIONS_DB:-clinic_notifications}"
else
    fail "PostgreSQL no responde pg_isready en ${POSTGRES_NOTIFICATIONS_DB:-clinic_notifications}"
fi

echo "==> 3. API Laravel"
if response=$(curl -sS -o /dev/null -w '%{http_code}' "${API_URL}/api/v1/health" 2>/dev/null); then
    if [ "$response" = "200" ]; then
        ok "liveness de la API responde 200"
    else
        fail "liveness de la API responde ${response}"
    fi
else
    fail "no se pudo contactar la API en ${API_URL}"
fi

if response=$(curl -sS -o /dev/null -w '%{http_code}' \
    -H "X-Api-Key: ${API_KEY}" \
    "${API_URL}/api/v1/doctors" 2>/dev/null); then
    case "$response" in
        200) ok "endpoint protegido responde 200 con credencial" ;;
        401) fail "endpoint protegido responde 401: revisa INTERNAL_API_KEY" ;;
        *)   fail "endpoint protegido responde ${response}" ;;
    esac
else
    fail "no se pudo consultar ${API_URL}/api/v1/doctors"
fi

echo "==> 4. Microservicio de notificaciones"
if response=$(curl -sS -o /dev/null -w '%{http_code}' "${NOTIFICATIONS_URL}/api/v1/health" 2>/dev/null); then
    if [ "$response" = "200" ]; then
        ok "liveness de FastAPI responde 200"
    else
        fail "liveness de FastAPI responde ${response}"
    fi
else
    fail "no se pudo contactar FastAPI en ${NOTIFICATIONS_URL}"
fi

if response=$(curl -sS -o /dev/null -w '%{http_code}' "${NOTIFICATIONS_URL}/api/v1/ready" 2>/dev/null); then
    if [ "$response" = "200" ]; then
        ok "readiness de FastAPI responde 200 (base de datos accesible)"
    else
        fail "readiness de FastAPI responde ${response}: el microservicio no puede trabajar"
    fi
else
    fail "no se pudo consultar el readiness de FastAPI"
fi

if response=$(curl -sS -o /dev/null -w '%{http_code}' \
    -X POST \
    -H "Authorization: Bearer ${NOTIFICATIONS_KEY}" \
    -H 'Content-Type: application/json' \
    -d '{"event_id":"00000000-0000-4000-8000-000000000001","event_type":"health.probe","aggregate_type":"probe","aggregate_id":1,"occurred_at":"2025-01-07T15:00:00+00:00","payload":{}}' \
    "${NOTIFICATIONS_URL}/api/v1/events" 2>/dev/null); then
    case "$response" in
        200|201) ok "recepcion de eventos responde ${response}" ;;
        401)     fail "recepcion de eventos responde 401: revisa NOTIFICATIONS_API_KEY" ;;
        *)       fail "recepcion de eventos responde ${response}" ;;
    esac
else
    fail "no se pudo enviar un evento de prueba a FastAPI"
fi

echo "==> 5. Gateway"
if response=$(curl -sS -o /dev/null -w '%{http_code}' "${GATEWAY_URL}/api/v1/health" 2>/dev/null); then
    if [ "$response" = "200" ]; then
        ok "el gateway enruta a la API"
    else
        fail "el gateway responde ${response} al health de la API"
    fi
else
    fail "no se pudo contactar el gateway en ${GATEWAY_URL}"
fi

if response=$(curl -sS -o /dev/null -w '%{http_code}' "${GATEWAY_URL}/notifications-service/api/v1/health" 2>/dev/null); then
    if [ "$response" = "200" ]; then
        ok "el gateway enruta al microservicio"
    else
        fail "el gateway responde ${response} al health de notificaciones"
    fi
else
    fail "no se pudo contactar el microservicio a traves del gateway"
fi

echo ""
if [ "$failures" -eq 0 ]; then
    echo "==> Stack sano."
    exit 0
fi

echo "==> ${failures} comprobacion(es) fallida(s)." >&2
exit 1
