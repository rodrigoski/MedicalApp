"""
CAPA DE INFRAESTRUCTURA: todo lo que toca el exterior.

    - SQLAlchemy: motor, sesion y modelo de persistencia
    - repositorios: traducen filas <-> entidades de dominio
    - canales: log y webhook
    - reloj real

Ninguna clase de esta capa se importa desde `app.domain` ni desde
`app.application`; la dependencia va siempre en un sentido.
"""

from app.infrastructure.channels import LogChannelSender, WebhookChannelSender
from app.infrastructure.clock import SystemClock
from app.infrastructure.database import Database
from app.infrastructure.repositories import SqlAlchemyNotificationRepository

__all__ = [
    "Database",
    "LogChannelSender",
    "SqlAlchemyNotificationRepository",
    "SystemClock",
    "WebhookChannelSender",
]
