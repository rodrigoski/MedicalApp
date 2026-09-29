"""
Endpoints de consulta y de operacion.

GET  -> observabilidad: ver el estado de la cola sin tocar la base de datos a mano.
POST /process -> dispara una pasada del procesador a mano. Es lo que usa el
                comando `php artisan events:dispatch` / el contenedor `worker`
                cuando el servicio se levanta con este endpoint en lugar de un
                bucle propio.
"""

from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, status

from app.api.deps import get_deliver_pending, get_repository, require_api_key
from app.api.schemas import NotificationResponse, ProcessResponse
from app.application.services import DeliverPendingService
from app.domain.exceptions import InvalidNotificationId
from app.domain.value_objects import NotificationId, NotificationStatus
from app.infrastructure.repositories import SqlAlchemyNotificationRepository

router = APIRouter(prefix="/api/v1", tags=["notifications"])


@router.get(
    "/notifications",
    response_model=list[NotificationResponse],
    summary="Lista notificaciones por estado",
    dependencies=[Depends(require_api_key)],
    responses={422: {"description": "El filtro de estado no es valido."}},
)
def list_notifications(
    repository: Annotated[SqlAlchemyNotificationRepository, Depends(get_repository)],
    status_filter: Annotated[str | None, Query(alias="status")] = None,
    limit: Annotated[int, Query(ge=1, le=200)] = 50,
) -> list[NotificationResponse]:
    """
    Listado acotado de notificaciones, opcionalmente filtrado por estado.

    `limit` tiene tope maximo a proposito: sin el, un backlog grande puede
    tumbar el servicio justo cuando se necesita para diagnosticar.
    """
    parsed: NotificationStatus | None = None

    if status_filter is not None:
        try:
            parsed = NotificationStatus(status_filter)
        except ValueError:
            raise HTTPException(
                status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
                detail={
                    "code": "notifications.invalid_status",
                    "detail": (
                        f"Estado desconocido: {status_filter}. "
                        f"Validos: {[status.value for status in NotificationStatus]}"
                    ),
                },
            ) from None

    return [
        NotificationResponse(**notification.to_dict())
        for notification in repository.list_by_status(parsed, limit)
    ]


@router.get(
    "/notifications/{notification_id}",
    response_model=NotificationResponse,
    summary="Consulta una notificacion",
    dependencies=[Depends(require_api_key)],
    responses={404: {"description": "La notificacion no existe."}},
)
def get_notification(
    notification_id: str,
    repository: Annotated[SqlAlchemyNotificationRepository, Depends(get_repository)],
) -> NotificationResponse:
    try:
        parsed = NotificationId(notification_id)
    except InvalidNotificationId as exc:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail={
                "code": "notifications.invalid_id",
                "detail": "El identificador no tiene un formato valido.",
            },
        ) from exc

    notification = repository.find_by_id(parsed)
    if notification is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail={
                "code": "notifications.not_found",
                "detail": f"No existe la notificacion {notification_id}.",
            },
        )

    return NotificationResponse(**notification.to_dict())


@router.post(
    "/process",
    response_model=ProcessResponse,
    summary="Fuerza una pasada de entrega",
    dependencies=[Depends(require_api_key)],
)
def process_pending(
    service: Annotated[DeliverPendingService, Depends(get_deliver_pending)],
) -> ProcessResponse:
    """
    Ejecuta una pasada del procesador y devuelve el resumen.

    Se expone como endpoint (y no solo como tarea programada) para que el
    operador pueda forzar un reintento sin esperar al siguiente ciclo, y para que
    las pruebas puedan verificar el comportamiento de extremo a extremo.
    """
    summary = service.execute()
    return ProcessResponse(**summary.to_dict())
