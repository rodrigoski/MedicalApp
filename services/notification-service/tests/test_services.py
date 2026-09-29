"""
Pruebas de los casos de uso con dobles en memoria.

Aqui se verifica el comportamiento que NO se ve en los endpoints:

  - la idempotencia (un evento reenviado no duplica nada),
  - la politica de reintentos aplicada de verdad, con su backoff,
  - que un fallo del canal nunca propague la excepcion hacia el llamador.
"""

from __future__ import annotations

from datetime import UTC, datetime

import pytest

from app.application.services import DeliverPendingService, ReceiveEventService
from app.domain.entities import Notification
from app.domain.exceptions import DuplicateEvent
from app.domain.policies import RetryPolicy
from app.domain.value_objects import AggregateRef, Channel, EventEnvelope, NotificationStatus
from tests.fakes import FakeClock, InMemoryNotificationRepository, RecordingSender

AHORA = datetime(2025, 1, 7, 10, 0, tzinfo=UTC)
EVENT_ID = "6f1d2c3a-9b8e-4f21-8a7b-0c1d2e3f4a5b"


def envelope(event_id: str = EVENT_ID) -> EventEnvelope:
    return EventEnvelope(
        event_id=event_id,
        event_type="appointment.created",
        aggregate=AggregateRef(type="appointment", id=812),
        occurred_at="2025-01-07T15:00:00+00:00",
        payload={"patient_id": 44},
    )


class TestReceiveEventService:
    def test_registra_un_evento_nuevo_como_pendiente(self) -> None:
        repository = InMemoryNotificationRepository()
        service = ReceiveEventService(repository, FakeClock(AHORA), Channel.LOG)

        receipt = service.execute(envelope())

        assert receipt.duplicate is False
        assert repository.only().status is NotificationStatus.PENDING
        assert repository.only().event_id == EVENT_ID

    def test_un_evento_reenviado_devuelve_el_mismo_acuse(self) -> None:
        """
        Este es el test mas importante del microservicio.

        La outbox de Laravel entrega "al menos una vez": si se pierde la
        respuesta, reintenta. Sin idempotencia, el paciente recibiria el mismo
        aviso dos veces.
        """
        repository = InMemoryNotificationRepository()
        service = ReceiveEventService(repository, FakeClock(AHORA), Channel.LOG)

        primero = service.execute(envelope())
        segundo = service.execute(envelope())

        assert segundo.duplicate is True
        assert segundo.notification_id == primero.notification_id
        assert len(repository.all()) == 1

    def test_eventos_distintos_crean_notificaciones_distintas(self) -> None:
        repository = InMemoryNotificationRepository()
        service = ReceiveEventService(repository, FakeClock(AHORA), Channel.LOG)

        primero = service.execute(envelope())
        segundo = service.execute(
            envelope(event_id="7a2b3c4d-1c2d-4e3f-9a8b-7c6d5e4f3a2b")
        )

        assert primero.notification_id != segundo.notification_id
        assert len(repository.all()) == 2

    def test_una_carrera_de_insercion_se_traduce_a_duplicado(self) -> None:
        """
        Reproduce la carrera que el indice UNIQUE de la base de datos detiene.

        Dos workers que pasan a la vez la comprobacion previa intentarian
        insertar el mismo `event_id`. La garantia final no es el codigo del caso
        de uso, es la restriccion del almacen: por eso se prueba aqui el
        repositorio, no el servicio.
        """
        repository = InMemoryNotificationRepository()
        clock = FakeClock(AHORA)
        receive = ReceiveEventService(repository, clock, Channel.LOG)
        receive.execute(envelope())

        # Segunda escritura sin pasar por la consulta previa: el almacen la
        # rechaza igual, y en PostgreSQL lo haria el indice unico.
        segundo_intento = Notification.from_event(envelope(), Channel.LOG, AHORA)

        with pytest.raises(DuplicateEvent):
            repository.add(segundo_intento)

        assert len(repository.all()) == 1


