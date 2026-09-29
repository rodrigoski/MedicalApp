"""
Punto de entrada del microservicio de notificaciones (FastAPI).

Responsabilidades de este archivo, y solo de este archivo:
  1. construir el contenedor de dependencias,
  2. abrir y cerrar recursos (base de datos, canal, procesador),
  3. registrar manejadores de error y de trazas,
  4. montar los routers.

Todo lo demas vive en las capas de abajo. Un `main.py` que ademas contiene
reglas de negocio seria el primer paso hacia un monolito disfrazado de
microservicio.
"""

from __future__ import annotations

import logging
import re
import time
import uuid
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException

from app.api.deps import Container
from app.api.routes import events_router, health_router, notifications_router
from app.application.services import DeliverPendingService
from app.config import Settings, get_settings
from app.domain.exceptions import (
    DuplicateEvent,
    InvalidEventPayload,
    InvalidNotificationId,
    NotificationDomainError,
)
from app.infrastructure.channels import LogChannelSender, WebhookChannelSender
from app.infrastructure.clock import SystemClock
from app.infrastructure.database import Database
from app.infrastructure.repositories import SqlAlchemyNotificationRepository

logger = logging.getLogger("notification_service")


# --------------------------------------------------------------------------- #
# Construccion del contenedor
# --------------------------------------------------------------------------- #
def build_channel_sender(settings: Settings):  # type: ignore[no-untyped-def]
    """
    Resuelve el canal de salida a partir de la configuracion.

    Es el unico lugar donde se decide que implementacion se usa. El caso de uso
    `DeliverPendingService` recibe un `ChannelSender` y no sabe cual es.
    """
    from app.domain.value_objects import Channel

    if Channel.parse(settings.channel) is Channel.WEBHOOK:
        return WebhookChannelSender(
            url=settings.webhook_url or "",
            timeout=settings.webhook_timeout_seconds,
        )

    return LogChannelSender()


def build_container(settings: Settings) -> Container:
    database = Database(
        settings.database_url,
        echo=settings.db_echo,
        pool_size=settings.db_pool_size,
        max_overflow=settings.db_max_overflow,
        pool_recycle=settings.db_pool_recycle_seconds,
    )

    return Container(
        settings=settings,
        database=database,
        channel_sender=build_channel_sender(settings),
    )


# --------------------------------------------------------------------------- #
# Procesador en segundo plano
# --------------------------------------------------------------------------- #
def run_processor(container: Container, stop_flag: list[bool]) -> None:
    """
    Bucle que entrega notificaciones vencidas.

    Es un hilo demonio, no un `asyncio.create_task`, porque TODO el trabajo
    (SQLAlchemy y el cliente HTTP) es sincrono. Enganarlo a un hilo mantiene el
    event loop libre para atender peticiones, que es lo que un servicio con API
    necesita.

    Se elige un hilo en lugar de un scheduler externo (Celery, cron) porque el
    servicio ya es un despliegue propio: anadir otra dependencia para hacer un
    `while True: ... sleep(...)` seria complejidad sin beneficio. Si el
    procesamiento creciera, la sustitucion natural es `POST /api/v1/process`
    llamado desde cron o desde el backend.
    """
    interval = container.settings.poll_interval_seconds

    while not stop_flag[0]:
        try:
            with container.database.session() as session:
                service = DeliverPendingService(
                    repository=SqlAlchemyNotificationRepository(session),
                    sender=container.channel_sender,
                    clock=SystemClock(),
                    retry_policy=container.retry_policy,
                    batch_size=container.settings.batch_size,
                )
                summary = service.execute()

            if summary.processed:
                logger.info("notification.processor.cycle", extra=summary.to_dict())
        except Exception:  # noqa: BLE001 - el bucle NUNCA debe morir
            # Si el procesador dejara de correr, las notificaciones se quedarian
            # encoladas para siempre sin que nadie lo note. Se captura todo y se
            # registra con excepcion; el siguiente ciclo reintenta.
            logger.exception("notification.processor.cycle_failed")

        # Se duerme en tramos cortos para poder parar el servicio con prontitud.
        for _ in range(interval):
            if stop_flag[0]:
                return
            time.sleep(1)


