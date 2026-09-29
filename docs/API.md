# API

Version `v1`. Prefijo `/api/v1`.

- **Base:** `http://localhost:8080` (gateway) o `http://localhost:8000` (directo)
- **Formato:** `application/json` en entrada y salida
- **Autenticacion:** cabecera `X-Api-Key` en todos los endpoints salvo `health`
- **Documentacion interactiva:** `/docs` en FastAPI; el backend se documenta en
  este archivo

---

## Convenciones

Las de esta seccion aplican al **backend Laravel**. El microservicio de
notificaciones tiene su propia convencion, descrita al final del documento: usa
el mismo envoltorio de error pero devuelve el recurso directamente en las
respuestas correctas, porque su superficie es de lectura y no expone agregados
de negocio.

### Envoltura de exito

```json
{
  "success": true,
  "data": { },
  "meta": { "pagination": { } },
  "request_id": "0f2c1d3e8a7b4c2d"
}
```

`meta` solo aparece en listados paginados. `request_id` siempre esta presente.

### Trazabilidad

Toda respuesta lleva `X-Request-Id` en la cabecera y `request_id` en el cuerpo,
con el mismo valor. Si el cliente ya envía la cabecera (es lo que hace Nginx), se
reutiliza; si no, el backend genera uno.

El valor se filtra a `[A-Za-z0-9._-]` y 64 caracteres antes de usarse, porque
acaba en un log: aceptar saltos de línea desde una cabecera controlada por el
cliente permitiría falsificar líneas de registro.

### Envoltura de error

```json
{
  "success": false,
  "error": {
    "code": "doctor_busy",
    "detail": "El medico ya tiene una cita de 09:00 a 09:30 que se traslapa con el horario solicitado.",
    "status": 409,
    "context": {
      "doctor_id": 1,
      "conflicting_starts_at": "2025-01-08T14:00:00+00:00",
      "conflicting_ends_at": "2025-01-08T14:30:00+00:00"
    }
  },
  "request_id": "0f2c1d3e8a7b4c2d"
}
```

El `code` es estable y programable; el `detail` esta escrito para humanos y
puede cambiar. Un cliente debe decidir sobre el `code`, nunca sobre el texto.

Los conflictos de agenda no comparten un unico codigo: cada motivo tiene el
suyo (`doctor_busy`, `patient_busy`, `past_date`, `outside_hours`,
`doctor_inactive`, `patient_inactive`, `daily_limit`, `db_constraint`). Todos
responden 409, pero el codigo es lo que permite reaccionar a cada caso.

### Fechas

Todas en ISO 8601 con zona explicita, en UTC:

```
2025-01-08T13:00:00Z
2025-01-07T10:00:00+00:00
```

La hora de la jornada se evalua en `America/Bogota` (configurable). Una cita a
las 8:00 hora local es `13:00Z`.

### Paginacion

```
GET /api/v1/patients?page=2&per_page=20&sort_by=full_name&sort_direction=asc
```

```json
"meta": {
  "pagination": {
    "page": 2, "per_page": 20, "total": 42, "last_page": 3, "has_more": true
  }
}
```

`per_page` maximo: 100.

### Limite de tasa

Por IP, en el gateway y en el backend. Un exceso devuelve `429` con
`Retry-After`.

---

## Medicos

### `GET /api/v1/doctors`

Lista medicos. Publico: un paciente necesita ver la agenda antes de
identificarse.

**Parametros:** `page`, `per_page`, `specialty`, `is_active`, `sort_by`,
`sort_direction`

```bash
curl -H "X-Api-Key: dev-clinic-key-change-me" \
  "http://localhost:8080/api/v1/doctors?specialty=Cardiologia&per_page=5"
```

### `GET /api/v1/doctors/{id}`

```json
{
  "data": {
    "id": 1,
    "full_name": "Dra. Marta Gomez",
    "email": "marta.gomez@clinic.local",
    "license_number": "MED-12345",
    "specialty": "Cardiologia",
    "is_active": true,
    "working_hours": { "start": "08:00", "end": "18:00" },
    "working_days": ["monday", "tuesday", "wednesday", "thursday", "friday", "saturday"]
  }
}
```

