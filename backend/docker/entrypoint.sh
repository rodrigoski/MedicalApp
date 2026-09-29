#!/bin/sh
# =============================================================================
# ClinicApp - entrypoint del contenedor PHP
# -----------------------------------------------------------------------------
# Responsabilidades:
#   1. Asegurar que los directorios de escritura existan y tengan el dueno
#      correcto (www-data).
#   2. Resolver APP_KEY de forma segura: si no viene por entorno, se genera una
#      clave aleatoria de 256 bits y se persiste en el volumen compartido
#      /run/secrets. Nunca se escribe en el repositorio ni en la imagen.
#   3. Bajar privilegios a www-data antes de ejecutar el proceso principal.
# =============================================================================
set -eu

APP_DIR="${APP_DIR:-/var/www/html}"
SECRETS_DIR="${SECRETS_DIR:-/run/secrets}"
KEY_FILE="${SECRETS_DIR}/app_key"

log() { printf '[entrypoint] %s\n' "$*"; }

# --- 1. directorios de escritura ---------------------------------------------
for dir in \
    "${APP_DIR}/storage/framework/cache/data" \
    "${APP_DIR}/storage/framework/sessions" \
    "${APP_DIR}/storage/framework/views" \
    "${APP_DIR}/storage/logs" \
    "${APP_DIR}/bootstrap/cache" ; do
    [ -d "$dir" ] || mkdir -p "$dir"
done
chown -R www-data:www-data "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache" 2>/dev/null || true

# --- 2. APP_KEY ----------------------------------------------------------------
if [ -z "${APP_KEY:-}" ]; then
    if mkdir -p "$SECRETS_DIR" 2>/dev/null && [ -w "$SECRETS_DIR" ]; then
        if [ ! -f "$KEY_FILE" ]; then
            log "Generando APP_KEY (256 bits) en ${KEY_FILE}"
            php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$KEY_FILE"
            chmod 600 "$KEY_FILE" || true
        fi
        APP_KEY="$(cat "$KEY_FILE")"
        export APP_KEY
        log "APP_KEY cargado desde el volumen de secretos."
    else
        log "AVISO: no se pudo escribir en ${SECRETS_DIR}. Defina APP_KEY en el entorno."
    fi
else
    log "APP_KEY provista por el entorno del despliegue."
fi

# --- 3. comprobaciones de entorno ---------------------------------------------
if [ -z "${DB_HOST:-}" ] && [ -z "${DB_CONNECTION:-}" ]; then
    log "AVISO: DB_CONNECTION no esta definido; se usara la configuracion por defecto."
fi

log "Arrancando: $*"
exec su-exec www-data "$@"
