"""Excepciones del dominio.

Se distinguen de los errores de infraestructura porque el dominio no sabe que
existe una base de datos ni un canal HTTP: solo expresa "esto no es valido" o
"esto ya estaba hecho".
"""

from __future__ import annotations


class NotificationDomainError(Exception):
    """Base de todos los errores del dominio."""


class InvalidEventPayload(NotificationDomainError):
    """El evento recibido no cumple el contrato minimo para ser procesado."""


class InvalidNotificationId(NotificationDomainError):
    """El identificador de notificacion no tiene un formato valido."""


class UnknownChannel(NotificationDomainError):
    """Se pidio entregar por un canal que este servicio no conoce."""


class DuplicateEvent(NotificationDomainError):
    """
    El evento ya fue procesado.

    NO es un error desde el punto de vista de la API: es el mecanismo de
    idempotencia. La outbox de Laravel reintenta la entrega si no recibe
    respuesta; sin esta proteccion, el mismo evento generaria dos notificaciones
    (por ejemplo, dos SMS al mismo paciente).
    """
