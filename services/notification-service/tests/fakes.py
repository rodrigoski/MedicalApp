"""
Dobles en memoria para las pruebas.

Un `FakeRepository` implementa el puerto `NotificationRepositoryPort` con un
diccionario. Permite probar los casos de uso sin base de datos, sin red y sin
sleeps: cada prueba tarda milisegundos y el resultado es determinista.
"""

from __future__ import annotations

from datetime import datetime, timedelta

from app.domain.entities import Notification
from app.domain.value_objects import NotificationId, NotificationStatus


class FakeClock:
    """Reloj fijo. `advance()` simula el paso del tiempo sin esperar."""

    def __init__(self, start: datetime) -> None:
        self._now = start

    def now(self) -> datetime:
        return self._now

    def advance(self, seconds: int) -> None:
        self._now = self._now + timedelta(seconds=seconds)


class InMemoryNotificationRepository:
    """
    Implementacion en memoria del puerto de repositorio.

    Reproduce tambien la restriccion de unicidad de `event_id`, que es la
    garantia clave del servicio. Sin ella, una prueba de idempotencia pasaria
    con un doble que no se parece al almacen real y el bug volveria a produccion.
    """

    def __init__(self) -> None:
        self._rows: dict[str, Notification] = {}
        self.duplicates_rejected: list[str] = []

    # --- escrituras ---
    def add(self, notification: Notification) -> None:
        from app.domain.exceptions import DuplicateEvent

        if notification.event_id in self._rows:
            self.duplicates_rejected.append(notification.event_id)
            raise DuplicateEvent(
                f"Ya existe una notificacion para el evento {notification.event_id}"
            )

        self._rows[notification.event_id] = notification

    def update(self, notification: Notification) -> None:
        if notification.event_id not in self._rows:
            return
        # Copia defensiva: el caso de uso sigue trabajando con la misma entidad,
        # pero guardamos el estado actual para simular la persistencia.
        self._rows[notification.event_id] = notification

    # --- lecturas ---
    def find_by_event_id(self, event_id: str) -> Notification | None:
        return self._rows.get(event_id)

    def find_by_id(self, notification_id: NotificationId) -> Notification | None:
        for notification in self._rows.values():
            if str(notification.id) == notification_id.value:
                return notification
        return None

    def due(self, now: datetime, limit: int) -> list[Notification]:
        candidates = [
            notification
            for notification in self._rows.values()
            if notification.is_due(now)
        ]
        candidates.sort(key=lambda n: n.next_attempt_at or now)

        return candidates[:limit]

    def list_by_status(
        self, status: NotificationStatus | None, limit: int
    ) -> list[Notification]:
        rows = list(self._rows.values())
        if status is not None:
            rows = [row for row in rows if row.status is status]
        rows.sort(key=lambda n: n.created_at, reverse=True)

        return rows[:limit]

    def count_by_status(self) -> dict[str, int]:
        counts = {status.value: 0 for status in NotificationStatus}
        for notification in self._rows.values():
            counts[notification.status.value] += 1
        return counts

    # --- utilidades de prueba ---
    def all(self) -> list[Notification]:
        return list(self._rows.values())

    def only(self) -> Notification:
        assert len(self._rows) == 1, f"Se esperaba 1 notificacion, hay {len(self._rows)}"
        return next(iter(self._rows.values()))


class RecordingSender:
    """
    Canal que registra los envios y puede fallar de forma programada.

    `fail_times` hace que las primeras N entregas fallen, lo que permite probar
    la politica de reintentos sin tocar la red ni esperar el backoff real.
    """

    def __init__(self, name: str = "recording", fail_times: int = 0) -> None:
        self.name = name
        self.fail_times = fail_times
        self.sent: list[str] = []

    def send(self, notification: Notification) -> None:
        if self.fail_times > 0:
            self.fail_times -= 1
            raise RuntimeError("fallo programado por la prueba")

        self.sent.append(notification.event_id)
