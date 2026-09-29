"""
Endpoints de salud.

Se separan en dos, y la distincion es intencional:

  - `/api/v1/health` (liveness): "¿el proceso responde?" NO toca la base de
    datos. Si un fallo de PostgreSQL hiciera caer el proceso, el orquestador lo
    reiniciaria sin resolver nada, y la base seguiria caida.
  - `/api/v1/ready` (readiness): "¿puede atender trabajo ahora?" Si la base no
    responde, devuelve 503 para que el balanceador deje de enviarle eventos.

Es el mismo criterio que usa el backend Laravel, de modo que un solo panel de
observabilidad sirva para los dos servicios.
"""

from __future__ import annotations

from typing import Any

from fastapi import APIRouter, Depends, Response, status

from app.api.deps import Container, get_container
from app.api.schemas import HealthResponse
from app.infrastructure.repositories import SqlAlchemyNotificationRepository

router = APIRouter(prefix="/api/v1", tags=["health"])


@router.get("/health", response_model=HealthResponse, summary="Liveness")
def health(container: Container = Depends(get_container)) -> HealthResponse:
    """
    Liveness. Responde 200 mientras el proceso pueda atender peticiones.

    A proposito NO consulta dependencias: el objetivo es distinguir "el servicio
    esta muerto" de "una dependencia esta caida", que requieren reacciones
    opuestas del orquestador.
    """
    return HealthResponse(
        status="ok",
        service=container.settings.service_name,
        version=container.settings.version,
        environment=container.settings.environment,
        checks={"process": "ok"},
    )


@router.get(
    "/ready",
    response_model=HealthResponse,
    summary="Readiness",
    responses={503: {"description": "Dependencia no disponible"}},
)
def ready(container: Container = Depends(get_container), response: Response) -> HealthResponse:
    """Readiness. Verifica la base de datos y expone el estado de la cola."""
    database_status = "unavailable"
    counts: dict[str, int] = {}

    if container.database.ping():
        try:
            with container.database.session() as session:
                counts = SqlAlchemyNotificationRepository(session).count_by_status()
            database_status = "ok"
        except Exception:  # noqa: BLE001 - el fallo se reporta en la respuesta
            database_status = "error"

    if database_status != "ok":
        response.status_code = status.HTTP_503_SERVICE_UNAVAILABLE

    body: dict[str, Any] = {
        "status": "ok" if database_status == "ok" else "unavailable",
        "service": container.settings.service_name,
        "version": container.settings.version,
        "environment": container.settings.environment,
        "checks": {"database": database_status},
    }

    if counts:
        body["notifications"] = counts

    return HealthResponse(**body)