El correo se devuelve **en claro**: la API es interna y ya exige credencial, y el
personal necesita el dato real para contactar al paciente. El enmascarado
(`m***@clinic.local`) se aplica donde el dato sale del sistema controlado: logs y
eventos de dominio.

### `GET /api/v1/doctors/{id}/availability?date=2025-01-08`

Huecos libres de un medico en una fecha. **La respuesta mas usada del sistema.**

```json
{
  "data": {
    "doctor_id": 1,
    "date": "2025-01-08",
    "timezone": "America/Bogota",
    "working_hours": { "start": "08:00", "end": "18:00" },
    "free_slots": [
      { "start_at": "2025-01-08T13:00:00Z", "end_at": "2025-01-08T13:30:00Z" }
    ],
    "occupied_slots": [
      { "start_at": "2025-01-08T15:00:00Z", "end_at": "2025-01-08T15:30:00Z" }
    ],
    "summary": {
      "total_slots": 20,
      "free_slots": 19,
      "occupied_slots": 1,
      "occupancy_percentage": 5
    }
  }
}
```

`403` si el medico esta inactivo, `422` si la fecha es invalida o ya paso.

---

## Pacientes

### `POST /api/v1/patients`

```bash
curl -X POST http://localhost:8080/api/v1/patients \
  -H "X-Api-Key: dev-clinic-key-change-me" \
  -H "Content-Type: application/json" \
  -d '{
    "full_name": "Ana Ruiz",
    "document_id": "CC1010101010",
    "email": "ana.ruiz@correo.com",
    "phone": "+573001234567",
    "birth_date": "1990-05-14",
    "gender": "F",
    "address": "Calle 45 # 12-30",
    "emergency_contact_name": "Luis Ruiz",
    "emergency_contact_phone": "+573009876543",
    "allergies": ["Penicilina", "Mariscos"]
  }'
```

**201** con el paciente creado.

`document_id` se normaliza antes de validar: `"cc-1010101010"` y
`"CC1010101010"` son el mismo paciente. Sin esa normalizacion, la validacion de
unicidad compararia `"cc-1010101010"` contra `"CC1010101010"`, no encontraria el
duplicado y dejaria insertar dos pacientes con el mismo documento.

### `GET /api/v1/patients`

| Parametro | Ejemplo | Efecto |
|---|---|---|
| `search` | `ana` | Nombre, documento o correo |
| `status` | `active` | `active` o `inactive` |
| `include_deleted` | `false` | `true` incluye los borrados logicamente |
| `sort_by` | `created_at` | Campo de orden |
| `sort_direction` | `desc` | `asc` o `desc` |

```bash
curl -H "X-Api-Key: dev-clinic-key-change-me" \
  "http://localhost:8080/api/v1/patients?search=ana&per_page=10"
```

### `GET /api/v1/patients/{id}`

`404` si no existe **o si esta borrado logicamente**. Un paciente dado de baja
debe ser indistinguible de uno que nunca existio para un cliente sin permiso
administrativo.

### `PATCH /api/v1/patients/{id}`

Actualizacion parcial. Solo se modifican los campos presentes en el cuerpo.

```json
{ "allergies": ["Latex"] }
```

Una lista `allergies` vacia **borra** todas las alergias. Omitir el campo las
conserva. Esa distincion importa clinicamente: un PATCH que no menciona alergias
no debe borrarlas por accidente.

`document_id` **no** es actualizable: el documento identifica a la persona, y
cambiarlo rompe la trazabilidad del historial clinico.

### `DELETE /api/v1/patients/{id}`

Borrado logico. **204**.

```json
{ "deleted_at": "2025-01-07T10:00:00Z", "reason": "Solicitud del titular" }
```

El registro se conserva, y con el, el historial de sus citas. El indice unico del
documento se libera, de modo que el documento puede reutilizarse.

---

## Citas

### `POST /api/v1/appointments`

