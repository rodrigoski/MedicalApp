"""
Plantilla comun de los canales de salida.

Se separa del canal concreto para que la traza de "se intento entregar" exista
una sola vez, y no se duplique en cada implementacion.
"""

from __future__ import annotations

import logging
from abc import ABC, abstractmethod

from app.domain.entities import Notification

logger = logging.getLogger(__name__)


class ChannelSenderBase(ABC):
    """
    Base de la estrategia de canal.

    `send` envuelve a `_deliver` y registra el intento. La subclase implementa
    unicamente la entrega efectiva.
    """

    name: str = "base"

    def send(self, notification: Notification) -> None:
        logger.info(
            "notification.channel.attempt",
            extra={
                "channel": self.name,
                "event_id": notification.event_id,
                "event_type": notification.event_type,
                "attempt": notification.attempts,
            },
        )
        self._deliver(notification)

    @abstractmethod
    def _deliver(self, notification: Notification) -> None:
        """
        Entrega efectiva. DEBE lanzar excepcion si la entrega falla.

        No se captura nada aqui a proposito: la politica de reintentos vive en
        el caso de uso, no en el canal.
        """
        raise NotImplementedError
