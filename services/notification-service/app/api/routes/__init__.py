"""
Rutas de la API.

Convencion de respuesta: se replica el envoltorio del backend Laravel
(`{"data": ...}` en exito y `{"error": ...}` en fallo) para que un consumidor
vea la misma forma de documento venga del microservicio que venga.
"""

from app.api.routes.events import router as events_router
from app.api.routes.health import router as health_router
from app.api.routes.notifications import router as notifications_router

__all__ = ["events_router", "health_router", "notifications_router"]