```bash
curl -X POST http://localhost:8080/api/v1/appointments \
  -H "X-Api-Key: dev-clinic-key-change-me" \
  -H "Content-Type: application/json" \
  -d '{
    "patient_id": 1,
    "doctor_id": 1,
    "start_at": "2025-01-08T13:00:00Z",
    "end_at": "2025-01-08T13:30:00Z",
    "reason": "Control de tension arterial"
  }'
```

**201**:

```json
{
  "data": {
    "id": 812,
    "patient_id": 1,
    "doctor_id": 1,
    "status": "scheduled",
    "start_at": "2025-01-08T13:00:00Z",
    "end_at": "2025-01-08T13:30:00Z",
    "duration_minutes": 30,
    "reason": "Control de tension arterial",
    "notes": null,
    "created_at": "2025-01-07T10:00:00Z"
  }
}
```

#### Errores de reserva

Los conflictos de disponibilidad son **todos 409**, pero cada motivo tiene su
propio `code`. Esa distincion es la que permite al cliente reaccionar distinto a
"el medico esta ocupado" (ofrecer otro horario) que a "la cita esta en el
pasado" (corregir la fecha) o "el medico no atiende ese dia" (no insistir).

| `code` | HTTP | Cuando |
|---|---|---|
| `doctor_busy` | 409 | Traslape con otra cita del medico |
| `patient_busy` | 409 | Traslape con otra cita del paciente |
| `past_date` | 409 | La cita empieza antes de ahora |
| `outside_hours` | 409 | Fuera de la jornada, en un dia que no atiende, o la cita cruza la medianoche local |
| `daily_limit` | 409 | El medico alcanzo el maximo de citas del dia |
| `db_constraint` | 409 | PostgreSQL veto la reserva (`23P01`): dos peticiones simultaneas; indica conflicto, no error |
| `doctor_inactive` | 409 | El medico esta inactivo |
| `patient_inactive` | 409 | El paciente esta inactivo o borrado |
| `time_range.end_before_start` | 422 | `end_at <= start_at` |
| `time_range.duration_out_of_range` | 422 | Duracion fuera de los limites permitidos |
| `time_range.outside_working_hours` | 422 | El intervalo como dato no cabe en la jornada |
| `appointment.invalid_status_transition` | 422 | La transicion de estado no esta permitida |

Un 409 no significa "reintentalo igual": en `doctor_busy` y `db_constraint` el
reintento con el mismo horario volvera a fallar. Solo tiene sentido reintentar
tras cambiar el horario.

Ejemplo de conflicto:

```json
{
  "success": false,
  "error": {
    "code": "doctor_busy",
    "detail": "El medico \"Dra. Marta Gomez\" ya tiene una cita de 09:00 a 09:30 que se traslapa con el horario solicitado.",
    "status": 409,
    "context": {
      "doctor_id": 1,
      "conflicting_starts_at": "2025-01-08T14:00:00+00:00",
      "conflicting_ends_at": "2025-01-08T14:30:00+00:00"
    }
  },
  "request_id": "0f2c1d3e8a7b4c2d"
}
```

El propio `code` distingue `doctor_busy` de `patient_busy`: el mensaje cambia y
la correccion que necesita el usuario tambien. Por eso el ejemplo anterior
devuelve el motivo en `code` y no anidado en `context`.

### `GET /api/v1/appointments`

| Parametro | Efecto |
|---|---|
| `patient_id` | Citas de un paciente |
| `doctor_id` | Citas de un medico |
| `status` | `scheduled`, `confirmed`, `in_progress`, `completed`, `cancelled` |
| `from` / `to` | Rango de fechas, inclusive |
| `per_page` | Maximo 100 |

### `GET /api/v1/appointments/{id}`

Detalle completo, con los datos del paciente y del medico. `404` si no existe.

### `PATCH /api/v1/appointments/{id}/status`

```bash
curl -X PATCH http://localhost:8080/api/v1/appointments/812/status \
  -H "X-Api-Key: dev-clinic-key-change-me" \
  -H "Content-Type: application/json" \
  -d '{ "status": "confirmed" }'
```

Maquina de estados:

```
  scheduled ──confirm──> confirmed ──start──> in_progress ──complete──> completed
      │                      │                      │
      └──────────────────────┴──────────────────────┴──────cancel──> cancelled
```

