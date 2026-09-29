"""
DTOs de la capa de aplicacion.

Un DTO es un objeto de transporte sin comportamiento. Se usa para que la capa
de aplicacion devuelva informacion simple y serializable en lugar de filtrar
entidades de dominio hacia la API, que es una fuga de capa clasica.
"""

from __future__ import annotations

from dataclasses import dataclass
from enum import StrEnum


class ProcessOutcome(StrEnum):
    """Resultado de intentar entregar UNA notificacion."""

    SENT = "sent"
    RETRY_SCHEDULED = "retry_scheduled"
    EXHAUSTED = "exhausted"
    SKIPPED = "skipped"


@dataclass(frozen=True, slots=True)
class Receipt:
    """
    Acuse de recepcion de un evento.

    Es lo que devuelve `POST /api/v1/events`. El campo `duplicate` es la pieza
    clave de la idempotencia: la outbox de Laravel puede reenviar el mismo
    evento si no lee la respuesta, y el microservicio responde 200 con la
    notificacion ya existente en lugar de crear una segunda.
    """

    notification_id: str
    event_id: str
    duplicate: bool = False

    def to_dict(self) -> dict[str, object]:
        return {
            "notification_id": self.notification_id,
            "event_id": self.event_id,
            "duplicate": self.duplicate,
        }


@dataclass(frozen=True, slots=True)
class ProcessSummary:
    """Resumen de una pasada del procesador de notificaciones."""

    processed: int
    sent: int
    retry_scheduled: int
    exhausted: int
    skipped: int

    def to_dict(self) -> dict[str, int]:
        return {
            "processed": self.processed,
            "sent": self.sent,
            "retry_scheduled": self.retry_scheduled,
            "exhausted": self.exhausted,
            "skipped": self.skipped,
        }
