"""
CAPA HTTP: lo que el mundo exterior ve.

Traduce peticion -> caso de uso y resultado -> respuesta. No contiene reglas de
negocio: si aparece una decision de negocio aqui, es un error de colocacion.
"""

from app.api.deps import Container
from app.api.schemas import ErrorBody, ErrorResponse, EventRequest, ReceiptResponse

__all__ = [
    "Container",
    "ErrorBody",
    "ErrorResponse",
    "EventRequest",
    "ReceiptResponse",
]
