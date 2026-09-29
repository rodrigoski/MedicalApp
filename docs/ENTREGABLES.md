# Entregables

Inventario de lo construido, con el estado de cada punto. La distincion entre
"implementado y revisado" e "implementado y ejecutado" es intencional: en el
entorno de desarrollo usado no hay PHP, Composer, Docker, Python ni Git, asi que
la validacion ha sido estatica, no dinamica. Ver
[POSTMORTEM.md](POSTMORTEM.md#10-el-entorno-de-desarrollo-no-tiene-las-herramientas-del-proyecto).

---

## 1. Resumen

| Area | Estado |
|---|---|
| Monolito modular Laravel 12 con capas verificadas | Implementado, revisado |
| Dominio con invariantes y politica de no-traslape | Implementado, revisado |
| Persistencia PostgreSQL con restriccion de exclusion | Implementado, revisado |
| Patron outbox transaccional | Implementado, revisado |
| Microservicio FastAPI idempotente | Implementado, revisado |
| Gateway Nginx con limite de tasa | Implementado, revisado |
| Autenticacion, cabeceras, logging sin datos sensibles | Implementado, revisado |
| Pruebas unitarias y de feature (backend) | 9 archivos, revisado |
| Pruebas de dominio, servicio y API (FastAPI) | 3 archivos, revisado |
| Documentacion (6 documentos) | Completo |
| Un comando de arranque | Implementado, sin ejecutar |

---

## 2. Estructura del repositorio

```
ClinicApp/
├── docker-compose.yml          6 servicios, arranque con dependencias sanas
├── Makefile                    30 comandos, `make help` como indice
├── .env.example                configuracion de la raiz, sin secretos
├── .gitignore                  excluye credenciales, caches y artefactos
├── README.md                   guia de arranque y panorama
│
├── backend/                    Laravel 12 / PHP 8.3
│   ├── app/
│   │   ├── Domain/             entidades, value objects, politica, excepciones
│   │   ├── Application/        casos de uso, DTOs
│   │   ├── Infrastructure/     Eloquent, repositorios, cliente HTTP, reloj
│   │   ├── Http/               middleware, requests, controllers, resources
│   │   ├── Jobs/               entrega asincrona de eventos
│   │   ├── Console/Commands/   install, dispatch, prune, seed, arch:check
│   │   └── Support/            reglas de arquitectura, gestion de API keys
│   ├── config/                 11 archivos, incluido clinic.php
│   ├── database/migrations/    6 migraciones
│   ├── routes/                 api.php, console.php, web.php
│   ├── tests/                  TestCase + 9 archivos de prueba
│   └── Dockerfile
│
├── services/notification-service/   FastAPI / Python 3.12
│   ├── app/
│   │   ├── domain/             entidad, politica de reintentos, value objects
│   │   ├── application/        casos de uso, puertos (Protocol), DTOs
│   │   ├── infrastructure/     SQLAlchemy, repositorio, canales, reloj
│   │   ├── api/                esquemas, dependencias, 3 routers
│   │   ├── config.py           configuracion tipada por entorno
│   │   └── main.py             lifespan, middlewares, manejadores de error
│   ├── tests/                  conftest, fakes, 3 archivos de prueba
│   ├── requirements.txt        dependencias de ejecucion, versiones fijadas
│   ├── requirements-dev.txt    pytest y cobertura (solo etapa de pruebas)
│   ├── pytest.ini
│   └── Dockerfile              etapas deps -> test -> runtime, usuario sin privilegios
│
├── infra/
│   ├── postgres/init/          crea la 2a base, btree_gist, UTC
│   └── nginx/nginx.conf        gateway, rate limit, cabeceras, X-Request-Id
│
├── scripts/
│   ├── healthcheck.sh          5 grupos de comprobaciones
│   └── smoke-test.sh           recorrido funcional con verificacion de traslape
│
└── docs/                       6 documentos
```

---

## 3. Requisitos de negocio

| Requisito | Implementacion | Prueba |
|---|---|---|
| No permitir citas traslapadas para un medico | `AppointmentOverlapPolicy` + `EXCLUDE USING gist` | `AppointmentOverlapPolicyTest`, `AppointmentApiTest`, `smoke-test.sh` |
| No permitir citas traslapadas para un paciente | Idem, con restriccion por paciente | `AppointmentOverlapPolicyTest` |
| Respetar el horario del medico | Jornada en hora local de clinica | `AppointmentOverlapPolicyTest` |
| No agendar en domingo | `Doctor::worksOn` | `AppointmentOverlapPolicyTest` |
| No agendar en el pasado | `ClockInterface` con `FrozenClock` en pruebas | `AppointmentOverlapPolicyTest` |
| Limitar el numero de citas diarias | Ventana local convertida a UTC | `AppointmentApiTest` |
| Gestionar estados de la cita | Maquina de estados en la entidad | `AppointmentStateMachineTest` |
| Datos de paciente validados | Value objects + Form Requests | `PatientTest`, `PatientApiTest` |
| Documento unico | Indice unico parcial + comprobacion en servicio | `PatientApiTest` |
| Borrado logico | `deleted_at`, indice liberado | `PatientApiTest` |
| Notificar la creacion de citas | Outbox + microservicio | `DemoDataSeederTest`, `test_api.py` |

---

## 4. Sistema de citacion clinica

Diez dimensiones exigidas, con lo implementado en cada una.

### 4.1 Aislamiento (modularidad)

- Cuatro capas con dependencias hacia adentro.
- Verificacion ejecutable: `php artisan architecture:check` rechaza imports
  prohibidos, acceso directo a repositorios desde HTTP y entidades acopladas a
  Eloquent.
- El microservicio replica el criterio con `Protocol` como equivalente de las
  interfaces.

### 4.2 Mantenibilidad (calidad y limpieza del codigo)

- Nombres explicativos, sin abreviaturas.
- Comentarios que explican **por que**, no que hace el codigo. Cada decision no
  obvia esta razonada en su lugar.
- Metodos de una sola responsabilidad; los casos de uso no dejan de 30 lineas.
- Sin duplicacion entre capa HTTP y capa API: `ApiResponse` y `ExceptionMapper`
  concentran la forma de la respuesta.

### 4.3 Rendimiento

- Restriccion de exclusion en vez de Validacion en PHP.
- Consultas de traslape por rango, no por recorrido.
- Lote acotado en el procesador de notificaciones.
- Indices declarados para cada consulta frecuente, con la razon en la migracion.
- Sin N+1 en listados.

### 4.4 Seguridad

- API key en tiempo constante (`hash_equals`, `hmac.compare_digest`).
- Fail-closed si falta la configuracion.
- Limite de tasa en gateway y aplicacion.
- Cabeceras defensivas en las tres capas.
- Validacion en tres niveles: forma (Request), significado (value object),
  integridad (base de datos).
- Logs sin cuerpos de peticion, sin datos clinicos y sin credenciales.
- Contenedores sin privilegios.

### 4.5 Fiabilidad (tolerancia a fallos)

- Outbox transaccional: sin perdida ni duplicacion por fallo del receptor.
- Idempotencia con indice `UNIQUE` en el receptor.
- Reintentos con backoff exponencial y tope.
- Estado terminal `dead` para fallos permanentes.
- `health` (liveness) separado de `ready` (readiness).
- El procesador nunca muere: captura y registra, y el siguiente ciclo reintenta.
- Politica de reinicio por servicio segun su criticidad.

### 4.6 Auditoria y trazabilidad

- `X-Request-Id` propagado por las tres capas y presente en la respuesta.
- Eventos de dominio inmutables por cada cambio de estado.
- Borrado logico en pacientes.
- Retencion definida y poda de eventos ya entregados.
- Nada de datos clinicos en los logs.

### 4.7 Documentacion

| Documento | Contenido |
|---|---|
| `README.md` | Arranque, arquitectura, reglas, API, pruebas, limitaciones |
| `docs/ARQUITECTURA.md` | Capas, flujo de peticion, decisiones y alternativas descartadas |
| `docs/AUDITORIA.md` | Las seis dimensiones con evidencia concreta |
| `docs/API.md` | Endpoints, errores, ejemplos de peticion y respuesta |
| `docs/DESACOPLAMIENTO.md` | Outbox, idempotencia, reintentos y sus limites |
| `docs/POSTMORTEM.md` | 12 problemas encontrados y como se corrigieron |
| `docs/ENTREGABLES.md` | Este documento |
| Comments en el codigo | Cada decision no obvia, razonada en su lugar |

### 4.8 Despliegue

- `docker compose up --build`: seis servicios con dependencias sanas.
- Migraciones ejecutadas automaticamente en el arranque, con espera activa.
- Semilla de datos de demostracion reproducible e idempotente.
- Variables por entorno, sin secretos en el codigo.
- Imagenes minimas, usuarios sin privilegios, health checks.
- `make` como interfaz unica de operaciones.

### 4.9 Pruebas

**Backend (9 archivos):**

| Archivo | Cubre |
|---|---|
| `TimeRangeTest` | Semiabiertos, traslape en el borde, duracion, conversion |
| `ValueObjectsTest` | Correo, telefono, documento, licencia, paginacion |
| `AppointmentStateMachineTest` | Todas las transiciones validas e invalidas |
| `AppointmentOverlapPolicyTest` | Traslape, jornada, domingo, pasado, limite diario |
| `PatientTest` | Registro, estado, alergias, edad, borrado |
| `AppointmentApiTest` | Reserva, conflicto, disponibilidad, estado, reloj |
| `PatientApiTest` | CRUD, duplicados, busqueda, paginacion, borrado logico |
| `ApiSecurityTest` | Credenciales, cabeceras, 404 JSON, fail-closed, logs, `request_id` |
| `DemoDataSeederTest` | Conteos, rechazo de traslape, jornada, idempotencia |

**Microservicio (3 archivos):**

| Archivo | Cubre |
|---|---|
| `test_domain.py` | Value objects, entidad, politica de reintentos |
| `test_services.py` | Idempotencia, backoff, estado `dead`, lote acotado |
| `test_api.py` | Autenticacion, codigos, forma, liveness/readiness, `X-Request-Id` |

**Verificacion en ejecucion:** `make health` (servicios) y `make smoke`
(recorrido funcional que reserva dos veces el mismo hueco y exige 409).

### 4.10 Desacoplamiento

| Frontera | Mecanismo |
|---|---|
| API principal -> notificaciones | Outbox transaccional + HTTP con API key |
| Casos de uso -> persistencia | Interfaces y `Protocol` |
| Casos de uso -> canal de salida | Estrategias (`ChannelSender`) |
| Dominio -> framework | Sin imports de Laravel en el dominio |
| Servicio -> reloj | `ClockInterface` / puerto de reloj |

---

## 5. Puntos verificables en cinco minutos

```bash
make setup          # crea .env
make up             # levanta todo y migra
make health         # 5 grupos de comprobaciones
make smoke          # reserva, intenta traslapar (409), consulta, cancela
make test           # suite completa de ambos servicios
make logs           # seguir la traza de un request_id
```

Prueba manual de la garantia central:

```bash
# Dos peticiones simultaneas al mismo hueco
for i in 1 2; do
  curl -sS -X POST http://localhost:8080/api/v1/appointments \
    -H "X-Api-Key: dev-clinic-key-change-me" \
    -H 'Content-Type: application/json' \
    -d '{"patient_id":1,"doctor_id":1,
         "start_at":"2025-01-08T13:00:00Z",
         "end_at":"2025-01-08T13:30:00Z",
         "reason":"Prueba de concurrencia"}' &
done; wait
```

Una responde `201` y la otra `409`. Es la restriccion de exclusion de PostgreSQL
haciendo su trabajo: la politica de dominio sola no alcanza en esta carrera.

---

## 6. Verificacion pendiente

Lo que falta por hacer, y que exige un entorno con las herramientas instaladas:

1. `composer install` y `npm` no se han ejecutado: no hay `composer.lock`.
2. Suite de pruebas sin ejecutar. El codigo esta revisado de forma estatica
   (imports, firmas, constructores, llamadas cruzadas) y se corrigieron durante
   esa revision cuatro errores que habrian roto la suite en el primer arranque.
3. Migraciones sin ejecutar. La sintaxis SQL, en especial la restriccion de
   exclusion con `tstzrange` y `[)`, no se ha comprobado contra un PostgreSQL real.
4. `docker compose up` sin ejecutar. El orden de arranque y las condiciones de
   salud estan codificados, pero no se han observado.
5. Analisis estatico (`phpstan`, `ruff`, `mypy`) no ejecutado.
6. Prueba de concurrencia con peticiones HTTP reales simultaneas, no simulada.

Los seis puntos estan aqui y no en una nota al pie porque un entregable que
oculta lo que no se ha verificado no permite decidir nada.
