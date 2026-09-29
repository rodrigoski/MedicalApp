# Auditoria

Que preguntas tiene que poder responder quien audite este sistema. Cada seccion
responde a una, con la evidencia concreta y el mecanismo que la respalda.

---

## 1. Trazabilidad: puedo reconstruir una peticion completa?

### Mecanismo

Un identificador (`X-Request-Id`) se genera en el gateway o en el primer punto
que lo recibe, y se propaga sin cambios por las tres capas:

```
Nginx  --X-Request-Id-->  Laravel  --mismo id-->  FastAPI
   |                          |                      |
   v                          v                      v
access.log (json)        storage/logs          log con "extra"
```

En el backend, `RequestLogger` lo adjunta a cada linea de contexto. En el
microservicio, el middleware `request_context` lo anade a la respuesta y a cada
registro. En Nginx, `$request_id` va al log de acceso en formato JSON.

### Evidencia

```bash
# Un identificador concreto, en los tres procesos
docker compose logs api | grep "0f2c1d3e"
docker compose logs notification-service | grep "0f2c1d3e"
docker compose logs gateway | grep "0f2c1d3e"
```

El campo `request_id` viaja tambien dentro del cuerpo de la respuesta, de modo
que un cliente que reporta un error ("la reserva fallo con este codigo") entrega
directamente el identificador que hay que buscar.

### Lo que NO se registra, y por que

| No se registra | Motivo |
|---|---|
| Cuerpo de la peticion | Puede contener datos clinicos (motivo de consulta, alergias, notas). |
| Documento de identidad del paciente | Es el identificador mas sensible y el microservicio de notificaciones no lo necesita: viaja el `patient_id`, y desde el sistema de origen se recupera lo que haga falta. |
| Correo electronico en claro | Se registra `email` enmascarado (`a***@dominio`). |
| Claves de API | Ni la correcta ni la incorrecta. Tampoco su longitud: medirla no aporta nada al diagnostico y abre unvia lateral gratuita. |
| Payloads de evento completos | El identificador y el tipo bastan para trazar; el detalle se consulta en la base. |

Un log de aplicacion que registre cuerpos de peticion es un segundo almacen de
datos clinicos sin cifrado, sin control de acceso y con retencion indefinida.

---

## 2. Trazabilidad: puedo reconstruir el historial de una cita?

Si. Cada cambio de estado de una cita es un evento inmutable en la tabla
`domain_events`:

| Campo | Contenido |
|---|---|
| `id` | UUID v4 |
| `type` | `appointment.created`, `.confirmed`, `.rescheduled`, `.cancelled`, `.completed` |
| `aggregate_type` / `aggregate_id` | `appointment` + id |
| `payload` | Campos modificados, sin datos clinicos sensibles |
| `occurred_at` | Instante del cambio (UTC) |
| `dispatched_at` | Cuando se entrego al microservicio (o `NULL`) |
| `attempts` / `last_error` | Intentos de entrega y ultimo fallo |

Las citas, ademas, conservan `cancelled_at`, `confirmed_at`, `started_at` y
`completed_at`. Un borrado de paciente es logico: la fila y su historial
permanecen.

```sql
-- Historial completo de una cita
SELECT type, payload, occurred_at, dispatched_at
FROM domain_events
WHERE aggregate_type = 'appointment' AND aggregate_id = 812
ORDER BY occurred_at;
```

---

## 3. Integridad: que garantiza el sistema y como lo prueba?

### El invariante central

> Ningun medico tiene dos citas traslapadas. Ningun paciente esta en dos sitios
> a la vez.

Garantizado en dos niveles:

1. **`AppointmentOverlapPolicy`** (dominio): comprueba jornada, domingo, pasado,
   traslape de medico, traslape de paciente y limite diario.
2. **`EXCLUDE USING gist`** (PostgreSQL): ninguna fila puede violarla, sea cual
   sea la ruta de escritura.

El segundo nivel existe porque el primero no es suficiente bajo concurrencia. Dos
peticiones que evaluan la politica en el mismo instante pasan ambas y luego ambas
insertan. Solo la restriccion cierra esa ventana. El repositorio traduce el
codigo `23P01` al error `db_constraint`, de modo que el cliente ve un 409
coherente en los dos caminos. El `code` es distinto del de la validacion en PHP a
proposito: distingue "la politica lo rechazo" de "otro proceso gano la carrera",
que es informacion distinta para quien diagnostica.

### Como se verifica

```bash
# 1. A nivel de codigo, sin desplegar nada
make test

# 2. En el sistema desplegado, con peticiones reales
make smoke
```

`scripts/smoke-test.sh` reserva un hueco, intenta reservar el MISMO hueco y exige
409. Si la garantia fallara, el smoke test falla.

### Otras integridad verificadas