class TestDeliverPendingService:
    def _service(
        self,
        repository: InMemoryNotificationRepository,
        sender: RecordingSender,
        clock: FakeClock,
        max_attempts: int = 3,
        batch_size: int = 25,
    ) -> DeliverPendingService:
        return DeliverPendingService(
            repository=repository,
            sender=sender,
            clock=clock,
            retry_policy=RetryPolicy(
                max_attempts=max_attempts, base_delay_seconds=30, cap_delay_seconds=600
            ),
            batch_size=batch_size,
        )

    def _encolar(self, repository: InMemoryNotificationRepository, clock: FakeClock) -> None:
        ReceiveEventService(repository, clock, Channel.LOG).execute(envelope())

    def test_una_notificacion_pendiente_se_envia(self) -> None:
        clock = FakeClock(AHORA)
        repository = InMemoryNotificationRepository()
        sender = RecordingSender()
        self._encolar(repository, clock)

        summary = self._service(repository, sender, clock).execute()

        assert summary.to_dict() == {
            "processed": 1,
            "sent": 1,
            "retry_scheduled": 0,
            "exhausted": 0,
            "skipped": 0,
        }
        assert sender.sent == [EVENT_ID]
        assert repository.only().status is NotificationStatus.SENT

    def test_un_canal_caido_no_rompe_el_procesador(self) -> None:
        """
        Excepcion del canal -> reintento programado, nunca propagacion.

        Si la excepcion del canal escapara, un webhook caido detendria el
        servicio entero y las notificaciones de los demas eventos dejarian de
        procesarse.
        """
        clock = FakeClock(AHORA)
        repository = InMemoryNotificationRepository()
        sender = RecordingSender(fail_times=1)
        self._encolar(repository, clock)

        summary = self._service(repository, sender, clock).execute()

        assert summary.retry_scheduled == 1
        assert summary.sent == 0
        assert repository.only().status is NotificationStatus.FAILED
        assert "fallo programado" in (repository.only().last_error or "")

    def test_reintenta_cuando_llega_el_momento(self) -> None:
        clock = FakeClock(AHORA)
        repository = InMemoryNotificationRepository()
        sender = RecordingSender(fail_times=1)
        self._encolar(repository, clock)
        service = self._service(repository, sender, clock)

        service.execute()
        assert repository.only().attempts == 1

        # Todavia no toca reintentar: el backoff son 30 s.
        clock.advance(29)
        assert service.execute().processed == 0

        clock.advance(1)
        summary = service.execute()

        assert summary.sent == 1
        assert repository.only().status is NotificationStatus.SENT
        assert repository.only().attempts == 2

    def test_agotar_los_intentos_deja_la_notificacion_muerta(self) -> None:
        clock = FakeClock(AHORA)
        repository = InMemoryNotificationRepository()
        sender = RecordingSender(fail_times=99)
        self._encolar(repository, clock)
        service = self._service(repository, sender, clock, max_attempts=2)

        first = service.execute()
        clock.advance(60)
        second = service.execute()

        assert first.retry_scheduled == 1
        assert second.exhausted == 1
        assert repository.only().status is NotificationStatus.DEAD
        assert repository.only().next_attempt_at is None

    def test_una_notificacion_muerta_no_se_vuelve_a_tocar(self) -> None:
        clock = FakeClock(AHORA)
        repository = InMemoryNotificationRepository()
        sender = RecordingSender(fail_times=99)
        self._encolar(repository, clock)
        service = self._service(repository, sender, clock, max_attempts=1)

        service.execute()
        clock.advance(86_400)
        resumen = service.execute()

        assert resumen.processed == 0

    def test_lote_acotado_para_no_ahogar_la_cola(self) -> None:
        clock = FakeClock(AHORA)
        repository = InMemoryNotificationRepository()
        sender = RecordingSender()
        receive = ReceiveEventService(repository, clock, Channel.LOG)

        for i in range(5):
            receive.execute(
                EventEnvelope(
                    event_id=f"0000000{i}-1c2d-4e3f-9a8b-7c6d5e4f3a2b",
                    event_type="appointment.created",
                    aggregate=AggregateRef(type="appointment", id=i + 1),
                    occurred_at="2025-01-07T15:00:00+00:00",
                    payload={},
                )
            )

        summary = self._service(repository, sender, clock, batch_size=2).execute()

        assert summary.processed == 2
        assert len(sender.sent) == 2
        assert len(repository.all()) == 5  # el resto sigue en cola

    def test_no_hay_una_tercera_parte_que_vea_el_error_del_canal(self) -> None:
        """El resultado es un resumen, nunca una excepcion del canal."""
        clock = FakeClock(AHORA)
        repository = InMemoryNotificationRepository()
        sender = RecordingSender(fail_times=1)
        self._encolar(repository, clock)

        summary = self._service(repository, sender, clock).execute()

        assert isinstance(summary.processed, int)
        assert summary.processed == 1
