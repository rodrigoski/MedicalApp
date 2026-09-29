# Arquitectura

## 1. Vista general

```
                       +-------------------------+
                       |   Cliente (web, movil)   |
                       +------------+------------+
                                    |
                                    v
                       +-------------------------+
                       |   Gateway (Nginx)       |
                       |  - limite de tasa       |
                       |  - cabeceras defensivas |
                       |  - X-Request-Id         |
                       +----+-------------+-------+
                            |             |
              /api/...      |             |  /notifications-service/...
                            v             v
        +----------------------------+  +----------------------------+
        |  API  (Laravel 12 / PHP)   |  |  Notificaciones (FastAPI)  |
        |                            |  |                            |
        |  Http -> Application ->    |  |  api -> application ->     |
        |  Domain <- Infrastructure  |  |  domain <- infrastructure |
        +--------------+-------------+  +-------------+--------------+
                       |                                |
                       v                                v
        +----------------------------+     +---------------------------+
        |  PostgreSQL 16             |     |  PostgreSQL 16            |
        |  clinic_app                |     |  clinic_notifications     |
        |  - citas (exclusion gist)  |     |  - notifications          |
        |  - outbox (domain_events)  |     |    (event_id UNIQUE)      |
        +----------------------------+     +---------------------------+
```

Dos bases de datos en una sola instancia de PostgreSQL. El aislamiento es
logico, no fisico: para una demostracion es suficiente y evita un segundo
contenedor, pero los datos de los dos servicios nunca se mezclan en un esquema,
y una migracion de uno no puede romper el otro.

---

## 2. Por que monolito modular y no microservicios

La pregunta "monolito o microservicios" tiene respuesta distinta segun el
problema. Aqui se separo por criterio, no por moda.

**Lo que NO se separo: la reserva de citas.** Al crear una cita se tocan
medico, paciente, agenda y disponibilidad, y el resultado debe ser coherente en
una sola transaccion. La invariante "no hay dos citas traslapadas" solo se puede
garantizar si la comprobacion y la escritura ocurren en el mismo almacen, en la
misma transaccion. Si la comprobacion estuviera en un servicio de agenda y la
escritura en otro, habria que aceptar una ventana de carrera o introducir
distribucion transaccional (dos fases, compensaciones) para un problema que la
base de datos resuelve con una restriccion.

**Lo que SI se separo: las notificaciones.** Un aviso de cita no participa en la
invariante, tolera retardo, debe funcionar con el receptor caido, no necesita
consistencia inmediata y su carga no es comparable a la de las reservas. Aislarlo
permite elegir tecnologia adecuada (Python), escalar de forma independiente y, sobre
todo, **fallar sin afectar al sistema principal**: si el microservicio esta caido,
la cita se reserva igual y el aviso sale mas tarde.

El criterio aplicado: *separar cuando la frontera coincide con un punto de
cambio real de tecnologia o de carga, no cuando simplemente "hay dos cosas que
hacer"*.

---

## 3. Capas del backend

### Dominio
Entidades (`Appointment`, `Doctor`, `Patient`), value objects (`TimeRange`,
`Email`, `PhoneNumber`, `DocumentId`, `LicenseNumber`), enumeraciones, la politica
`AppointmentOverlapPolicy` y las excepciones de negocio.

No importa nada de Laravel, Eloquent ni HTTP. Es la unica parte del sistema que
no cambia si la base de datos o el framework cambian. Reglas como "el medico
trabaja de 8 a 18, de lunes a sabado, y no puede tener dos citas solapadas" estan
aqui, no en un controlador ni en una migracion.

### Aplicacion
Casos de uso: `AppointmentService`, `PatientService`, `DoctorService`,
`AvailabilityService`, `EventPublisher`. Cada uno es una clase con un metodo por
operacion de negocio, DTOs de entrada (`BookAppointmentData`) y
`readonly class` de salida.

Coordina: carga el agregado, delega el comportamiento en la entidad, persiste a
traves de un contrato y registra el evento en la outbox. Aplica las reglas que
dependen de OTROS datos (unicidad de documento), que la entidad no puede conocer
por si sola.

### Infraestructura
Modelos Eloquent, mappers, repositorios, cliente HTTP del microservicio,
implementaciones de reloj, seeders, comandos de consola y el verificador de
arquitectura.

