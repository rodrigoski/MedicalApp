#!/usr/bin/env sh
# =============================================================================
# ClinicApp - espera a que PostgreSQL acepte conexiones
#
# Por que existe: `docker compose` espera a los health checks, pero un script
# ejecutado a mano (o desde un pipeline de CI) necesita lo mismo sin depender
# del orquestador. Es la MISMA comprobacion, no una distinta.
#
# Uso:  sh scripts/wait-for-postgres.sh [host] [port] [timeout_segundos]
# =============================================================================

set -eu

HOST="${1:-postgres}"
PORT="${2:-5432}"
TIMEOUT="${3:-60}"

echo "==> Esperando a PostgreSQL en ${HOST}:${PORT} (max ${TIMEOUT}s)"

elapsed=0
while [ "$elapsed" -lt "$TIMEOUT" ]; do
    if nc -z "$HOST" "$PORT" 2>/dev/null; then
        echo "==> PostgreSQL acepta conexiones tras ${elapsed}s."
        exit 0
    fi

    # Un punto cada 5 segundos: no hay que adivinar si el proceso sigue vivo.
    if [ $((elapsed % 5)) -eq 0 ]; then
        echo "    ${elapsed}s..."
    fi

    sleep 1
    elapsed=$((elapsed + 1))
done

echo "!! PostgreSQL no respondio en ${TIMEOUT}s (${HOST}:${PORT})." >&2
exit 1
