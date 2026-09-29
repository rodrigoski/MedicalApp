"""
Contenedor de dependencias y autenticacion.

`Container` es un service locator muy simple que se construye UNA vez en el
arranque y se guarda en `app.state.container`. Las rutas no crean objetos: los
piden como dependencias de FastAPI. Es preferible a tener singletons por modulo
porque:

  - las pruebas pueden inyectar su propio contenedor con dobles en memoria,
  - no hay estado global creado en el momento de importacion,
  - el grafo de dependencias se lee entero en este archivo.

La sesion de base de datos se abre POR PETICION mediante un generador con
`yield`: FastAPI la cierra al terminar la respuesta, incluso si la ruta lanza
excepcion. Compartir una sesion entre peticiones haria que un fallo en una
contaminara a las demas.
"""

from __future__ import annotations

import hmac
import logging
from collections.abc import Iterator
from typing import Annotated

from fastapi import Depends, Header, HTTPException, Request, status
from sqlalchemy.orm import Session

from app.application.services import DeliverPendingService, ReceiveEventService
from app.config import Settings
from app.domain.policies import RetryPolicy
from app.domain.value_objects import Channel
from app.infrastructure.channels import ChannelSenderBase
from app.infrastructure.clock import SystemClock
from app.infrastructure.database import Database
from app.infrastructure.repositories import SqlAlchemyNotificationRepository

logger = logging.getLogger(__name__)


class Container:
    """Grafo de dependencias construido en el arranque de la aplicacion."""

    def __init__(
        self,
        settings: Settings,
        database: Database,
        channel_sender: ChannelSenderBase,
    ) -> None:
        self.settings = settings
        self.database = database
        self.channel_sender = channel_sender
        self.retry_policy = RetryPolicy(
            max_attempts=settings.max_attempts,
            base_delay_seconds=settings.backoff_base_seconds,
            cap_delay_seconds=settings.backoff_max_seconds,
        )

    @property
    def default_channel(self) -> Channel:
        return Channel.parse(self.settings.channel)


# --------------------------------------------------------------------------- #
# Dependencias de FastAPI
# --------------------------------------------------------------------------- #
def get_container(request: Request) -> Container:
    """Contenedor global de la aplicacion."""
    return request.app.state.container


def get_session(container: Annotated[Container, Depends(get_container)]) -> Iterator[Session]:
    """
    Sesion transaccional por peticion.

    El cierre lo hace `Database.session()`, que hace `commit` al salir del
    `with`, `rollback` si hubo excepcion y `close` siempre.
    """
    with container.database.session() as session:
        yield session


def get_repository(
    session: Annotated[Session, Depends(get_session)],
) -> SqlAlchemyNotificationRepository:
    return SqlAlchemyNotificationRepository(session)


def get_clock() -> SystemClock:
    # Se declara como dependencia (y no se usa `SystemClock()` en las rutas) para
    # que las pruebas puedan reemplazarla por un reloj fijo.
    return SystemClock()


def get_receive_event(
    repository: Annotated[SqlAlchemyNotificationRepository, Depends(get_repository)],
    clock: Annotated[SystemClock, Depends(get_clock)],
    container: Annotated[Container, Depends(get_container)],
) -> ReceiveEventService:
    return ReceiveEventService(
        repository=repository,
        clock=clock,
        default_channel=container.default_channel,
    )


def get_deliver_pending(
    repository: Annotated[SqlAlchemyNotificationRepository, Depends(get_repository)],
    clock: Annotated[SystemClock, Depends(get_clock)],
    container: Annotated[Container, Depends(get_container)],
) -> DeliverPendingService:
    return DeliverPendingService(
        repository=repository,
        sender=container.channel_sender,
        clock=clock,
        retry_policy=container.retry_policy,
        batch_size=container.settings.batch_size,
    )


def require_api_key(
    container: Annotated[Container, Depends(get_container)],
    authorization: Annotated[str | None, Header()] = None,
    x_api_key: Annotated[str | None, Header()] = None,
) -> None:
    """
    Verifica la API key compartida. Responde 401 si falta o no coincide.

    Se acepta `Authorization: Bearer <clave>` porque es lo que envia
    `Http::withToken()` del backend, y `X-Api-Key` por comodidad de depuracion.
    La comparacion es en TIEMPO CONSTANTE (`hmac.compare_digest`): comparar con
    `==` devolveria antes ante el primer caracter distinto y permitiria reconstruir
    la clave byte a byte midiendo tiempos de respuesta.
    """
    presented: str | None = None

    if authorization and authorization.lower().startswith("bearer "):
        presented = authorization[7:].strip()
    elif x_api_key:
        presented = x_api_key.strip()

    if not presented:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail={
                "code": "auth.key_missing",
                "detail": "Falta la cabecera de autenticacion.",
            },
            headers={"WWW-Authenticate": "Bearer"},
        )

    if not hmac.compare_digest(presented, container.settings.api_key):
        # NO se registra ni el valor ni la longitud de la clave presentada.
        # El valor es una fuga de credenciales directa, y la longitud no aporta
        # nada al diagnostico (ya se sabe que la clave era incorrecta) mientras
        # que si ofrece un canal lateral trivial. El middleware de peticiones ya
        # registro el `request_id` de esta llamada, asi que el rechazo queda
        # correlacionado sin necesidad de duplicar datos aqui.
        logger.warning("notification.auth.rejected")
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail={
                "code": "auth.invalid_key",
                "detail": "La credencial proporcionada no es valida.",
            },
            headers={"WWW-Authenticate": "Bearer"},
        )
