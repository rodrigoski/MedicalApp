"""Canal HTTP saliente: entrega el evento a un sistema externo."""

from __future__ import annotations

import httpx

from app.domain.entities import Notification
from app.infrastructure.channels.base import ChannelSenderBase


class WebhookChannelSender(ChannelSenderBase):
    """
    Entrega el evento a una pasarela externa (SMS, correo, etc.).

    Decisiones de robustez:
      - timeout explicito: sin el, un destino colgado inmoviliza al worker y las
        notificaciones se acumulan sin entregarse.
      - `raise_for_status()`: cualquier 4xx o 5xx se convierte en excepcion y,
        por tanto, en reintento. Darse por entregado un 500 es como se pierden
        notificaciones sin que nadie lo note.
      - cliente reutilizado entre envios, para aprovechar keep-alive.
    """

    name = "webhook"

    def __init__(self, url: str, timeout: float = 4.0) -> None:
        if not url:
            raise ValueError("webhook_url es obligatorio cuando el canal es 'webhook'")

        self._url = url
        self._timeout = timeout
        self._client = httpx.Client(timeout=timeout)

    def _deliver(self, notification: Notification) -> None:
        try:
            response = self._client.post(self._url, json=notification.envelope())
            response.raise_for_status()
        except httpx.HTTPStatusError as exc:
            raise RuntimeError(
                f"El webhook respondio {exc.response.status_code}"
            ) from exc
        except httpx.HTTPError as exc:
            raise RuntimeError(f"Fallo de red al invocar el webhook: {exc}") from exc

    def close(self) -> None:
        self._client.close()
