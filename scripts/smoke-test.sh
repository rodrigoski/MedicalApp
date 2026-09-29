#!/usr/bin/env sh
# =============================================================================
# ClinicApp - recorrido funcional de extremo a extremo
#
# Ejecuta el camino completo de un caso de uso real:
#   1. lista medicos,
#   2. pide disponibilidad de un dia,
#   3. crea un paciente,
#   4. reserva una cita,
#   5. intenta reservar una cita que se traslapa (debe fallar con 409),
#   6. consulta la cita,
#   7. cancela la cita,
#   8. entrega la outbox y comprueba que el microservicio recibio una
#      notificacion NUEVA de esta ejecucion.
#
# Sirve como evidencia de auditoria: deja constancia de que la regla central
# ("no hay dos citas traslapadas para el mismo medico") se cumple en el sistema
# DESPLEGADO, no solo en las pruebas unitarias.
#
# Uso:  sh scripts/smoke-test.sh
#       DISPATCH_CMD="make events-dispatch" sh scripts/smoke-test.sh
#       TARGET_DATE=2026-01-15 sh scripts/smoke-test.sh   (fuerza la fecha)
#
# IDEMPOTENCIA: se puede ejecutar tantas veces como se quiera. El documento y el
# correo del paciente de prueba se generan con el PID y la hora, de modo que dos
# ejecuciones seguidas no choquen con el indice unico.
# =============================================================================

set -eu

API_URL="${API_URL:-http://localhost:8080}"
API_KEY="${INTERNAL_API_KEY:-dev-clinic-key-change-me}"
NOTIFICATIONS_URL="${NOTIFICATIONS_URL:-http://localhost:8080/notifications-service}"
NOTIFICATIONS_KEY="${NOTIFICATIONS_API_KEY:-dev-notifications-key-change-me}"
COMPOSE_CMD="${COMPOSE_CMD:-docker compose}"

# --- Fecha del recorrido ---------------------------------------------------------
# El dominio cierra los domingos y solo los domingos, asi que se avanza desde hoy
# hasta el primer dia de atencion. Sin este ajuste el recorrido fallaria una de
# cada siete ejecuciones, y el fallo se presentaria como un problema de la regla
# de no traslape cuando en realidad se intento reservar en domingo.

# Suma dias a hoy. `date -d` es GNU y `date -v` es BSD/macOS; se prueban ambos
# porque el script se ejecuta indistintamente en Linux, en macOS y dentro de un
# contenedor.
add_days() {
    date -u -d "$1 days" +%Y-%m-%d 2>/dev/null || date -u -v+"$1"d +%Y-%m-%d
}

# Dia de la semana en ISO 8601: 1 = lunes ... 7 = domingo. `%u` esta disponible
# tanto en GNU como en BSD, a diferencia de `%w`, que arranca en domingo.
weekday_of() {
    date -u -d "$1 00:00:00" +%u 2>/dev/null || date -u -j -f '%Y-%m-%d' "$1" +%u
}

next_attention_day() {
    _offset=7

    # Se empieza a siete dias para no coincidir con la agenda que dejo el seed,
    # que arranca en el proximo dia de atencion.
    while [ "$_offset" -le 14 ]; do
        _candidate=$(add_days "$_offset")

        if [ "$(weekday_of "$_candidate")" != "7" ]; then
            printf '%s' "$_candidate"
            return 0
        fi

        _offset=$((_offset + 1))
    done

    # No deberia alcanzarse: en una semana completa siempre hay un dia laborable.
    # Se devuelve igualmente el valor para no abortar el recorrido con un error
    # de shell en lugar de un diagnostico util.
    add_days "$_offset"
}

TARGET_DATE="${TARGET_DATE:-$(next_attention_day)}"
SLOT_START="${SLOT_START:-13:00:00}"
SLOT_END="${SLOT_END:-13:30:00}"