| Invariante | Mecanismo |
|---|---|
| Documento unico entre pacientes activos | Indice unico parcial `WHERE deleted_at IS NULL` |
| Correo unico | Indice unico + comprobacion en `PatientService` |
| Cita pertenece a paciente y medico existentes | Claves foraneas `RESTRICT` |
| Estados validos | `CHECK (status IN (...))` |
| No hay citas con `end_at <= start_at` | `CHECK (end_at > start_at)` |
| Intentos de entrega no negativos | `CHECK (attempts >= 0)` |

---

## 4. Seguridad

### Superficie expuesta

| Endpoint | Proteccion |
|---|---|
| `GET /api/v1/health` | Publico. No expone version de framework ni estado de dependencias. |
| `GET /api/v1/ready` | Publico, pero **solo** devuelve conteos agregados por estado. Nunca identificadores ni payloads. |
| Resto de `/api/v1/*` | `X-Api-Key` obligatoria |
| `/api/v1/events` (FastAPI) | `Authorization: Bearer` obligatoria |
| `POST /api/v1/process` (FastAPI) | Misma credencial. Permite forzar reintentos. |

`ready` es publico a proposito: un orquestador necesita saber si el servicio
puede trabajar sin aprender una credencial. Por eso su contenido son
`{"database": "ok"}` y conteos, nada que identifique a un paciente.

### Comparacion de credenciales

```php
hash_equals($presented, $configured)   // Laravel
hmac.compare_digest(presented, clave)   // Python
```

Comparacion en tiempo constante. Con `==`, el operador de igualdad sale en el
primer caracter distinto, y midiendo tiempos de respuesta se puede reconstruir
la clave byte a byte. Es un ataque real, no teorico, y el remedio cuesta una
linea.

### Comportamiento ante fallo (fail-closed)

Si no hay clave configurada, el middleware **rechaza todas las peticiones** en
lugar de aceptarlas. Un despliegue mal configurado queda sin servicio, que es
visible y recoverable; uno que acepta todo en silencio y descubre el problema
cuando se audita, no.

### Defensas por capa

