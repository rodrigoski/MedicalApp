"""
CAPA DE APLICACION: casos de uso del microservicio.

Orquesta el flujo completo:

    evento HTTP -> EventEnvelope (dominio) -> Notification (dominio)
                -> NotificationService (caso de uso) -> puertos (repositorio,
                   canal de salida, reloj)

Los "puertos" son `Protocol` de Python: la capa de aplicacion DECLARA lo que
necesita y la infraestructura lo IMPLEMENTA. Gracias a esa inversion de
dependencias, los casos de uso se prueban con dobles en memoria, sin base de
datos ni red.
"""

from app.application.dto import ProcessOutcome, ProcessSummary, Receipt
from app.application.ports import ChannelSender, ClockPort, NotificationRepositoryPort
from app.application.services import DeliverPendingService, ReceiveEventService

__all__ = [
    "ChannelSender",
    "ClockPort",
    "DeliverPendingService",
    "NotificationRepositoryPort",
    "ProcessOutcome",
    "ProcessSummary",
    "ReceiveEventService",
    "Receipt",
]
