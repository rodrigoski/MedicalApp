"""
Politicas del dominio.

Una politica encapsula una regla de negocio REUTILIZABLE y con identidad propia.
La diferencia con un metodo de entidad es que la politica puede consultar y
combinar datos de varias entidades sin ensuciar ninguna de ellas.
"""

from __future__ import annotations

from dataclasses import dataclass

from app.domain.value_objects import NotificationStatus


@dataclass(frozen=True, slots=True)
class RetryPolicy:
    """
    Politica de reintentos con backoff exponencial acotado.

    Se modela como un objeto de valor inmutable porque depende de la CONFIGURACION
    del despliegue (cuantos reintentos, cada cuanto) y porque asi es trivial
    probarla con valores extremos sin tocar la base de datos.

    La razon de usar backoff exponencial y no reintentos fijos: si el sistema
    externo (pasarela de SMS) esta caido, reintentar cada 5 segundos solo
    empeora la caida. El backoff deja que el servicio se recupere.
    """

    max_attempts: int = 5
    base_delay_seconds: int = 30
    cap_delay_seconds: int = 3600

    def __post_init__(self) -> None:
        if self.max_attempts < 1:
            raise ValueError("max_attempts debe ser al menos 1")
        if self.base_delay_seconds < 1:
            raise ValueError("base_delay_seconds debe ser al menos 1")
        if self.cap_delay_seconds < self.base_delay_seconds:
            raise ValueError("cap_delay_seconds no puede ser menor que base_delay_seconds")

    def delay_for(self, attempts: int) -> int:
        """
        Segundos a esperar antes del siguiente intento.

        `attempts` es el numero de intentos YA realizados. Con 1 intento
        fallido se espera `base`; con 2, `2 * base`; y asi sucesivamente hasta
        el tope. Nunca devuelve 0: un retraso nulo provocaria un bucle cerrado de
        reintentos si el destino siguiera caido.
        """
        if attempts < 1:
            raise ValueError("attempts debe ser al menos 1")

        exponent = attempts - 1
        # 2 ** exponent puede crecer mucho; se acota ANTES de multiplicar para
        # no desbordar un int en Python (que es de precision arbitraria, pero
        # el valor acabaria siendo enorme e inutil).
        if exponent > 32:
            return self.cap_delay_seconds

        return min(self.cap_delay_seconds, self.base_delay_seconds * (2**exponent))

    def should_retry(self, attempts: int) -> bool:
        """True si todavia quedan intentos disponibles."""
        return attempts < self.max_attempts

    def is_terminal(self, status: NotificationStatus) -> bool:
        """Los estados en los que la notificacion no se vuelve a tocar."""
        return status in (NotificationStatus.SENT, NotificationStatus.DEAD)
