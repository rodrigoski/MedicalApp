"""
Value objects del dominio de notificaciones.

Cada value object encapsula UNA invariante. Un `NotificationStatus` no puede
contener un texto que no sea uno de los estados del ciclo de vida; un
`NotificationId` no puede ser un UUID con formato invalido. Gracias a eso, el
resto del codigo no necesita validaciones defensivas repartidas por todos lados.
"""

from __future__ import annotations

import re
import uuid
from dataclasses import dataclass
from enum import StrEnum
from typing import Any, Mapping

from app.domain.exceptions import InvalidNotificationId, UnknownChannel

_UUID_RE = re.compile(
    r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$",
    re.IGNORECASE,
)


class NotificationStatus(StrEnum):
    """
    Ciclo de vida de una notificacion.

        PENDING -> SENT
                  -> FAILED -> PENDING (reintento con backoff)
                                 -> DEAD  (agotados los reintentos)

    `DEAD` es terminal: un evento que agoto todos sus intentos pasa a una cola
    de revision manual en lugar de reintentarse para siempre.
    """

    PENDING = "pending"
    SENT = "sent"
    FAILED = "failed"
    DEAD = "dead"


class Channel(StrEnum):
    """Medios de entrega soportados."""

    LOG = "log"
    WEBHOOK = "webhook"

    @classmethod
    def parse(cls, value: str) -> "Channel":
        try:
            return cls(str(value).strip().lower())
        except ValueError as exc:
            raise UnknownChannel(
                f"Canal desconocido: {value!r}. Permitidos: {[c.value for c in cls]}"
            ) from exc


@dataclass(frozen=True, slots=True)
class NotificationId:
    """Identificador de la notificacion. Inmutable y comparable por valor."""

    value: str

    def __post_init__(self) -> None:
        if not isinstance(self.value, str) or not _UUID_RE.match(self.value.strip()):
            raise InvalidNotificationId(
                f"El identificador debe ser un UUID v4 valido, se recibio: {self.value!r}"
            )
        object.__setattr__(self, "value", self.value.strip().lower())

    @classmethod
    def generate(cls) -> "NotificationId":
        return cls(str(uuid.uuid4()))

    def __str__(self) -> str:
        return self.value


@dataclass(frozen=True, slots=True)
class AggregateRef:
    """
    Referencia al agregado de origen del evento.

    Se guarda separada del payload porque es el dato que permite responder
    "¿este SMS era por la cita 812 o por la cita 940?" sin tener que abrir el
    cuerpo de la notificacion.
    """

    type: str
    id: int

    def __post_init__(self) -> None:
        if not self.type or not self.type.strip():
            raise ValueError("El tipo de agregado no puede estar vacio")
        if self.id <= 0:
            raise ValueError("El identificador de agregado debe ser un entero positivo")

    def as_dict(self) -> dict[str, Any]:
        return {"type": self.type, "id": self.id}


@dataclass(frozen=True, slots=True)
class EventEnvelope:
    """
    Evento de dominio recibido del backend.

    Se valida al construirlo, no al usarlo: si el microservicio acepta un evento
    sin `event_id`, no podria garantizar idempotencia, que es su garantia mas
    importante.
    """

    event_id: str
    event_type: str
    aggregate: AggregateRef
    occurred_at: str
    payload: Mapping[str, Any]
    request_id: str | None = None

    def __post_init__(self) -> None:
        if not self.event_id or not self.event_id.strip():
            raise ValueError("event_id es obligatorio: es la clave de idempotencia")
        if not self.event_type or not self.event_type.strip():
            raise ValueError("event_type es obligatorio")
        if not isinstance(self.payload, Mapping):
            raise ValueError("payload debe ser un objeto")
        object.__setattr__(self, "payload", dict(self.payload))
