"""
Esquemas de entrada y salida (Pydantic v2).

Son el CONTRATO PUBLICO del microservicio. La validacion de forma ocurre aqui;
la validacion de significado (que el evento tenga sentido clinico) ocurre en el
dominio. Separar ambas cosas produce mensajes de error mucho mas utiles: "falta
un campo" frente a "un evento de cita debe traer identificador de paciente".
"""

from __future__ import annotations

from typing import Any

from pydantic import BaseModel, ConfigDict, Field

from app.domain.value_objects import AggregateRef, EventEnvelope


class EventRequest(BaseModel):
    """
    Evento de dominio enviado por el backend Laravel.

    Refleja exactamente lo que produce `DomainEvent::toArray()`:
    `event_id`, `event_type`, `aggregate_type`, `aggregate_id`, `occurred_at`
    y `payload`.
    """

    model_config = ConfigDict(
        extra="ignore",
        json_schema_extra={
            "example": {
                "event_id": "6f1d2c3a-9b8e-4f21-8a7b-0c1d2e3f4a5b",
                "event_type": "appointment.created",
                "aggregate_type": "appointment",
                "aggregate_id": 812,
                "occurred_at": "2025-01-07T15:00:00+00:00",
                "payload": {"patient_id": 44, "starts_at": "2025-01-08T13:00:00Z"},
            }
        },
    )

    event_id: str = Field(min_length=1, max_length=120)
    event_type: str = Field(min_length=1, max_length=80)
    aggregate_type: str = Field(min_length=1, max_length=40)
    aggregate_id: int = Field(gt=0)
    occurred_at: str
    payload: dict[str, Any] = Field(default_factory=dict)

    def to_envelope(self, request_id: str | None = None) -> EventEnvelope:
        """Traduce el esquema HTTP al value object de dominio."""
        return EventEnvelope(
            event_id=self.event_id,
            event_type=self.event_type,
            aggregate=AggregateRef(type=self.aggregate_type, id=self.aggregate_id),
            occurred_at=self.occurred_at,
            payload=self.payload,
            request_id=request_id,
        )


class ReceiptResponse(BaseModel):
    """Respuesta de `POST /api/v1/events`."""

    notification_id: str
    event_id: str
    duplicate: bool = Field(
        description=(
            "True si el evento ya estaba registrado. NO es un error: es la "
            "idempotencia del servicio ante reintentos de la outbox."
        )
    )


class NotificationResponse(BaseModel):
    """Estado de una notificacion (consulta)."""

    id: str
    event_id: str
    event_type: str
    aggregate_type: str
    aggregate_id: int
    channel: str
    status: str
    attempts: int
    last_error: str | None
    created_at: str
    updated_at: str
    sent_at: str | None
    next_attempt_at: str | None


class ProcessResponse(BaseModel):
    """Resultado de forzar una pasada del procesador."""

    processed: int
    sent: int
    retry_scheduled: int
    exhausted: int
    skipped: int


class HealthResponse(BaseModel):
    """Estado del servicio y de sus dependencias."""

    status: str
    service: str
    version: str
    environment: str
    checks: dict[str, str] = Field(default_factory=dict)
    notifications: dict[str, int] = Field(default_factory=dict)


class ErrorBody(BaseModel):
    """Cuerpo de error, con la misma forma que usa el backend Laravel."""

    code: str
    message: str
    status: int
    context: dict[str, Any] = Field(default_factory=dict)
    request_id: str | None = None


class ErrorResponse(BaseModel):
    """Envoltorio de error, identico al contrato del backend."""

    error: ErrorBody
