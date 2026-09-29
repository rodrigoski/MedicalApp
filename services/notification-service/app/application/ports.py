"""
Puertos (contratos) de la capa de aplicacion.

`typing.Protocol` es la forma que tiene Python de expresar interfaces. Al ser
estructural, no hace falta que las implementaciones hereden de nada: basta con
tener los metodos correctos. Esto mantiene el acoplamiento en cero, igual que las
interfaces del backend Laravel.
"""

from __future__ import annotations

from datetime import datetime
from typing import Protocol, runtime_checkable

from app.domain.entities import Notification
from app.domain.value_objects import NotificationId, NotificationStatus


@runtime_checkable
class ClockPort(Protocol):
    """Reloj inyectable: permite pruebas deterministas."""

    def now(self) -> datetime: ...


@runtime_checkable
class NotificationRepositoryPort(Protocol):
    """Persistencia de notificaciones."""

    def find_by_event_id(self, event_id: str) -> Notification | None:
        """Devuelve la notificacion creada para ese evento, o None si es nuevo."""
        ...

    def find_by_id(self, notification_id: NotificationId) -> Notification | None: ...

    def add(self, notification: Notification) -> None:
        """Persiste una notificacion nueva."""
        ...

    def update(self, notification: Notification) -> None:
        """Guarda los cambios de estado, intentos y errores."""
        ...

    def due(self, now: datetime, limit: int) -> list[Notification]:
        """
        Notificaciones pendientes o fallidas cuyo momento de reintento ya llego.

        El limite es obligatorio: sin el, un backlog grande bloquearia el
        proceso y un unico destino lento ahogaria el resto.
        """
        ...

    def list_by_status(self, status: NotificationStatus | None, limit: int) -> list[Notification]:
        """Listado acotado para observabilidad, opcionalmente por estado."""
        ...

    def count_by_status(self) -> dict[str, int]: ...


@runtime_checkable
class ChannelSender(Protocol):
    """
    Canal de salida (log, webhook, SMS...).

    `name` identifica el canal en logs y metricas. `send` DEBE lanzar una
    excepcion si la entrega falla: "no pude enviar" es un fallo de
    infraestructura, no un resultado valido de negocio. Confundir ambos casos es
    como se pierden notificaciones silenciosamente.
    """

    name: str

    def send(self, notification: Notification) -> None: ...


__all__ = [
    "ChannelSender",
    "ClockPort",
    "NotificationRepositoryPort",
]
