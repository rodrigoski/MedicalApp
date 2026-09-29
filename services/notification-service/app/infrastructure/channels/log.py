"""Canal por defecto: la notificacion queda en el log del contenedor."""

from __future__ import annotations

import logging

from app.domain.entities import Notification
from app.infrastructure.channels.base import ChannelSenderBase

logger = logging.getLogger(__name__)


class LogChannelSender(ChannelSenderBase):
    """
    Escribe la notificacion en el log.

    Es el canal de desarrollo y demostracion. Permite ejercitar todo el flujo
    (outbox -> evento -> notificacion -> reintentos) sin contratar ni simular
    un proveedor de SMS: cuando exista la pasarela real se anade
    `SmsChannelSender` y se cambia `CHANNEL=sms`, sin tocar las reglas.
    """

    name = "log"

    def _deliver(self, notification: Notification) -> None:
        logger.info(
            "notification.channel.log",
            extra={"envelope": notification.envelope()},
        )