### HTTP
Rutas, middlewares, Form Requests, Controllers, Resources y el mapeador de
excepciones a respuestas HTTP.

---

## 4. Regla de dependencia

```
Http  ->  Application  ->  Domain
             ^
             |
   Infrastructure
```

Las flechas apuntan hacia adentro. El dominio no sabe que existe una base de
datos; la aplicacion no sabe si el repositorio es Eloquent, una API o un
archivo. Esta regla no se deja a la buena fe: `App\Support\ArchitectureRules` la
comprueba y `php artisan architecture:check` falla si:

- un archivo de `Domain` importa `Illuminate\*`;
- `Http` escribe directamente en un repositorio en vez de pasar por un servicio;
- un DTO de `Application` importa un modelo de `Infrastructure`;
- una entidad depende de `Illuminate\Database\Eloquent\Model`.

Que la regla sea ejecutable importa mas que que este escrita en un documento: una
regla de arquitectura que depende de la disciplina de cada persona se rompe en la
primera semana de presion por una fecha de entrega.

---

## 5. Flujo de una reserva de cita

```
POST /api/v1/appointments
  │
  ├─ SecurityHeadersMiddleware      cabeceras defensivas
  ├─ ForceJsonResponse               negocia JSON
  ├─ RequestLogger                   inicia traza con X-Request-Id
  ├─ AuthenticateApiKey              hash_equals; fail-closed
  ├─ throttle:appointments           limite por IP
  │
  ├─ StoreAppointmentRequest         Form Request: forma y reglas de entrada
  │     └─ normaliza documento/telefono ANTES de validar unicidad
  │
  ├─ AppointmentController::store
  │     └─ BookAppointmentData::fromArray($request->validated())
  │
  ├─ AppointmentService::book        caso de uso
  │     ├─ DoctorRepository::findById
  │     ├─ PatientRepository::findById        (activo y no borrado)
  │     ├─ TimeRange::forAppointment(...)      (duracion valida)
  │     ├─ AppointmentOverlapPolicy::assertCanBook
  │     │     ├─ no en el pasado                (ClockInterface)
  │     │     ├─ estado activo
  │     │     ├─ dentro de jornada y sin domingo
  │     │     ├─ sin traslape del medico        (repositorio)
  │     │     ├─ sin traslape del paciente      (repositorio)
  │     │     └─ limite diario (ventana local -> UTC)
  │     ├─ AppointmentRepository::save
  │     │     └─ INSERT ... 23P01 si hay carrera
  │     └─ DomainEventRepository::record        (misma transaccion: OUTBOX)
  │
  ├─ EventPublisher                  AFTER COMMIT: entrega al microservicio
  │
  ├─ AppointmentResource             forma de respuesta
  └─ 201 Created
```

Detalle que importa: el evento se registra en la **misma transaccion** que la
cita. Si la escritura de la cita falla, no queda ningun evento huerfano; si el
microservicio esta caido, el evento queda pendiente en la outbox y se entrega
despues.

---

## 6. Patron Outbox

Ver [DESACOPLAMIENTO.md](DESACOPLAMIENTO.md) para el detalle completo. En
resumen:

| Sin outbox | Con outbox |
|---|---|
| Se guarda la cita y se notifica. Si el HTTP falla, el aviso se pierde para siempre. | Se guardan cita y evento juntos. Un comando reintenta la entrega. |
| Publicar durante la transaccion obliga a mantener abierta una llamada de red dentro de una transaccion SQL. | La publicacion ocurre despues del commit, en un proceso aparte. |
| Si el evento se publica y la transaccion revierte, el aviso sale de algo que no existe. | Es imposible: si la transaccion revierte, no hay evento. |

---

## 7. Decisiones de persistencia

### Intervalos semiabiertos

`TimeRange` representa `[inicio, fin)`. Dos citas se traslapan si
`inicioA < finB AND finA > inicioB`. Con intervalos cerrados, una cita que
termina a las 10:00 y otra que empieza a las 10:00 se considerarian en
conflicto, y no se podria agendar una jornada continua. El intervalo semiabierto
es lo que hace que la agenda "rellene" sin huecos falsos.

### Restriccion de exclusion de PostgreSQL

```sql
EXCLUDE USING gist (
    doctor_id  WITH =,
    tstzrange(start_at, end_at, '[)') WITH &&
) WHERE (deleted_at IS NULL AND status IN ('scheduled','confirmed'))
```