# 13:00Z = 08:00 en America/Bogota, dentro de la jornada 08:00-18:00.
RUN_TAG="$$$(date -u +%H%M%S)"
TEST_DOCUMENT="CC${RUN_TAG}"
TEST_EMAIL="humo.${RUN_TAG}@clinicapp.local"

failures=0

pass() { echo "  [ OK ] $1"; }
fail() { echo "  [FALLO] $1" >&2; failures=$((failures + 1)); }

BODY_FILE=$(mktemp)
trap 'rm -f "$BODY_FILE"' EXIT INT TERM

# api METODO RUTA [CUERPO_JSON]
#
# Fija dos variables: API_STATUS (codigo HTTP real) y BODY (cuerpo de la
# respuesta). Se invoca SIN sustitucion de comandos, precisamente para que esas
# variables sobrevivan: dentro de `$(...)` se perderian, y entonces no habria
# forma fiable de distinguir un 409 de un 422 sin raspar el JSON.
api() {
    _method="$1"
    _path="$2"
    _body="${3:-}"

    if [ -n "$_body" ]; then
        API_STATUS=$(curl -sS -o "$BODY_FILE" -w '%{http_code}' \
            -X "$_method" \
            -H "X-Api-Key: ${API_KEY}" \
            -H 'Content-Type: application/json' \
            -H 'Accept: application/json' \
            -d "$_body" \
            "${API_URL}${_path}")
    else
        API_STATUS=$(curl -sS -o "$BODY_FILE" -w '%{http_code}' \
            -X "$_method" \
            -H "X-Api-Key: ${API_KEY}" \
            -H 'Accept: application/json' \
            "${API_URL}${_path}")
    fi

    BODY=$(cat "$BODY_FILE")
}

# Primer "id":<numero> del cuerpo. Los recursos ordenan `id` primero, asi que el
# primer numero encontrado es el de la entidad y no el de un anidado.
first_id() {
    printf '%s' "$1" | tr ',' '\n' | sed -n 's/.*"id":\([0-9][0-9]*\).*/\1/p' | head -n 1
}

# notifications METODO RUTA [CUERPO_JSON]
#
# Igual que `api()`, fija NOTIF_STATUS y NOTIF_BODY sin sustituir comandos, por el
# mismo motivo: dentro de `$(...)` esas variables se perderian. Ademas evita
# depender de partir la respuesta para separar el codigo HTTP, que se rompe en
# cuanto un dia el JSON se devuelve con formato multilinea.
notifications() {
    _method="$1"
    _path="$2"
    _body="${3:-}"

    if [ -n "$_body" ]; then
        NOTIF_STATUS=$(curl -sS -o "$BODY_FILE" -w '%{http_code}' \
            -X "$_method" \
            -H "Authorization: Bearer ${NOTIFICATIONS_KEY}" \
            -H 'Content-Type: application/json' \
            -H 'Accept: application/json' \
            -d "$_body" \
            "${NOTIFICATIONS_URL}${_path}")
    else
        NOTIF_STATUS=$(curl -sS -o "$BODY_FILE" -w '%{http_code}' \
            -X "$_method" \
            -H "Authorization: Bearer ${NOTIFICATIONS_KEY}" \
            -H 'Accept: application/json' \
            "${NOTIFICATIONS_URL}${_path}")
    fi

    NOTIF_BODY=$(cat "$BODY_FILE")
}

# Identificadores de las notificaciones existentes, separados por saltos de linea.
#
# Se toman ANTES y DESPUES de entregar la outbox. Comparar solo "hay alguna
# notificacion" no demuestra nada: el microservicio conserva las de ejecuciones
# anteriores, asi que ese paso pasaria siempre y no detectaria una outbox rota.
notification_ids() {
    notifications GET '/api/v1/notifications?limit=200' >/dev/null

    printf '%s' "$NOTIF_BODY" \
        | tr ',' '\n' \
        | sed -n 's/.*"id":"\([A-Za-z0-9_-]*\)".*/\1/p'
}

