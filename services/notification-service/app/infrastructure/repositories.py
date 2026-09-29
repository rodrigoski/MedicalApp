"""
Repositorio SQLAlchemy.

Traduce entre filas y entidades de dominio. Es la UNICA pieza que conoce las
dos representaciones, lo que evita que los modelos de SQLAlchemy se filtren al
dominio (donde no tienen nada que ver) o que la entidad se persista directamente
(acoplando el dominio al esquema SQL).

Detalle importante en `due()`: el filtro de estados y de vencimiento se hace en
SQL, no en Python, para no traer a memoria notificaciones que no tocan su turno.
"""

from __future__ import annotations

import logging
import uuid
from datetime import datetime

from sqlalchemy import func, select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.domain.entities import Notification
from app.domain.exceptions import DuplicateEvent
from app.domain.value_objects import NotificationId, NotificationStatus
from app.infrastructure.models import NotificationModel

logger = logging.getLogger(__name__)


class SqlAlchemyNotificationRepository:
    """Implementacion del puerto `NotificationRepositoryPort` sobre SQLAlchemy."""

    def __init__(self, session: Session) -> None:
        self._session = session

    # ------------------------------------------------------------------ #
    # Lecturas
    # ------------------------------------------------------------------ #
    def find_by_event_id(self, event_id: str) -> Notification | None:
        row = self._session.execute(
            select(NotificationModel).where(NotificationModel.event_id == event_id)
        ).scalar_one_or_none()

        return Notification.rehydrate(row) if row is not None else None

    def find_by_id(self, notification_id: NotificationId) -> Notification | None:
        row = self._session.get(NotificationModel, self._to_pk(notification_id))

        return Notification.rehydrate(row) if row is not None else None

    def due(self, now: datetime, limit: int) -> list[Notification]:
        """
        Notificaciones pendientes o fallidas cuyo reintento ya vencio.

        El `ORDER BY next_attempt_at` garantiza que las mas antiguas se atiendan
        primero: si el backlog es mas grande que `limit`, lo importante es no
        starvation de las mas antiguas.
        """
        if limit < 1:
            return []

        rows = self._session.execute(
            select(NotificationModel)
            .where(
                NotificationModel.status.in_(
                    [NotificationStatus.PENDING.value, NotificationStatus.FAILED.value]
                ),
                (NotificationModel.next_attempt_at.is_(None))
                | (NotificationModel.next_attempt_at <= now),
            )
            .order_by(NotificationModel.next_attempt_at.asc().nullsfirst())
            .limit(limit)
        ).scalars()

        return [Notification.rehydrate(row) for row in rows]

    def count_by_status(self) -> dict[str, int]:
        """Metricas de salud: cuantas notificaciones hay en cada estado."""
        rows = self._session.execute(
            select(NotificationModel.status, func.count(NotificationModel.id))
            .group_by(NotificationModel.status)
        ).all()

        counts = {status.value: 0 for status in NotificationStatus}
        for status, total in rows:
            counts[status] = int(total)

        return counts

    def list_by_status(
        self,
        status: NotificationStatus | None,
        limit: int,
    ) -> list[Notification]:
        """Listado acotado para observabilidad."""
        if limit < 1:
            return []

        query = select(NotificationModel)
        if status is not None:
            query = query.where(NotificationModel.status == status.value)

        rows = self._session.execute(
            query.order_by(NotificationModel.created_at.desc()).limit(limit)
        ).scalars()

        return [Notification.rehydrate(row) for row in rows]

    # ------------------------------------------------------------------ #
    # Escrituras
    # ------------------------------------------------------------------ #
    def add(self, notification: Notification) -> None:
        row = self._to_row(notification)
        self._session.add(row)

        try:
            # flush() y no commit(): la transaccion la cierra quien la abrio. Asi
            # el caso de uso no controla la base de datos y el repositorio no
            # decide cuando se confirma el trabajo.
            self._session.flush()
        except IntegrityError as exc:
            # Carrera real: otro worker inserto el mismo event_id entre nuestro
            # SELECT y este INSERT. La unicidad del indice lo detecta.
            raise DuplicateEvent(
                f"Ya existe una notificacion para el evento {notification.event_id}"
            ) from exc

    def update(self, notification: Notification) -> None:
        row = self._to_row(notification)

        existing = self._session.get(NotificationModel, row.id)
        if existing is None:
            # No se inventa el registro: si desaparecio, es un problema de
            # consistencia que debe verse en el log, no un UPDATE silencioso.
            logger.error(
                "notification.update.missing_row",
                extra={
                    "notification_id": str(notification.id),
                    "event_id": notification.event_id,
                },
            )
            return

        for field in (
            "channel",
            "status",
            "payload",
            "attempts",
            "last_error",
            "updated_at",
            "sent_at",
            "next_attempt_at",
        ):
            setattr(existing, field, getattr(row, field))

        self._session.flush()

    # ------------------------------------------------------------------ #
    # Traduccion entidad <-> fila
    # ------------------------------------------------------------------ #
    @staticmethod
    def _to_pk(notification_id: NotificationId) -> str:
        return notification_id.value

    @staticmethod
    def _to_row(notification: Notification) -> NotificationModel:
        return NotificationModel(
            id=uuid.UUID(str(notification.id)),
            event_id=notification.event_id,
            event_type=notification.event_type,
            aggregate_type=notification.aggregate.type,
            aggregate_id=notification.aggregate.id,
            channel=notification.channel.value,
            status=notification.status.value,
            payload=notification.payload,
            attempts=notification.attempts,
            last_error=notification.last_error,
            created_at=notification.created_at,
            updated_at=notification.updated_at,
            sent_at=notification.sent_at,
            next_attempt_at=notification.next_attempt_at,
        )