Reglas: una cita cancelada no se reactiva (habria que crear otra); una completada
no se cancela (el evento klinico ocurrio); una en curso no vuelve a `confirmed`.

### `POST /api/v1/appointments/{id}/reschedule`

```json
{
  "start_at": "2025-01-09T15:00:00Z",
  "end_at": "2025-01-09T15:30:00Z",
  "reason": "El paciente no pudo asistir"
}
```

La reprogramacion **excluye la cita original** de la comprobacion de traslape.
Sin esa excepcion, reprogramar sobre el mismo horario fallaria contra si misma, y
un paciente que cancela y vuelve a agendar la misma hora no podria.

Todas las reglas de una reserva nueva se vuelven a aplicar: jornada, pasado,
limite diario. `200` con la cita actualizada.

---

## Microservicio de notificaciones

Base: `http://localhost:8001` o `http://localhost:8080/notifications-service`.
Documentacion interactiva: `/docs`.

### Convenciones propias

A diferencia del backend, este servicio:

- devuelve el recurso **directamente** en las respuestas correctas, sin
  envoltorio `success`/`data`, porque su superficie es de consulta y no expone
  agregados de negocio que necesiten anidarse bajo una clave;
- usa el **mismo envoltorio de error**: `{"error": {"code", "detail", "status",
  "context"}}`, para que un cliente pueda reutilizar el manejo de errores;
- devuelve `X-Request-Id` en la cabecera, reutilizando el que le llega del
  backend.

### `GET /api/v1/health`

Publico. No consulta dependencias. Liveness.

### `GET /api/v1/ready`

Publico. Verifica la base de datos e incluye el estado de la cola.

```json
{
  "status": "ok",
  "service": "notification-service",
  "version": "1.0.0",
  "environment": "local",
  "checks": { "database": "ok" },
  "notifications": { "pending": 3, "sent": 41, "failed": 0, "dead": 0 }
}
```

`503` si la base no responde. Publico **a proposito**: un orquestador necesita
consultarlo sin credencial. Por eso solo devuelve conteos agregados, nunca
identificadores ni contenido.

### `POST /api/v1/events`