echo "==> 1. Medicos disponibles"
api GET "/api/v1/doctors?per_page=1"
DOCTOR_ID=$(first_id "$BODY")

if [ -n "$DOCTOR_ID" ]; then
    pass "medico encontrado: id=${DOCTOR_ID}"
else
    fail "no hay medicos; ejecuta 'make seed'"
    echo "Abortando: el resto del recorrido necesita un medico."
    exit 1
fi

echo "==> 2. Disponibilidad del ${TARGET_DATE}"
api GET "/api/v1/doctors/${DOCTOR_ID}/availability?date=${TARGET_DATE}"
if printf '%s' "$BODY" | grep -q '"free_slots"'; then
    pass "la API responde disponibilidad con huecos libres"
else
    fail "disponibilidad inesperada (HTTP ${API_STATUS}): ${BODY}"
fi

echo "==> 3. Alta de un paciente de prueba (${TEST_DOCUMENT})"
api POST "/api/v1/patients" "{
    \"full_name\": \"Paciente de Humo\",
    \"document_id\": \"${TEST_DOCUMENT}\",
    \"email\": \"${TEST_EMAIL}\",
    \"birth_date\": \"1990-05-14\",
    \"gender\": \"F\",
    \"phone\": \"+573001112233\"
}"
PATIENT_ID=$(first_id "$BODY")

if [ -n "$PATIENT_ID" ]; then
    pass "paciente creado: id=${PATIENT_ID}"
else
    fail "no se pudo crear el paciente (HTTP ${API_STATUS}): ${BODY}"
fi

echo "==> 4. Reserva de cita el ${TARGET_DATE} ${SLOT_START}"
api POST "/api/v1/appointments" "{
    \"patient_id\": ${PATIENT_ID:-0},
    \"doctor_id\": ${DOCTOR_ID},
    \"start_at\": \"${TARGET_DATE}T${SLOT_START}Z\",
    \"end_at\": \"${TARGET_DATE}T${SLOT_END}Z\",
    \"reason\": \"Recurrencia de prueba\"
}"
APPOINTMENT_ID=$(first_id "$BODY")

if [ -n "$APPOINTMENT_ID" ]; then
    pass "cita reservada: id=${APPOINTMENT_ID}"
else
    fail "no se pudo reservar la cita (HTTP ${API_STATUS}): ${BODY}"
fi

echo "==> 5. Segundo intento sobre el mismo horario (debe ser rechazado)"
api POST "/api/v1/appointments" "{
    \"patient_id\": ${PATIENT_ID:-0},
    \"doctor_id\": ${DOCTOR_ID},
    \"start_at\": \"${TARGET_DATE}T${SLOT_START}Z\",
    \"end_at\": \"${TARGET_DATE}T${SLOT_END}Z\",
    \"reason\": \"Intento de traslape\"
}"
OVERLAP="$BODY"

case "$API_STATUS" in
    409) pass "el traslape se rechaza con 409 (regla central verificada)" ;;
    422) fail "el traslape dio 422 y no 409: la API no llego a evaluar el traslape. ${OVERLAP}" ;;
    *)   fail "el traslape no fue rechazado como se esperaba (HTTP ${API_STATUS}): ${OVERLAP}" ;;
esac

# Codigos que emite la API para un conflicto de agenda. `db_constraint` tambien
# cuenta: significa que gano otra transaccion, que es un 409 igualmente correcto
# y, de hecho, la prueba de que la base de datos esta protegiendo el horario.
if printf '%s' "$OVERLAP" | grep -q '"code":"\(doctor_busy\|patient_busy\|db_constraint\)"'; then
    pass "el error identifica el conflicto de traslape"
else
    fail "el error no identifica el conflicto: ${OVERLAP}"
fi

echo "==> 6. Consulta de la cita"
api GET "/api/v1/appointments/${APPOINTMENT_ID}"
if printf '%s' "$BODY" | grep -q "\"id\":${APPOINTMENT_ID}"; then
    pass "la cita se puede consultar"