Es el unico mecanismo que puede garantizar el no-traslape bajo concurrencia real.
La politica de dominio da el mensaje de error comprensible y evita el viaje de
ida y vuelta en el caso normal (el 99%); la restriccion cubre el 1% restante
(two clicks simultaneos) donde la politica no alcanza.

El `[)` en `tstzrange` replica exactamente la semántica de `TimeRange`. Si
divergen, aparece el bug mas dificil de detectar del sistema: una cita que la
API acepta y que la base de datos rechazaria, o al reves.

### Indice unico parcial de documento

```sql
CREATE UNIQUE INDEX patients_document_id_unique
    ON patients (document_id) WHERE deleted_at IS NULL;
```

Permite reutilizar el documento de un paciente dado de baja (el indice se
libera al hacer el borrado logico) sin permitir dos pacientes activos con el
mismo documento.

---

## 8. Manejo del tiempo

Seis mecanismos distintos, cada uno con su proposito:

| Mecanismo | Donde | Proposito |
|---|---|---|
| `ClockInterface` | dominio y aplicacion | Inyectar el tiempo; pruebas deterministas |
| `FrozenClock` | pruebas | Fecha fija, sin dependencia del reloj real |
| UTC en persistencia | `TimeRange`, migraciones | Un solo criterio de comparacion |
| `clinicTimezone` | `AppointmentOverlapPolicy` | Evaluar la jornada en hora local |
| `[from, to)` explicito | repositorio | Consultas de dia sin ambiguedad de bordes |
| `DateTimeZone` en `AvailabilityService` | disponibilidad | Huecos expresados en la zona que ve el usuario |

El error que este diseno evita: comparar la hora de llegada de una peticion
(UTC) contra una jornada definida en hora local. Con `America/Bogota` (UTC-5),
una cita "a las 8:00" son las 13:00 UTC; comparar 13:00 contra 8 devolveria un
error valido. Por eso `AvailabilityService` construye la ventana del dia en hora
local y la convierte a UTC antes de consultar o escribir.

---

## 9. Seguridad

| Capa | Mecanismo |
|---|---|
| Gateway | Limite de tasa por IP, limite de conexiones, `server_tokens off`, limites de tamano de cuerpo y de tiempo |
| Middleware | `X-Api-Key` comparada con `hash_equals` (tiempo constante), fail-closed |
| Formularios | Validacion con mensajes en espanol y detalle por campo |
| Dominio | Value objects que rechazan entradas invalidas en el constructor |
| Base de datos | Restricciones de exclusion, indices unicos, claves foraneas |
| Logs | Sin cuerpos de peticion, sin datos clinicos; el `request_id` es el enlace |
| Contenedores | Procesos sin privilegios; imagenes minimas; la clave de notificacion no se escribe nunca en log |

El detalle, con la trazabilidad exigible, esta en [AUDITORIA.md](AUDITORIA.md).

---

## 10. Alternativas descartadas

| Alternativa | Por que no |
|---|---|
| Reservar citas con columnas `start_at`/`end_at` y solo validacion en PHP | Con dos peticiones simultaneas, ambas pasan la validacion. Sin restriccion de exclusion, el traslape es inevitable. |
| Notificar desde la transaccion de la cita | Una llamada de red dentro de una transaccion SQL mantiene bloqueos abiertos; si el servicio responde lento, la base se bloquea. |
| Cola de mensajes (RabbitMQ, Kafka) para el evento | Un componente mas que operar para un volumen de cientos de eventos por dia. La outbox en PostgreSQL es la cola, y ya esta transaccional. |
| FastAPI asincrono con `asyncpg` | El volumen no lo justifica y complica las pruebas. Se eligio SQLAlchemy sincrono con endpoints `def`, que FastAPI ejecuta en un thread pool. |
| Tokens JWT y usuarios | Fuera del alcance. La clave compartida cubre el consumo entre dos despliegues internos. |
| Ranges de jornada por medico en base de datos | La jornada vigente de la clinica es un parametro operativo. La variación por medico (si se anadiera) se resuelve como excepcion en la politica, no con una tabla nueva. |
| Redis para cache y limites | El limite de tasa del gateway ya resuelve el caso. Anadir Redis es otro servicio que operar. |
