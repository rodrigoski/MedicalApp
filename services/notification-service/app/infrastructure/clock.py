"""Reloj del sistema. Implementa el puerto `ClockPort` de la aplicacion."""

from __future__ import annotations

from datetime import UTC, datetime


class SystemClock:
    """
    Reloj real, en UTC.

    Se normaliza a UTC porque las comparaciones de "vencimiento" de un reintento
    tienen que hacerse en una sola zona; mezclar zonas produce bugs del tipo
    "se reintenta dos veces" o "nunca se reintenta".
    """

    def now(self) -> datetime:
        return datetime.now(UTC)
