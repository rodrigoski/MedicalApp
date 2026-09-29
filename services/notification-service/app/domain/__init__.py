"""
CAPA DE DOMINIO: reglas del microservicio de notificaciones.

No conoce FastAPI, ni SQLAlchemy, ni HTTP. Solo sabe que es una notificacion,
que puede fallar y cuantas veces se reintenta. Por eso esta capa se puede
probar sin levantar un servidor ni una base de datos.
"""

from app.domain.exceptions import (
    DuplicateEvent,
    InvalidEventPayload,
    InvalidNotificationId,
    UnknownChannel,
)
from app.domain.entities import Notification
from app.domain.policies import RetryPolicy
from app.domain.value_objects import (
    AggregateRef,
    Channel,
    EventEnvelope,
    NotificationId,
    NotificationStatus,
)

__all__ = [
    "AggregateRef",
    "Channel",
    "DuplicateEvent",
    "EventEnvelope",
    "InvalidEventPayload",
    "InvalidNotificationId",
    "Notification",
    "NotificationId",
    "NotificationStatus",
    "RetryPolicy",
    "UnknownChannel",
]