# --------------------------------------------------------------------------- #
# Ciclo de vida
# --------------------------------------------------------------------------- #
@asynccontextmanager
async def lifespan(app: FastAPI) -> AsyncIterator[None]:
    """
    Arranque y apagado ordenados.

    1. Se crea el contenedor y se guarda en `app.state` (de ahi lo leen las
       dependencias de FastAPI).
    2. Se asegura el esquema: si la base no esta lista, el servicio arranca igual
       pero `ready` devuelve 503. Es preferible a morir en bucle de reinicios.
    3. Se lanza el procesador en un hilo.
    4. Al apagar, se para el procesador ANTES de cerrar la base de datos, para no
       dejar una consulta a medias.
    """
    settings = get_settings()
    container = build_container(settings)
    app.state.container = container

    try:
        container.database.create_schema()
    except Exception:  # noqa: BLE001 - se reportara en /ready
        logger.exception("notification.schema.not_ready")

    stop_flag = [False]
    worker = None

    if settings.environment != "test":
        import threading

        worker = threading.Thread(
            target=run_processor,
            args=(container, stop_flag),
            name="notification-processor",
            daemon=True,
        )
        worker.start()
        logger.info("notification.processor.started", extra={"interval": settings.poll_interval_seconds})

    try:
        yield
    finally:
        stop_flag[0] = True
        if worker is not None:
            worker.join(timeout=5)

        close = getattr(container.channel_sender, "close", None)
        if callable(close):
            close()

        container.database.dispose()
        logger.info("notification.shutdown.complete")


# --------------------------------------------------------------------------- #
# Aplicacion
# --------------------------------------------------------------------------- #
def create_app() -> FastAPI:
    settings = get_settings()

    logging.basicConfig(
        level=logging.DEBUG if settings.debug else logging.INFO,
        format="%(asctime)s %(levelname)-8s %(name)s %(message)s",
    )

    app = FastAPI(
        title="Notification Service",
        description=(
            "Microservicio de notificaciones del sistema de clinica. Consume los "
            "eventos de dominio publicados por la outbox del backend Laravel y "
            "entrega las notificaciones de forma idempotente y con reintentos."
        ),
        version=settings.version,
        lifespan=lifespan,
        docs_url="/docs",
        openapi_url="/openapi.json",
    )

    _register_middleware(app)
    _register_error_handlers(app)
    _register_routes(app)

    return app


# Formato admitido para el identificador de trazabilidad. Deliberadamente
# restringido y con tope de longitud, igual que en el backend: el valor se
# propaga a una cabecera de respuesta y a un log, y ambas superficies son
# objetivo habitual de inyeccion. Se acepta el UUID de Nginx (hex), el que
# genera este servicio (UUID v4) y el que usa el backend.
_REQUEST_ID_PATTERN = re.compile(r"^[A-Za-z0-9._-]{1,64}$")


def _resolve_request_id(raw: str | None) -> str:
    """
    Decide que identificador usar para esta peticion.

    Se reutiliza el entrante cuando es utilizable y se genera uno nuevo cuando
    no lo es. Descartar el valor (en vez de intentar "limpiarlo") es lo
    correcto: un identificador que hubo que mutilar ya no es reliable para
    correlacionar, asi que es preferible devolver uno honestamente generado
    que propagar uno recortado que no aparece en ninguna otra parte del rastro.
    """
    if raw and _REQUEST_ID_PATTERN.fullmatch(raw):
        return raw

    return str(uuid.uuid4())


def _register_middleware(app: FastAPI) -> None:
    @app.middleware("http")
    async def request_context(request: Request, call_next):  # type: ignore[no-untyped-def]
        """
        Identidad de la peticion y tiempo de respuesta.

        El `X-Request-Id` se propaga desde el backend: un mismo identificador
        atraviesa la reserva de la cita, la escritura en la outbox, la entrega al
        microservicio y el envio final. Con eso, un solo `grep` en los logs
        reconstruye la historia completa de una peticion, que es justo lo que se
        necesita para auditar un incidente.

        El valor entrante se filtra antes de usarlo. El servicio esta publicado
        tambien en un puerto propio, asi que el identificador no siempre llega de
        Nginx: cualquier cliente que alcance ese puerto controla la cadena, y esa
        cadena acaba en una cabecera de respuesta y en un log. Sin filtro, un
        salto de linea permitiria inyectar cabeceras o falsificar entradas del
        registro.
        """
        request_id = _resolve_request_id(request.headers.get("X-Request-Id"))
        request.state.request_id = request_id
        started = time.perf_counter()

        response = await call_next(request)

        duration_ms = round((time.perf_counter() - started) * 1000, 2)
        response.headers["X-Request-Id"] = request_id
        response.headers["X-Response-Time-ms"] = str(duration_ms)
        response.headers["X-Content-Type-Options"] = "nosniff"
        response.headers["X-Frame-Options"] = "DENY"
        response.headers["Referrer-Policy"] = "no-referrer"

        logger.info(
            "notification.http.request",
            extra={
                "request_id": request_id,
                "method": request.method,
                "path": request.url.path,
                "status": response.status_code,
                "duration_ms": duration_ms,
            },
        )

        return response