else
    fail "la consulta fallo (HTTP ${API_STATUS}): ${BODY}"
fi

echo "==> 7. Cancelacion"
api PATCH "/api/v1/appointments/${APPOINTMENT_ID}/status" '{"status":"cancelled"}'
if printf '%s' "$BODY" | grep -q '"status":"cancelled"'; then
    pass "la cita se cancela"
else
    fail "la cancelacion fallo (HTTP ${API_STATUS}): ${BODY}"
fi

echo "==> 8. Entrega de la outbox y recepcion en el microservicio"

# Inventario previo. Se toma ANTES de entregar nada para poder distinguir las
# notificaciones de esta ejecucion de las que quedaran de anteriores.
# Los identificadores se separan por ESPACIOS, no por lineas: el `case` de abajo
# busca subcadenas delimitadas por espacio, y comparar lineas exigiria incrustar
# saltos de linea en el patron, que es fragil y dificil de leer.
BEFORE_IDS=$(notification_ids | tr '\n' ' ')
BEFORE_COUNT=$(printf '%s\n' "$BEFORE_IDS" | grep -c . || true)

# Sin este paso el 8 no puede pasar por principio: los eventos viven en la base
# del backend hasta que alguien los entrega. Por eso se dispara aqui y no antes,
# para que incluya los eventos que acaban de generar los pasos 3 a 7.
if [ -n "${DISPATCH_CMD:-}" ]; then
    # shellcheck disable=SC2086
    if $DISPATCH_CMD >/dev/null 2>&1; then
        pass "outbox entregada con: ${DISPATCH_CMD}"
    else
        fail "fallo la entrega de la outbox con: ${DISPATCH_CMD}"
    fi
elif command -v docker >/dev/null 2>&1; then
    if ${COMPOSE_CMD} exec -T api php artisan events:dispatch >/dev/null 2>&1; then
        pass "outbox entregada con: ${COMPOSE_CMD} exec api php artisan events:dispatch"
    else
        fail "no se pudo ejecutar events:dispatch; la outbox sigue pendiente"
    fi
else
    fail "no hay forma de entregar la outbox: define DISPATCH_CMD o ten docker disponible"
fi

# Ahora se fuerza la entrega en el microservicio. Sin esto, los eventos
# llegarian a la cola pero ninguna notificacion se habria enviado todavia.
notifications POST '/api/v1/process' '{}'

if [ "$NOTIF_STATUS" = "200" ] && printf '%s' "$NOTIF_BODY" | grep -q '"processed"'; then
    pass "el microservicio proceso la cola: ${NOTIF_BODY}"
else
    fail "el microservicio no proceso la cola (HTTP ${NOTIF_STATUS}): ${NOTIF_BODY}"
fi

# Comparacion con el inventario previo: lo que se busca es un identificador NUEVO,
# no simplemente que la lista no este vacia.
AFTER_IDS=$(notification_ids)
AFTER_COUNT=$(printf '%s\n' "$AFTER_IDS" | grep -c . || true)
NEW_ID=''

for _id in $(printf '%s' "$AFTER_IDS" | tr '\n' ' '); do
    case " $BEFORE_IDS " in
        *" $_id "*) : ;;
        *) NEW_ID="$_id"; break ;;
    esac
done

if [ -n "$NEW_ID" ]; then
    pass "llego una notificacion nueva (${NEW_ID}); el outbox alcanzo el microservicio"
else
    fail "no llego ninguna notificacion nueva: el outbox no se entrego o el microservicio no la proceso"
    fail "notificaciones en total: ${AFTER_COUNT} (antes de este recorrido: ${BEFORE_COUNT})"
fi

echo ""
if [ "$failures" -eq 0 ]; then
    echo "==> Recorrido completo sin fallos."
    exit 0
fi

echo "==> ${failures} paso(s) fallido(s)." >&2
exit 1
