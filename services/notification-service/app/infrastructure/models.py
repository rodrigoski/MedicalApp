"""
Modelo de persistencia (SQLAlchemy) de las notificaciones.

Esquema de la tabla `notifications`:

    id                uuid  PK
    event_id          text  UNIQUE NOT NULL   <- clave de idempotencia
    event_type        text  NOT NULL
    aggregate_type    text  NOT NULL
    aggregate_id      int   NOT NULL
    channel           text  NOT NULL
    status            text  NOT NULL
    payload           json  NOT NULL
    attempts          int   NOT NULL default 0
    last_error        text  NULL
    created_at        ts    NOT NULL
    updated_at        ts    NOT NULL
    sent_at           ts    NULL
    next_attempt_at   ts    NULL

Por que `event_id` es UNIQUE y no solo un indice: la unicidad la impone el motor
de base de datos, que es el unico lugar donde puede garantizarse sin carreras.
Comprobar "si existe" en Python y luego insertar deja una ventana entre el SELECT
y el INSERT por la que dos workers simultaneos crearian dos notificaciones. El
indice unico convierte esa carrera en un error de integridad, que el repositorio
traduce a "duplicado".
"""

from __future__ import annotations

import uuid
from datetime import datetime
from typing import Any

from sqlalchemy import (
    CHAR,
    JSON,
    CheckConstraint,
    DateTime,
    Index,
    Integer,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.dialects.postgresql import JSONB, UUID
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column


class Base(DeclarativeBase):
    """Base declarativa comun a todos los modelos."""


# Tipos portables entre PostgreSQL (produccion) y SQLite (pruebas).
# `.with_variant()` hace que PostgreSQL use JSONB (consulta e indexacion mas
# rapidas) y que SQLite use JSON plano, sin cambiar una linea del modelo.
JSON_TYPE = JSON().with_variant(JSONB(), "postgresql")
UUID_TYPE = CHAR(36).with_variant(UUID(as_uuid=True), "postgresql")


class NotificationModel(Base):
    """Fila persistida de una notificacion."""

    __tablename__ = "notifications"

    id: Mapped[uuid.UUID | str] = mapped_column(UUID_TYPE, primary_key=True, default=uuid.uuid4)
    event_id: Mapped[str] = mapped_column(String(120), nullable=False)
    event_type: Mapped[str] = mapped_column(String(80), nullable=False)
    aggregate_type: Mapped[str] = mapped_column(String(40), nullable=False)
    aggregate_id: Mapped[int] = mapped_column(Integer, nullable=False)
    channel: Mapped[str] = mapped_column(String(20), nullable=False)
    status: Mapped[str] = mapped_column(String(20), nullable=False)
    payload: Mapped[dict[str, Any]] = mapped_column(JSON_TYPE, nullable=False, default=dict)

    attempts: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    last_error: Mapped[str | None] = mapped_column(Text, nullable=True)

    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False)
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False)
    sent_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    next_attempt_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )

    __table_args__ = (
        # Idempotencia garantizada por el motor, no por el codigo de aplicacion.
        UniqueConstraint("event_id", name="notifications_event_id_unique"),
        CheckConstraint("attempts >= 0", name="notifications_attempts_non_negative"),
        CheckConstraint(
            "status IN ('pending', 'sent', 'failed', 'dead')",
            name="notifications_status_valid",
        ),
        # Indice de trabajo del procesador: busca por estado y por vencimiento.
        # Es el indice que evita un seq scan en la consulta `due()`.
        Index("notifications_due_idx", "status", "next_attempt_at"),
        Index("notifications_aggregate_idx", "aggregate_type", "aggregate_id"),
    )
