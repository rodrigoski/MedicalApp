# ClinicApp

Sistema de gestion de citas medicas para una clinica pequena, construido como
aplicacion monolito modular en Laravel 12 (PHP 8.3) con un microservicio de
notificaciones en FastAPI (Python 3.12), PostgreSQL 16 como almacen unico y
Nginx como gateway.

El problema de negocio que resuelve es deliberadamente concreto: **garantizar
que un medico nunca tenga dos citas traslapadas**, y que esa garantia se sostenga
aun con peticiones simultaneas.

---

## Indice

1. [Arranque rapido](#arranque-rapido)
2. [Arquitectura](#arquitectura)
3. [Reglas de negocio](#reglas-de-negocio)
4. [API](#api)
5. [Microservicio de notificaciones](#microservicio-de-notificaciones)
6. [Pruebas](#pruebas)
7. [Comandos utiles](#comandos-utiles)
8. [Documentacion](#documentacion)
9. [Limitaciones conocidas](#limitaciones-conocidas)

---

## Arranque rapido

Requisitos: Docker con Compose v2. No hace falta instalar PHP, Composer, Python
ni PostgreSQL en el equipo.

```bash
# 1. Configuracion (crea .env si no existe)
make setup

# 2. Levantar todo (construye imagenes, migra y siembra datos de demostracion)
make up

# 3. Verificar que todo responde
make health
```

| Servicio | URL | Descripcion |
|---|---|---|
| Gateway | <http://localhost:8080> | Punto de entrada; aplica limite de tasa |
| API Laravel | <http://localhost:8000> | API REST v1 |
| Documentacion FastAPI | <http://localhost:8001/docs> | OpenAPI interactivo |
| Notificaciones (via gateway) | <http://localhost:8080/notifications-service> | Mismo servicio, enrutado |
| PostgreSQL | `localhost:5432` | `clinic_app` y `clinic_notifications` |

Credenciales de demostracion (definidas en `backend/.env.example`):

```
X-Api-Key: dev-clinic-key-change-me
```

Prueba de que la regla central se cumple en el sistema desplegado:

```bash
make smoke
```

Para detener todo: `make down`. Para empezar de cero, incluidos los datos:
`make destroy && make up`.

---

## Arquitectura

### Monolito modular con capas, no microservicios por capa

El backend es un **unico despliegue** organizado en cuatro capas dentro de cada
modulo, con dependencias que apuntan siempre hacia adentro:

```
HTTP          routes -> Form Request -> Controller -> Resource
                                    |
Aplicacion    Servicio (caso de uso) -> DTO -> Contrato (interfaz)
                                    |
Dominio       Entidad -> Value Object -> Politica -> Excepcion
                                    ^
Infraestructura   Eloquent | Repositorio | Cliente HTTP | Reloj
```

La regla de dependencia es verificada por codigo, no por convencion:
`php artisan architecture:check` falla si el dominio importa Laravel, si la
capa HTTP escribe en un repositorio o si una entidad depende de un DTO.

Se eligio un monolito modular y no microservicios para los datos de clinica
porque la transicion de las entidades (medico, paciente, cita) es una
INVARIANTE: el "no traslape" tiene que garantizarse dentro de la MISMA
transaccion de base de datos. Partirlo entre servicios obligaria a introducir
distribucion transaccional, y el sistema pasaria de "nadie puede solaparse" a
"nadie puede solaparse, salvo una carrera improbable".

### El microservicio si aporta valor

La notificacion de una cita es, en cambio, el caso opposite: no participa en la
invariante, tolera retardo, debe sobrevivir a la caida del receptor y no
justifica un modelo transaccional compartido. Por eso vive aparte, en Python, y
se comunica por el patron **outbox**.

```
POST /api/v1/appointments
        |
        v
   [ PostgreSQL ]  <-- cita + evento, misma transaccion
        |
        |  events:dispatch (cola)
        v
  [ FastAPI ]  -- POST /api/v1/events -->  notification (idempotente)
        |
        v
   canal: log | webhook
```

Detalhes en [docs/DESACOPLAMIENTO.md](docs/DESACOPLAMIENTO.md).

### Tiempo y zonas horarias

La distincion que suele generar bugs clinicos, y que aqui es explicita:

- **Todo instante se guarda en UTC** y se transmite en ISO 8601 con `Z`.
- **Las reglas de jornada se evaluan en hora local de clinia**
  (`America/Bogota` por defecto, configurable).

Una cita que el paciente ve como "martes 9:00" se persiste como `14:00Z`. El
error tipico -- comparar la hora de llegada de una peticion con una jornada
definida en UTC -- produciria citas validas a las 3 de la manana.

---

## Reglas de negocio

| Regla | Donde vive | Como se garantiza |
|---|---|---|
| No hay dos citas traslapadas para un medico | `AppointmentOverlapPolicy` | Politica de dominio + `EXCLUDE USING gist` en PostgreSQL |
| No hay dos citas traslapadas para un paciente | `AppointmentOverlapPolicy` | Politica + restriccion de exclusion por paciente |
| Solo en horario laboral y en dias habiles | `AppointmentOverlapPolicy` | Semana en `Doctor::worksOn` (domingo excluido) |
| Maximo de citas por medico y dia | `AppointmentOverlapPolicy` | Conteo en ventana local convertida a UTC |
| No se agenda en el pasado | `AppointmentOverlapPolicy` | Comparacion contra `ClockInterface` |
| Transiciones de estado validas | `Appointment` | Maquina de estados en la entidad |
| Documento unico entre pacientes activos | `PatientService` | Comprobacion en aplicacion + indice unico parcial |
| Borrado logico, nunca fisico | `PatientService` | `deleted_at`; el registro se conserva para auditoria |

Los intervalos son **semiabiertos** (`[inicio, fin)`): una cita que termina a las
10:00 y otra que empieza a las 10:00 NO se traslapan. Es lo que permite agendar
una jornada completa sin huecos falsos.

La garantia de no-traslape esta en dos niveles, y ambos son necesarios:

1. **Politica de dominio**: da el mensaje de error util ("el medico esta ocupado
   de 14:00 a 14:30") y evita el viaje de ida y vuelta en el caso normal.
2. **Restriccion de base de datos**: es la unica que sobrevive a dos peticiones
   simultaneas. Dos peticiones que pasan la politica a la vez no pueden ambas
   llegar a `INSERT`: una viola la restriccion y recibe `23P01`, que el
   repositorio traduce al mismo error de conflicto.

Sin el segundo nivel, dos clics simultaneos sobre el mismo hueco reservan dos
citas. Ese es exactamente el fallo que el sistema tiene que impedir.

---

## API

Prefijo `/api/v1`. Toda la superficie, salvo `/api/v1/health`, exige la cabecera
`X-Api-Key`.

| Metodo | Ruta | Descripcion |
|---|---|---|
| GET | `/health` | Liveness, publico y sin credencial |
| GET | `/stats` | Conteo de citas por estado (requiere credencial) |
| GET/POST | `/doctors` | Listar y crear medicos |
| GET | `/doctors/{id}` | Detalle de un medico |
| PATCH/DELETE | `/doctors/{id}` | Actualizar y baja logica |
| GET | `/doctors/{id}/availability?date=` | Huecos libres de una fecha |
| GET/POST | `/patients` | Listar (paginado) y crear pacientes |
| GET/PATCH/DELETE | `/patients/{id}` | Consultar, actualizar, borrado logico |
| GET/POST | `/appointments` | Listar y reservar citas |
| GET | `/appointments/{id}` | Detalle de una cita |
| PATCH | `/appointments/{id}/status` | Confirmar, iniciar, completar o cancelar |
| POST | `/appointments/{id}/reschedule` | Reprogramar |
| DELETE | `/appointments/{id}` | Cancelar (borrado logico) |

El microservicio de notificaciones expone su propia superficie, con los mismos
formatos y autenticacion por cabecera: `GET /health`, `GET /ready`,
`POST /events`, `GET /notifications`, `GET /notifications/{id}` y
`POST /process`. La tabla anterior es la del backend.

Formato de respuesta, comun a exito y error:

```jsonc
// Exito
{
  "success": true,
  "data": { "id": 1, "full_name": "Ana Ruiz" },
  "meta": { "pagination": { "page": 1, "per_page": 15, "total": 42 } },
  "request_id": "0f2c1a4b7d9e0355"
}
// Error
{
  "success": false,
  "error": {
    "code": "doctor_busy",
    "detail": "El medico ya tiene una cita que se traslapa con el horario solicitado.",
    "status": 409,
    "context": {
      "doctor_id": 1,
      "conflicting_starts_at": "2025-01-08T14:00:00+00:00",
      "conflicting_ends_at": "2025-01-08T14:30:00+00:00"
    }
  },
  "request_id": "0f2c1a4b7d9e0355"
}
```

`error.code` es estable y programable (`doctor_busy`, `patient_busy`,
`past_date`, `outside_hours`, `patient_inactive`, `doctor_inactive`,
`daily_limit`, `db_constraint`, `validation.failed`, `rate_limit.exceeded`,
`patient.not_found`, `route.not_found`, `server.unexpected_error`, …).
`error.detail` es para mostrar a la persona y puede redactarse sin romper a un
cliente. El catalogo completo esta en [docs/API.md](docs/API.md#catalogo-de-errores).

El `request_id` viaja en la respuesta y en la cabecera `X-Request-Id`, y atraviesa
tambien el gateway y el microservicio. Un solo identificador permite reconstruir
la historia completa de una peticion en los logs de los tres procesos.

Referencia completa, incluido el catalogo de errores, en
[docs/API.md](docs/API.md).

---

## Microservicio de notificaciones

Estructura por capas, replicando el criterio del backend:

```
app/domain          entidad Notification, politica de reintentos, value objects
app/application     ReceiveEventService, DeliverPendingService, puertos (Protocol)
app/infrastructure  SQLAlchemy, repositorio, canales (log, webhook), reloj
app/api             esquemas, dependencias, rutas
app/config.py       configuracion por entorno
```

Dos garantias:

**Idempotencia.** La outbox de Laravel entrega "al menos una vez": si se pierde
la respuesta, reintenta. `notifications.event_id` tiene indice `UNIQUE`, de modo
que un evento reenviado devuelve `200` con la notificacion existente y nunca
crea un segundo aviso al paciente. La comparacion previa en el caso de uso
optimiza; la restriccion del motor resuelve la carrera.

**Reintentos con backoff.** Un fallo del canal no propaga la excepcion: la
notificacion pasa a `failed` y se reprograma con retardo exponencial (30 s, 60 s,
120 s…) hasta un tope. Al agotar los intentos pasa a `dead` para revision manual,
en lugar de reintentarse indefinidamente.

`GET /api/v1/ready` expone el conteo por estado. Si una cola crece sin parar,
ahí esta la primera pista.

---

## Pruebas

```bash
make test          # backend + microservicio
make test-unit     # solo unitarias de Laravel
make test-coverage # con umbral minimo de 80 %
make arch          # reglas de arquitectura
```

Cobertura de los escenarios que importan en un sistema de citas:

| Escenario | Donde |
|---|---|
| Traslape en borde compartido (10:00) | `TimeRangeTest` |
| Traslape de medico y de paciente | `AppointmentOverlapPolicyTest` |
| Fuera de jornada y en domingo | `AppointmentOverlapPolicyTest` |
| Cita en el pasado | `AppointmentOverlapPolicyTest` |
| Limite diario por medico | `AppointmentApiTest` |
| Transiciones de estado invalidas | `AppointmentStateMachineTest` |
| Documento duplicado | `PatientApiTest` |
| Borrado logico e idempotencia del seed | `DemoDataSeederTest` |
| Sin credencial, clave incorrecta, cabeceras | `ApiSecurityTest` |
| Idempotencia del evento | `test_api.py`, `test_services.py` |
| Backoff y estado `dead` | `test_services.py` |
| Liveness vs readiness | `test_api.py` |

Las pruebas del backend usan SQLite; las del microservicio, SQLite en memoria
con `StaticPool`. Las reglas que dependen del motor -- la restriccion de
exclusion y la carrera entre dos inserciones -- **no** se pueden comprobar en
SQLite: estan cubiertas por `make smoke` contra PostgreSQL, y por
`scripts/smoke-test.sh`, que reserva dos veces el mismo hueco y exige un 409.

---

## Comandos utiles

`make help` lista todos. Los mas usados:

```bash
make up            # levantar todo
make health        # comprobar contenedores, base, API, FastAPI y gateway
make logs          # seguir los logs
make shell         # consola de PHP (tinker)
make psql          # consola SQL
make fresh         # recrear base con datos de demostracion
make events-dispatch   # entregar la outbox al microservicio
make smoke         # recorrido funcional completo
make destroy       # borrar todo, incluidos los datos
```

---

## Documentacion

| Documento | Contenido |
|---|---|
| [docs/ARQUITECTURA.md](docs/ARQUITECTURA.md) | Capas, modulos, flujo de una peticion, decisiones y alternativas descartadas |
| [docs/AUDITORIA.md](docs/AUDITORIA.md) | Trazabilidad, seguridad, auditoria, rendimiento y despliegue |
| [docs/API.md](docs/API.md) | Referencia de endpoints, codigos de error y ejemplos |
| [docs/DESACOPLAMIENTO.md](docs/DESACOPLAMIENTO.md) | Por que el patron outbox, idempotencia y reintentos |
| [docs/POSTMORTEM.md](docs/POSTMORTEM.md) | Errores encontrados durante el desarrollo y como se corrigieron |
| [docs/ENTREGABLES.md](docs/ENTREGABLES.md) | Inventario de lo entregado y estado de cada punto |

---

## Limitaciones conocidas

Se documentan en lugar de esconderse:

1. **Sin HTTPS.** Nginx sirve en HTTP. En produccion hace falta un proxy TLS
   delante; las cabeceras `Strict-Transport-Security` ya se emiten.
2. **Autenticacion por clave compartida.** No hay usuarios, roles ni Refresh
   tokens. Suficiente para un servicio interno entre dos despliegues,
   insuficiente para exponerlo a Internet.
3. **Canal de notificacion simulado.** El canal por defecto escribe en el log.
   La pasarela de SMS real se anade implementando `ChannelSender`; las reglas no
   cambian.
4. **Una instancia del backend.** La logica de negocio admite varias, pero la
   reserva de citas no se ha probado bajo concurrencia real de varias instancias.
5. **Los tokens de Git, PHP y Docker no estan instalados en el entorno de
   desarrollo usado**, por lo que la suite se ha revisado de forma estatica pero
   no ejecutado. Ver [docs/POSTMORTEM.md](docs/POSTMORTEM.md).
