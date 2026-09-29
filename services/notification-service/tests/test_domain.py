"""
Pruebas del dominio: value objects, entidad y politica de reintentos.

No usan FastAPI, ni base de datos, ni red. Son las pruebas MAS RAPIDAS del
proyecto y las que detectan un error de regla antes de que llegue a la API.
"""

from __future__ import annotations

from datetime import UTC, datetime, timedelta

import pytest

from app.domain.entities import Notification
from app.domain.exceptions import InvalidNotificationId, UnknownChannel
from app.domain.policies import RetryPolicy
from app.domain.value_objects import (
    AggregateRef,
    Channel,
    EventEnvelope,
    NotificationId,
    NotificationStatus,
)

AHORA = datetime(2025, 1, 7, 10, 0, tzinfo=UTC)


def envelope(**overrides) -> EventEnvelope:  # type: ignore[no-untyped-def]
    data = {
        "event_id": "6f1d2c3a-9b8e-4f21-8a7b-0c1d2e3f4a5b",
        "event_type": "appointment.created",
        "aggregate": AggregateRef(type="appointment", id=812),
        "occurred_at": "2025-01-07T15:00:00+00:00",
        "payload": {"patient_id": 44},
    }
    data.update(overrides)

    return EventEnvelope(**data)


# --------------------------------------------------------------------------- #
# Value objects
# --------------------------------------------------------------------------- #
class TestNotificationId:
    def test_genera_un_uuid_valido(self) -> None:
        notification_id = NotificationId.generate()

        assert str(notification_id)
        assert NotificationId(str(notification_id)) == notification_id

    def test_normaliza_a_minusculas(self) -> None:
        mayusculas = "6F1D2C3A-9B8E-4F21-8A7B-0C1D2E3F4A5B"

        assert NotificationId(mayusculas).value == mayusculas.lower()

    @pytest.mark.parametrize(
        "valor",
        ["no-es-uuid", "12345", "", "6f1d2c3a9b8e4f218a7b0c1d2e3f4a5b"],
    )
    def test_rechaza_identificadores_invalidos(self, valor: str) -> None:
        with pytest.raises(InvalidNotificationId):
            NotificationId(valor)


class TestAggregateRef:
    def test_exige_un_tipo_no_vacio(self) -> None:
        with pytest.raises(ValueError, match="tipo de agregado"):
            AggregateRef(type="  ", id=1)

    def test_exige_un_identificador_positivo(self) -> None:
        with pytest.raises(ValueError, match="entero positivo"):
            AggregateRef(type="appointment", id=0)


class TestEventEnvelope:
    def test_exige_event_id_para_poder_garantizar_idempotencia(self) -> None:
        with pytest.raises(ValueError, match="idempotencia"):
            envelope(event_id="")

    def test_exige_un_payload_objeto(self) -> None:
        with pytest.raises(ValueError, match="payload"):
            envelope(payload=["no", "es", "objeto"])


class TestChannel:
    def test_parsea_sin_importar_mayusculas(self) -> None:
        assert Channel.parse("WEBHOOK") is Channel.WEBHOOK

    def test_rechaza_un_canal_desconocido(self) -> None:
        with pytest.raises(UnknownChannel):
            Channel.parse("telegram")


# --------------------------------------------------------------------------- #
# Entidad
# --------------------------------------------------------------------------- #
class TestNotification:
    def test_nace_pendiente_y_con_intento_inmediato(self) -> None:
        notification = Notification.from_event(envelope(), Channel.LOG, AHORA)

        assert notification.status is NotificationStatus.PENDING
        assert notification.attempts == 0
        assert notification.is_due(AHORA)
        assert notification.next_attempt_at == AHORA

    def test_una_notificacion_enviada_no_vuelve_a_la_cola(self) -> None:
        notification = Notification.from_event(envelope(), Channel.LOG, AHORA)
        notification.mark_attempted(AHORA)
        notification.mark_sent(AHORA)

        assert notification.status is NotificationStatus.SENT
        assert notification.sent_at == AHORA
        assert notification.last_error is None
        assert not notification.is_due(AHORA + timedelta(days=1))

    def test_un_fallo_programa_el_reejuste_con_backoff(self) -> None:
        notification = Notification.from_event(envelope(), Channel.LOG, AHORA)
        notification.mark_attempted(AHORA)
        notification.mark_failed(
            reason="timeout",
            now=AHORA,
            max_attempts=5,
            backoff_seconds=30,
            backoff_cap_seconds=3600,
        )

        assert notification.status is NotificationStatus.FAILED
        assert notification.attempts == 1
        assert notification.last_error == "timeout"
        # 30 s * 2^(1-1) = 30 s
        assert notification.next_attempt_at == AHORA + timedelta(seconds=30)
        assert not notification.is_due(AHORA + timedelta(seconds=29))
        assert notification.is_due(AHORA + timedelta(seconds=30))

    def test_al_agotar_los_intentos_pasa_a_dead(self) -> None:
        notification = Notification.from_event(envelope(), Channel.LOG, AHORA)

        notification.mark_attempted(AHORA)
        notification.mark_failed(
            reason="1", now=AHORA, max_attempts=1, backoff_seconds=30, backoff_cap_seconds=600
        )

        assert notification.status is NotificationStatus.DEAD
        assert notification.next_attempt_at is None
        assert not notification.is_due(AHORA + timedelta(days=30))

    def test_no_se_puede_reintentar_una_notificacion_enviada(self) -> None:
        notification = Notification.from_event(envelope(), Channel.LOG, AHORA)
        notification.mark_sent(AHORA)

        with pytest.raises(ValueError, match="No se puede reintentar"):
            notification.mark_attempted(AHORA)

    def test_trunca_el_error_para_no_desbordar_la_columna(self) -> None:
        notification = Notification.from_event(envelope(), Channel.LOG, AHORA)
        notification.mark_attempted(AHORA)
        notification.mark_failed(
            reason="x" * 900,
            now=AHORA,
            max_attempts=5,
            backoff_seconds=30,
            backoff_cap_seconds=600,
        )

        assert len(notification.last_error or "") == 500


# --------------------------------------------------------------------------- #
# Politica de reintentos
# --------------------------------------------------------------------------- #
class TestRetryPolicy:
    def test_el_backoff_crece_de_forma_exponencial(self) -> None:
        policy = RetryPolicy(max_attempts=6, base_delay_seconds=30, cap_delay_seconds=3600)

        assert policy.delay_for(1) == 30
        assert policy.delay_for(2) == 60
        assert policy.delay_for(3) == 120
        assert policy.delay_for(4) == 240

    def test_el_backoff_nunca_supera_el_tope(self) -> None:
        policy = RetryPolicy(max_attempts=100, base_delay_seconds=30, cap_delay_seconds=600)

        assert policy.delay_for(50) == 600
        assert policy.delay_for(1000) == 600

    def test_los_estados_terminales_no_se_reintentan(self) -> None:
        policy = RetryPolicy()

        assert policy.is_terminal(NotificationStatus.SENT)
        assert policy.is_terminal(NotificationStatus.DEAD)
        assert not policy.is_terminal(NotificationStatus.PENDING)
        assert not policy.is_terminal(NotificationStatus.FAILED)

    def test_rechaza_configuraciones_absurdas(self) -> None:
        with pytest.raises(ValueError, match="max_attempts"):
            RetryPolicy(max_attempts=0)

        with pytest.raises(ValueError, match="cap_delay_seconds"):
            RetryPolicy(base_delay_seconds=100, cap_delay_seconds=10)
