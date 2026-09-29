"""
Agregado `Notification`.

Es la entidad central del microservicio. Concentra TODAS las reglas que deciden
si una notificacion se entrega, se reintenta o se abandona, de modo que el
repositorio y los canales solo necesitan guardar y enviar, sin decidir nada.
"""

from __future__ import annotations

from datetime import UTC, datetime, timedelta
from typing import Any, Mapping, overload

from app.domain.value_objects import (
    AggregateRef,
    Channel,
    EventEnvelope,
    NotificationId,
    NotificationStatus,
)


@overload
def _as_utc(value: datetime) -> datetime: ...


@overload
def _as_utc(value: None) -> None: ...


def _as_utc(value: datetime | None) -> datetime | None:
    """
    Normaliza un instante DATETIME a UTC con zona horaria explicita.

    POR QUE ESTA FUNCION EXISTE: la columna se declara `DateTime(timezone=True)`,
    pero eso solo es una instruccion para el motor, no una garantia. PostgreSQL
    devuelve datetimes con `tzinfo`; **SQLite no tiene tipo fecha nativo** y
    devuelve datetimes ingenuos, aunque la columna lo pida. Una fila leida de
    SQLite llega con `next_attempt_at` sin zona, y `is_due()` la compara con un
    `now` con zona: `TypeError: can't compare offset-naive and offset-aware
    datetimes`.

    El fallo no aparece en produccion (PostgreSQL) y si en las pruebas (SQLite),
    que es el peor sitio posible para descubrirlo. Se asume UTC en lugar de
    "hora local", porque el unico motor que produce valores ingenuos es SQLite y
    sus pruebas siempre trabajan en UTC.

    Va en `rehydrate` y no en el repositorio porque este es, por diseño, el
    unico punto donde se aceptan datos de la infraestructura: el mismo lugar
    donde ya se normaliza el identificador, que tambien cambia segun el motor.

    Las sobrecargas existen para que la firma siga reflejando la nulabilidad real
    de cada columna: `created_at` y `updated_at` son NOT NULL, `sent_at` y
    `next_attempt_at` no. Sin ellas, el type checker trataria `created_at` como
    opcional y obligaria a un `assert` que solo existe para satisfacer al
    verificador, no al modelo de datos.
    """
    if value is None:
        return None

    if value.tzinfo is None:
        return value.replace(tzinfo=UTC)

    return value.astimezone(UTC)