| Capa | Mecanismo |
|---|---|
| Gateway | Limite de tasa por IP, limite de conexiones, `server_tokens off`, cuerpo maximo 1 MB, tiempos de espera |
| Gateway | Cabeceras de navegador: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Strict-Transport-Security` (fuente unica; oculta con `proxy_hide_header` las que emite el upstream para no duplicarlas) |
| Aplicacion | Cabeceras equivalentes, `Cache-Control: no-store` en respuestas con datos clinicos, y generacion o reutilizacion del `X-Request-Id` |
| Validacion | Forma y tipo en el Form Request; significado en el value object |
| Base de datos | Permisos reducidos: el usuario de la aplicacion no es superusuario |
| Contenedores | Sin root, imagenes minimas, superficie de ataque acotada |

`Strict-Transport-Security` la emite solo el gateway porque es el unico que sabe
si hay un terminador TLS delante. Enviar HSTS desde la aplicacion seria peligroso
en un despliegue sin TLS: el navegador memorizaria que el host es seguro de forma
permanente y no podria volver a usarlo por HTTP.

### Datos sensibles

| Dato | Almacenamiento | API | Logs y eventos |
|---|---|---|---|
| Correo del paciente | En claro | En claro (API interna con credencial) | Enmascarado (`a***@dominio`) |
| Documento | Normalizado en mayusculas | En claro (identifica al paciente; requiere credencial) | No se registra |
| Alergias | En claro (dato critico clinico) | En claro, con credencial | No se registran |
| Motivo de consulta | En claro | En claro, con credencial | No se registra |

La distincion es deliberada: enmascarar las alergias volveria el dato inutil
clinicamente, y el acceso ya esta protegido por credencial. Lo que se protege es
el dato cuando sale del sistema controlado, es decir, en los logs y en los
eventos que viajan al microservicio.

---

## 5. Auditoria y retencion

| Dato | Retencion | Como se aplica |
|---|---|---|
| Citas | Permanente (borrado logico) | `deleted_at` |
| Pacientes | Permanente (borrado logico) | `deleted_at`; el documento queda libre para reutilizar |
| Eventos de dominio | 90 dias tras entregarse | `php artisan events:prune` |
| Notificaciones | Permanente | Permite reconstruir que aviso salio y cuando |
| Logs de aplicacion | Volumen Docker: 3 archivos de 10 MB | `max-size` / `max-file` en `docker-compose.yml` |

Los eventos se podan solo si `dispatched_at` no es nulo. Un evento no entregado
nunca se borra: podria contener la unica copia de un aviso que el paciente
necesita.

---

## 6. Rendimiento

### Indices

| Indice | Consulta que sirve |
|---|---|
| Restriccion de exclusion (gist) | Verificacion de traslape en cada intento de reserva |
| `appointments (doctor_id, start_at)` | Agenda del medico en un rango |
| `appointments (patient_id, start_at)` | Historial del paciente |
| `appointments_due_idx (status, next_attempt_at)` | Consulta de notificaciones vencidas |
| `notifications_due_idx (status, next_attempt_at)` | Lote del procesador |
| `patients_document_id_unique` (parcial) | Comprobacion de unicidad |
| `doctors (email)` unico | Comprobacion de unicidad |

### Consultas que se evitaron

| Consulta ingenua | Lo que se hace | Por que |
|---|---|---|
| Cargar todas las citas del dia y filtrar en PHP | `WHERE tstzrange && tstzrange(...)` | El filtro va en el indice, no en PHP |
| `COUNT(*)` de todo el historico para el limite diario | `COUNT(*) WHERE start_at >= :from AND start_at < :to` | Indice por rango en vez de recorrido completo |
| Traer todos los eventos pendientes | `WHERE status IN (...) AND next_attempt_at <= now LIMIT n` | Lote acotado, memoria constante |
| Calcular disponibilidad con bucles por hueco | Una consulta de ocupacion + aritmetica de intervalos | Un solo acceso a la base por peticion |

### Limites explicitos

| Limite | Valor | Donde |
|---|---|---|
| Registros por pagina | 15 por defecto, 100 maximo | `config/clinic.php` |
| Lote del procesador | 25 | `BATCH_SIZE` |
| Cuerpo HTTP | 1 MB | `client_max_body_size` en Nginx |
| Intentos de entrega | 5 | `MAX_ATTEMPTS` |
| Tiempo de espera del cliente HTTP | 4 s | `NotificationServiceClient` |

Los limites no son arbitrarios: cada uno evita un modo de fallo concreto
(tabla infinita en memoria, lotes infinitos, conexiones colgadas).

---

## 7. Disponibilidad y recuperacion

### health vs readiness

| Endpoint | Comprueba | Si falla |
|---|---|---|
| `/health` | Solo el proceso | Reiniciar el contenedor |
| `/ready` | El proceso **y** la base de datos | Sacar el servicio de rotacion |

Si `/health` consultara la base de datos, una caida de PostgreSQL provocaria
reinicios del servicio que no arreglarian nada, mientras la base siguiera
caida. Separar ambos permite que el orquestador reaccione de forma distinta
ante "el proceso esta muerto" y "una dependencia esta caida".

### Estado y reinicio

| Servicio | Politica | Consecuencia |
|---|---|---|
| `postgres` | `unless-stopped` | Conserva datos entre reinicios |
| `api` | `unless-stopped` | Vuelve sola tras la dependencia |
| `queue-worker` | `unless-stopped` | Reanuda la entrega de eventos |
| `notification-service` | `unless-stopped` | Vuelve con su cola intacta en la base |
| `migrator` | `no` | Se ejecuta una vez y termina |

### Recuperacion de datos

El volumen `postgres-data` es la unica fuente de verdad. Todo lo demas es
reconstruible:

```bash
make destroy && make up    # desde cero
```

La migracion, el seed, el esquema del microservicio y la configuracion se
regeneran automaticamente en el arranque. No hay estado que viva solo en memoria
y que se pierda: las notificaciones pendientes, por ejemplo, estan en
`clinic_notifications`, no en el proceso de Python.

---

## 8. Despliegue

### Secuencia

```bash
1. Levantar base de datos
2. Ejecutar migrador (espera a la base, genera APP_KEY, migra, siembra)
3. Levantar microservicio (crea su esquema)
4. Levantar API (espera al migrador y al microservicio)
5. Levantar worker
6. Levantar gateway (espera a API y microservicio)
```

El orden importa y esta codificado en `depends_on` con condiciones de salud, no
con `sleep`. `depends_on` sin `condition` solo espera a que el contenedor
arranque, no a que este listo, y ese es un fallo clasico de arranque.

### Variables criticas

| Variable | Donde | Consecuencia si falta |
|---|---|---|
| `APP_KEY` | Laravel | Sin cifrado de sesion; se genera en el arranque |
| `INTERNAL_API_KEY` | API | **Todas** las peticiones se rechazan (fail-closed) |
| `NOTIFICATIONS_API_KEY` | Ambos | La outbox acumula sin poder entregar |
| `DB_PASSWORD` | PostgreSQL | El servicio no arranca |

`NOTIFICATIONS_API_KEY` es el unico nombre que existe en el `.env` de la raiz.
Compose lo traduce al nombre que cada servicio ya espera: Laravel lo lee como
`NOTIFICATION_SERVICE_API_KEY` y FastAPI como `API_KEY`. El valor es el mismo; lo
que cambia es el alias, para que cada proyecto conserve su convencion y no haya
que recordar un nombre unico para dos equipos.

### Lo que faltaria para produccion

1. Proxy TLS delante de Nginx. `Strict-Transport-Security` ya la emite el
   gateway, pero sin TLS el navegador la ignora: por eso el header esta
   desplegado pero inactivo en este entorno.
2. Gestor de secretos en lugar de variables en un archivo.
3. Copias de seguridad automaticas de `postgres-data`, con restauracion probada.
4. Autenticacion con usuarios, roles y tokens, si el sistema se expone fuera
   de la red interna.
5. Metricas y trazas distribuidas (OpenTelemetry). El `request_id` ya permite
   correlacionar; falta la instrumentacion automatica.