Requiere `Authorization: Bearer <API_KEY>`. Contrato en
[DESACOPLAMIMIENTO.md](DESACOPLAMIMIENTO.md#6-contrato-entre-los-dos-servicios).

| Respuesta | Cuando |
|---|---|
| `201` | Evento nuevo |
| `200` | Duplicado (idempotencia) |
| `409` | Carrera entre entregas simultaneas |
| `422` | Evento mal formado |

### `GET /api/v1/notifications`

Requiere credencial. Filtros: `status`, `limit` (maximo 200).

```json
[{
  "id": "3a7f1c2e-...",
  "event_id": "6f1d2c3a-...",
  "event_type": "appointment.created",
  "aggregate_type": "appointment",
  "aggregate_id": 812,
  "channel": "log",
  "status": "sent",
  "attempts": 1,
  "last_error": null,
  "created_at": "2025-01-07T10:00:00+00:00",
  "updated_at": "2025-01-07T10:00:01+00:00",
  "sent_at": "2025-01-07T10:00:01+00:00",
  "next_attempt_at": null
}]
```

### `GET /api/v1/notifications/{id}`

`200`, `404` si no existe, `400` si el UUID es invalido.

### `POST /api/v1/process`

Requiere credencial. Fuerza una pasada del procesador.

```json
{ "processed": 3, "sent": 3, "retry_scheduled": 0, "exhausted": 0, "skipped": 0 }
```

---

## Catalogo de errores

Los codigos reales que emite la API. Se listan agrupados por familia porque el
`code` lo decide la excepcion de dominio que lanza la regla que fallo.

### Autenticacion y autorizacion

| `code` | HTTP | Cuando |
|---|---|---|
| `authentication.api_key_missing` | 401 | No se envio `X-Api-Key` |
| `authentication.api_key_invalid` | 401 | La credencial no coincide |
| `authentication.required` | 401 | Sin autenticar |
| `authorization.forbidden` | 403 | Autenticado pero sin permiso |
| `rate_limit.exceeded` | 429 | Se supero el limite del endpoint; ver `Retry-After` |

Los errores de credencial distinguen "falta" de "incorrecta" a proposito: son
fallos de operacion distintos. En los registros nunca se escribe la clave, ni la
correcta ni la incorrecta.

### Validacion de forma

| `code` | HTTP | Cuando |
|---|---|---|
| `validation.failed` | 422 | Form Request; detalle por campo en `error.context.errors` |

### Validacion de significado (value objects)

| `code` | HTTP | Cuando |
|---|---|---|
| `patient.invalid_document_id` | 422 | Documento con formato invalido |
| `shared.invalid_email` | 422 | Correo con formato invalido |
| `shared.invalid_phone` | 422 | Telefono con formato invalido |
| `doctor.invalid_license_number` | 422 | Matricula con formato invalido |
| `time_range.end_before_start` | 422 | `end_at <= start_at` |
| `time_range.duration_out_of_range` | 422 | Duracion fuera de los limites |
| `time_range.outside_working_hours` | 422 | El intervalo no cabe en la jornada |
| `appointment.invalid_status_transition` | 422 | Transicion de estado no permitida |

Estos son 422 y no 500 precisamente porque llegan de la peticion del cliente. Es
la distincion que evita que un dato malo aparezca en el panel de errores como si
fuera una caida del sistema.

### No encontrado

| `code` | HTTP | Cuando |
|---|---|---|
| `patient.not_found` | 404 | El paciente no existe, o esta borrado logicamente |
| `doctor.not_found` | 404 | El medico no existe |
| `appointment.not_found` | 404 | La cita no existe |
| `route.not_found` | 404 | La ruta no existe |

Un recurso borrado logicamente responde 404, no 410: el registro sigue
existiendo y una futura restauracion lo haria accesible otra vez.

### Duplicados

| `code` | HTTP | Cuando |
|---|---|---|
| `patient.duplicate_document_id` | 409 | Documento ya registrado |
| `patient.duplicate_email` | 409 | Correo ya registrado |
| `doctor.duplicate_license_number` | 409 | Matricula ya registrada |
| `doctor.duplicate_email` | 409 | Correo ya registrado |

### Conflictos de agenda

Los ocho de la tabla de "Errores de reserva", todos 409: `doctor_busy`,
`patient_busy`, `past_date`, `outside_hours`, `daily_limit`, `db_constraint`,
`doctor_inactive`, `patient_inactive`.

### Sin clasificar

| `code` | HTTP | Cuando |
|---|---|---|
| `http.<status>` | el que corresponda | Error HTTP de Symfony sin tratamiento especifico |
| `server.unexpected_error` | 500 | Fallo no previsto; se registra con traza |

Todos los errores 500 devuelven un mensaje generico y el `request_id`. El detalle
va al log del servidor: devolver la excepcion al cliente filtraria rutas,
credenciales o SQL.

### Microservicio de notificaciones

Comparten la forma anterior, pero el prefijo y el origen son distintos.

| `code` | HTTP | Cuando |
|---|---|---|
| `auth.key_missing` | 401 | No se envio `Authorization: Bearer` |
| `auth.invalid_key` | 401 | La credencial no coincide |
| `validation.failed` | 422 | El cuerpo no cumple el esquema; detalle en `error.context.errors` |
| `events.invalid_payload` | 422 | El evento no cumple el contrato de dominio |
| `events.duplicate` | 409 | Entrega concurrente del mismo `event_id` |
| `notifications.not_found` | 404 | La notificacion no existe |
| `notifications.invalid_id` | 400 | El identificador no tiene formato valido |
| `notifications.invalid_status` | 422 | El filtro `status` no es un valor del dominio |
| `http.error` | el que corresponda | `HTTPException` sin codigo propio |

`events.duplicate` no es un fallo para el productor: la outbox de Laravel lo
registra y reintenta, y el reintento recibe `200`. Por eso devuelve 409 y no
200, para que un cliente que trate de distinguir "nuevo" de "ya conocido" pueda
hacerlo, aunque la operacion haya quedado garantizada por el indice unico.
