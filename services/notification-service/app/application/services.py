"""
Casos de uso del microservicio de notificaciones.

Hay exactamente dos:

  1. `ReceiveEventService`  - acepta un evento del backend y lo registra como
     notificacion pendiente. Es idempotente.
  2. `DeliverPendingService` - toma las notificaciones vencidas y las entrega
     por el canal configurado, aplicando la politica de reintentos.

    Ninguno de los dos sabe de HTTP, de SQLAlchemy ni de FastAPI.
"""

from __future__ import annotations

import logging
from datetime import datetime

from app.application.dto import ProcessOutcome, ProcessSummary, Receipt
from app.application.ports import ChannelSender, ClockPort, NotificationRepositoryPort
from app.domain.entities import Notification
from app.domain.policies import RetryPolicy
from app.domain.value_objects import Channel, EventEnvelope, NotificationStatus

logger = logging.getLogger(__name__)


class ReceiveEventService:
    """
    Caso de uso: registrar el evento recibido como notificacion.

    REGLA CENTRAL DEL MICROSERVICIO: la idempotencia.

    El backend entrega con el patron outbox, que es "al menos una vez": si la
    respuesta HTTP se pierde, Laravel reintenta. Sin deduplicar por `event_id`,
    el mismo evento crearia varias notificaciones y el paciente recibiria SMS
    duplicados. Por eso se consulta primero por `event_id` y, si ya existe, se
    devuelve el acuse con `duplicate = true` y codigo 200 (no 409): para el
    productor el evento SI fue aceptado.
    """

    def __init__(
        self,
        repository: NotificationRepositoryPort,
        clock: ClockPort,
        default_channel: Channel,
    ) -> None:
        self._repository = repository
        self._clock = clock
        self._default_channel = default_channel

    def execute(self, event: EventEnvelope) -> Receipt:
        existing = self._repository.find_by_event_id(event.event_id)
        if existing is not None:
            logger.info(
                "notification.event.duplicate",
                extra={
                    "event_id": event.event_id,
                    "notification_id": str(existing.id),
                    "status": existing.status.value,
                },
            )
            return Receipt(
                notification_id=str(existing.id),
                event_id=event.event_id,
                duplicate=True,
            )

        now = self._clock.now()
        notification = Notification.from_event(
            event=event,
            channel=self._default_channel,
            now=now,
        )

        self._repository.add(notification)

        logger.info(
            "notification.accepted",
            extra={
                "event_id": event.event_id,
                "event_type": event.event_type,
                "notification_id": str(notification.id),
            },
        )

        return Receipt(
            notification_id=str(notification.id),
            event_id=event.event_id,
            duplicate=False,
        )


class DeliverPendingService:
    """
    Caso de uso: entregar las notificaciones vencidas.

    Ciclo de una iteracion por notificacion:

        due -> mark_attempted -> sender.send()
                                   |- ok    -> mark_sent
                                   |- error -> mark_failed (reintento o DEAD)

    SEMANTICA REAL DE LA PERSISTENCIA (importante para razonar sobre fallos):

    El repositorio hace `flush()`, nunca `commit()`: la transaccion la abre y la
    cierra quien invoco el caso de uso, de modo que un lote es atomico. Eso
    significa que:

      - Un fallo del CANAL queda registrado con normalidad. La excepcion se
        captura, `mark_failed` escribe el error y el backoff, y todo se confirma
        al cerrar la transaccion. El proximo ciclo respeta el retardo.

      - Un CRASH del proceso (una OOM, un `docker kill`, un despliegue) pierde
        el `attempts` y el `next_attempt_at` de las notificaciones del lote que
        aun no se habian confirmado. Esas notificaciones vuelven a `pending` con
        `attempts = 0` y se reintentan de inmediato, sin backoff.

    Consecuencia: la entrega es "al menos una vez", no "exactamente una vez", y
    un crash puede producir una notificacion repetida. Es la garantia correcta
    para este dominio: perder un aviso de cita es peor que enviarlo dos veces, y
    la deduplicacion fuerte exigiria un idempotency key compartido con el canal
    externo, que un webhook generico no ofrece.

    Lo que NO se hace aqui, y es deliberado, es confirmar por notificacion para
    "blindar" el crash. Haria falta ceder el control de la transaccion al caso de
    uso, y con el coste de que un fallo a mitad de lote dejara la cola en un
    estado parcial sin ninguna transaccion que lo describa. La garantia de
    atomicidad del lote se considera mas valiosa que eliminar un duplicado poco
    frecuente. Para esa garantia mas fuerte, el siguiente paso seria reclamar las
    notificaciones con `SELECT ... FOR UPDATE SKIP LOCKED` y un `leased_until`,
    de modo que un worker muerto las devuelva al circuito tras un tiempo.
    """

    def __init__(
        self,
        repository: NotificationRepositoryPort,
        sender: ChannelSender,
        clock: ClockPort,
        retry_policy: RetryPolicy,
        batch_size: int = 25,
    ) -> None:
        self._repository = repository
        self._sender = sender
        self._clock = clock
        self._retry_policy = retry_policy
        self._batch_size = batch_size

    def execute(self) -> ProcessSummary:
        now = self._clock.now()
        batch = self._repository.due(now, self._batch_size)

        sent = 0
        retry = 0
        exhausted = 0
        skipped = 0

        for notification in batch:
            outcome = self._process_one(notification, now)

            if outcome is ProcessOutcome.SENT:
                sent += 1
            elif outcome is ProcessOutcome.RETRY_SCHEDULED:
                retry += 1
            elif outcome is ProcessOutcome.EXHAUSTED:
                exhausted += 1
            else:
                skipped += 1

        summary = ProcessSummary(
            processed=len(batch),
            sent=sent,
            retry_scheduled=retry,
            exhausted=exhausted,
            skipped=skipped,
        )

        if summary.processed:
            logger.info("notification.batch.processed", extra=summary.to_dict())

        return summary

    def _process_one(self, notification: Notification, now: datetime) -> ProcessOutcome:
        if not notification.is_due(now):
            # Otro worker pudo tomarla entre la consulta y ahora: no es un error,
            # simplemente no es el turno de este proceso.
            return ProcessOutcome.SKIPPED

        notification.mark_attempted(now)
        self._repository.update(notification)

        try:
            self._sender.send(notification)
        except Exception as exc:  # noqa: BLE001 - fallo de infraestructura, no de codigo
            reason = f"{type(exc).__name__}: {exc}"

            notification.mark_failed(
                reason=reason,
                now=now,
                max_attempts=self._retry_policy.max_attempts,
                backoff_seconds=self._retry_policy.base_delay_seconds,
                backoff_cap_seconds=self._retry_policy.cap_delay_seconds,
            )
            self._repository.update(notification)

            outcome = (
                ProcessOutcome.EXHAUSTED
                if notification.status is NotificationStatus.DEAD
                else ProcessOutcome.RETRY_SCHEDULED
            )

            logger.warning(
                "notification.delivery.failed",
                extra={
                    "event_id": notification.event_id,
                    "notification_id": str(notification.id),
                    "channel": notification.channel.value,
                    "attempts": notification.attempts,
                    "outcome": outcome.value,
                    "error": reason,
                },
            )

            return outcome

        notification.mark_sent(now)
        self._repository.update(notification)

        logger.info(
            "notification.delivery.sent",
            extra={
                "event_id": notification.event_id,
                "notification_id": str(notification.id),
                "channel": notification.channel.value,
                "attempts": notification.attempts,
            },
        )

        return ProcessOutcome.SENT