def _error(status_code: int, code: str, message: str, request_id: str | None, **context):  # type: ignore[no-untyped-def]
    """
    Construye la respuesta de error.

    Se replica la forma del backend Laravel a proposito: `error.code` estable,
    `error.detail` para el usuario, `error.status` y el contexto, y
    `request_id` en la RAIZ del cuerpo, no dentro de `error`. Ambos servicios
    cuelgan del mismo gateway, asi que un cliente que atraviese los dos debe
    poder leer el error de la misma forma; si aqui el identificador viviera
    anidado, cada cliente necesitaria dos rutas de lectura distintas.

    El nombre es `detail` y no `message` por el mismo motivo: es el termino que
    usa el backend y el de RFC 9457 para la explicacion legible del problema.
    """
    body = {
        "success": False,
        "error": {
            "code": code,
            "detail": message,
            "status": status_code,
            "context": context,
        },
        "request_id": request_id,
    }
    return JSONResponse(status_code=status_code, content=body)


def _register_error_handlers(app: FastAPI) -> None:
    """
    Manejadores de error con la forma del backend Laravel.

    Que el error tenga siempre `error.code` y `error.detail` evita que el
    consumidor tenga que interpretar el `detail` de FastAPI en unos casos y el
    `error` del backend en otros.
    """

    @app.exception_handler(RequestValidationError)
    async def on_validation_error(request: Request, exc: RequestValidationError):  # type: ignore[no-untyped-def]
        return _error(
            422,
            "validation.failed",
            "Los datos enviados no cumplen el contrato.",
            getattr(request.state, "request_id", None),
            errors=_serialisable_errors(exc),
        )

    @app.exception_handler(StarletteHTTPException)
    async def on_http_exception(request: Request, exc: StarletteHTTPException):  # type: ignore[no-untyped-def]
        detail = exc.detail
        if isinstance(detail, dict):
            code = detail.get("code", "http.error")
            # Se acepta `message` por compatibilidad con cualquier `HTTPException`
            # que se construya en otro punto, pero la clave canonica es `detail`:
            # es el nombre que viaja al cliente y el que usa el backend.
            message = detail.get("detail") or detail.get("message") or "No se pudo completar la peticion."
            context = {k: v for k, v in detail.items() if k not in ("code", "detail", "message")}
        else:
            code = "http.error"
            message = str(detail)
            context = {}

        return _error(exc.status_code, code, message, getattr(request.state, "request_id", None), **context)

    @app.exception_handler(DuplicateEvent)
    async def on_duplicate_event(request: Request, exc: DuplicateEvent):  # type: ignore[no-untyped-def]
        return _error(
            409,
            "events.duplicate",
            str(exc),
            getattr(request.state, "request_id", None),
        )

    @app.exception_handler(InvalidEventPayload)
    async def on_invalid_event(request: Request, exc: InvalidEventPayload):  # type: ignore[no-untyped-def]
        return _error(
            422,
            "events.invalid_payload",
            str(exc),
            getattr(request.state, "request_id", None),
        )

    @app.exception_handler(InvalidNotificationId)
    async def on_invalid_id(request: Request, exc: InvalidNotificationId):  # type: ignore[no-untyped-def]
        return _error(
            400,
            "notifications.invalid_id",
            str(exc),
            getattr(request.state, "request_id", None),
        )

    @app.exception_handler(NotificationDomainError)
    async def on_domain_error(request: Request, exc: NotificationDomainError):  # type: ignore[no-untyped-def]
        return _error(
            422,
            "domain.invalid_operation",
            str(exc),
            getattr(request.state, "request_id", None),
        )

    @app.exception_handler(Exception)
    async def on_unexpected_error(request: Request, exc: Exception):  # type: ignore[no-untyped-def]
        # Se registra la excepcion completa para el operador, pero al cliente solo
        # se le da un mensaje generico y el request_id. Devolver el detalle de
        # una excepcion inesperada filtraria rutas, credenciales o SQL.
        logger.exception("notification.unhandled_error")
        return _error(
            500,
            "internal.error",
            "Ocurrio un error inesperado. Referencia el identificador de la peticion.",
            getattr(request.state, "request_id", None),
        )


def _serialisable_errors(exc: RequestValidationError) -> list[dict]:  # type: ignore[no-untyped-def]
    """
    Convierte los errores de Pydantic en una lista JSON-serializable.

    Sin esta conversion, un error de validacion cuyo `ctx` contenga un
    `ValueError` haria fallar el `JSONResponse` y devolveria un 500 en lugar de
    un 422, ocultando el problema real.

    Tambien se quita la raiz de la ubicacion ("body", "query"): el consumidor
    quiere saber que campo corregir ("email"), no de donde vino ("body.email").
    """
    serialised = []
    for error in exc.errors():
        location = [str(part) for part in error.get("loc", ())]
        if location and location[0] in ("body", "query", "path"):
            location = location[1:]

        serialised.append(
            {
                "field": ".".join(location),
                "message": error.get("msg", "valor invalido"),
                "type": error.get("type", "invalid"),
            }
        )
    return serialised


def _register_routes(app: FastAPI) -> None:
    app.include_router(health_router)
    app.include_router(events_router)
    app.include_router(notifications_router)


app = create_app()