class Notification:
    """
    Notificacion a entregar, derivada de un evento de dominio.

    Nota de diseno: NO se usa `dataclass` porque la entidad tiene
    comportamiento (transiciones de estado) y, al ser mutable por la app de
    aplicacion, `dataclass` frozen seria enganoso.
    """

    __slots__ = (
        "_id",
        "_event_id",
        "_event_type",
        "_aggregate",
        "_channel",
        "_status",
        "_payload",
        "_attempts",
        "_last_error",
        "_created_at",
        "_updated_at",
        "_sent_at",
        "_next_attempt_at",
    )

    def __init__(
        self,
        id: NotificationId,
        event_id: str,
        event_type: str,
        aggregate: AggregateRef,
        channel: Channel,
        status: NotificationStatus,
        payload: Mapping[str, Any],
        created_at: datetime,
        updated_at: datetime,
        attempts: int = 0,
        last_error: str | None = None,
        sent_at: datetime | None = None,
        next_attempt_at: datetime | None = None,
    ) -> None:
        self._id = id
        self._event_id = event_id
        self._event_type = event_type
        self._aggregate = aggregate
        self._channel = channel
        self._status = status
        self._payload = dict(payload)
        self._attempts = attempts
        self._last_error = last_error
        self._created_at = created_at
        self._updated_at = updated_at
        self._sent_at = sent_at
        self._next_attempt_at = next_attempt_at

    # ------------------------------------------------------------------ #
    # Constructores
    # ------------------------------------------------------------------ #
    @classmethod
    def from_event(
        cls,
        event: EventEnvelope,
        channel: Channel,
        now: datetime,
    ) -> "Notification":
        """
        Crea la notificacion a partir de un evento entrante.

        Nace en `PENDING` y con `attempts = 0`: todavia nadie intento entregarla.
        """
        return cls(
            id=NotificationId.generate(),
            event_id=event.event_id,
            event_type=event.event_type,
            aggregate=event.aggregate,
            channel=channel,
            status=NotificationStatus.PENDING,
            payload=event.payload,
            created_at=now,
            updated_at=now,
            next_attempt_at=now,
        )

    @classmethod
    def rehydrate(
        cls,
        row: Any,
    ) -> "Notification":
        """
        Reconstruye la entidad desde una fila de la base de datos.

        Es el unico punto donde se aceptan datos de la infraestructura. A
        partir de aqui, el resto del codigo trabaja siempre con objetos validos
        gracias a las invariantes del constructor.
        """
        return cls(
            # `str()` porque el tipo de la columna cambia segun el motor: con
            # PostgreSQL llega un `uuid.UUID` y con SQLite una cadena. El dominio
            # no debe depender de esa diferencia.
            id=NotificationId(str(row.id)),
            event_id=row.event_id,
            event_type=row.event_type,
            aggregate=AggregateRef(type=row.aggregate_type, id=row.aggregate_id),
            channel=Channel.parse(row.channel),
            status=NotificationStatus(row.status),
            payload=dict(row.payload or {}),
            # `_as_utc` por el mismo motivo que el `str()` de arriba, y con la
            # misma consecuencia: sin esto, `is_due()` revienta al comparar
            # fechas de SQLite (ingenuas) con el reloj (con zona).
            created_at=_as_utc(row.created_at),
            updated_at=_as_utc(row.updated_at),
            attempts=row.attempts,
            last_error=row.last_error,
            sent_at=_as_utc(row.sent_at),
            next_attempt_at=_as_utc(row.next_attempt_at),
        )

    # ------------------------------------------------------------------ #
    # Lectura
    # ------------------------------------------------------------------ #
    @property
    def id(self) -> NotificationId:
        return self._id

    @property
    def event_id(self) -> str:
        return self._event_id

    @property
    def event_type(self) -> str:
        return self._event_type

    @property
    def aggregate(self) -> AggregateRef:
        return self._aggregate

    @property
    def channel(self) -> Channel:
        return self._channel

    @property
    def status(self) -> NotificationStatus:
        return self._status

    @property
    def payload(self) -> dict[str, Any]:
        return dict(self._payload)

    @property
    def attempts(self) -> int:
        return self._attempts

    @property
    def last_error(self) -> str | None:
        return self._last_error

    @property
    def created_at(self) -> datetime:
        return self._created_at

    @property
    def updated_at(self) -> datetime:
        return self._updated_at

    @property
    def sent_at(self) -> datetime | None:
        return self._sent_at

    @property
    def next_attempt_at(self) -> datetime | None:
        return self._next_attempt_at

    # ------------------------------------------------------------------ #
    # Reglas de negocio
    # ------------------------------------------------------------------ #
    def is_due(self, now: datetime) -> bool:
        """
        Indica si la notificacion debe intentarse ahora.

        Solo las notificaciones `PENDING` y `FAILED` son elegibles. `SENT` y
        `DEAD` son estados terminales: volver a tocarlas seria un bug.
        """
        if self._status not in (NotificationStatus.PENDING, NotificationStatus.FAILED):
            return False

        return self._next_attempt_at is None or self._next_attempt_at <= now

    def mark_attempted(self, now: datetime) -> None:
        """Registra que se va a intentar la entrega. No cambia el estado."""
        if self._status not in (NotificationStatus.PENDING, NotificationStatus.FAILED):
            raise ValueError(f"No se puede reintentar una notificacion en {self._status}")

        self._attempts += 1
        self._updated_at = now

    def mark_sent(self, now: datetime) -> None:
        """La entrega funciono. Estado terminal."""
        self._status = NotificationStatus.SENT
        self._sent_at = now
        self._updated_at = now
        self._last_error = None
        self._next_attempt_at = None

    def mark_failed(
        self,
        reason: str,
        now: datetime,
        max_attempts: int,
        backoff_seconds: int,
        backoff_cap_seconds: int,
    ) -> None:
        """
        La entrega fallo: decide si reintenta o si se rinde.

        Se separo de la entidad la decision de "cuando" reintentar (el calculo
        del backoff) porque es una politica, y las politicas viven fuera de la
        entidad. Aqui solo se aplica el resultado.
        """
        if self._status not in (NotificationStatus.PENDING, NotificationStatus.FAILED):
            raise ValueError(f"No se puede marcar como fallida una notificacion en {self._status}")

        self._last_error = reason[:500]
        self._updated_at = now

        if self._attempts >= max_attempts:
            # Agotados los intentos: a la cola de revision manual.
            self._status = NotificationStatus.DEAD
            self._next_attempt_at = None
            return

        self._status = NotificationStatus.FAILED

        # Backoff exponencial: 30s, 60s, 120s... hasta el tope configurado.
        # El minimo de 1s evita un bucle cerrado si base fuera 0.
        delay = max(1, min(backoff_cap_seconds, backoff_seconds * (2 ** (self._attempts - 1))))
        self._next_attempt_at = now + timedelta(seconds=delay)

    def envelope(self) -> dict[str, Any]:
        """Representacion estable del evento, util para log y webhook."""
        return {
            "event_id": self._event_id,
            "event_type": self._event_type,
            "aggregate_type": self._aggregate.type,
            "aggregate_id": self._aggregate.id,
            "channel": self._channel.value,
            "payload": self._payload,
        }

    def to_dict(self) -> dict[str, Any]:
        """Serializacion para la API de consulta."""
        return {
            "id": str(self._id),
            "event_id": self._event_id,
            "event_type": self._event_type,
            "aggregate_type": self._aggregate.type,
            "aggregate_id": self._aggregate.id,
            "channel": self._channel.value,
            "status": self._status.value,
            "attempts": self._attempts,
            "last_error": self._last_error,
            "created_at": self._created_at.isoformat(),
            "updated_at": self._updated_at.isoformat(),
            "sent_at": self._sent_at.isoformat() if self._sent_at else None,
            "next_attempt_at": self._next_attempt_at.isoformat() if self._next_attempt_at else None,
        }
