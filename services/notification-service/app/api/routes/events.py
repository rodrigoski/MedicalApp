"""
Endpoints de recepcion de eventos.

`POST /api/v1/events` es el unico endpoint de escritura. Es idempotente: el
mismo `event_id` recibido N veces produce UNA notificacion, y las N respuestas
son 2xx para que la outbox de Laravel deje de reintentar.
"""

from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends, Request, Response, status

from app.api.deps import get_receive_event, require_api_key
from app.api.schemas import EventRequest, ReceiptResponse
from app.application.services import ReceiveEventService

router = APIRouter(prefix="/api/v1", tags=["events"])


@router.post(
    "/events",
    response_model=ReceiptResponse,
    status_code=status.HTTP_201_CREATED,
    summary="Recibe un evento de dominio y encola su notificacion",
    dependencies=[Depends(require_api_key)],
    responses={
        200: {"description": "El evento ya existia; se devuelve el acuse original."},
        401: {"description": "Credencial ausente o invalida."},
        409: {"description": "Entrega concurrente del mismo evento."},
        422: {"description": "El evento no cumple el contrato."},
    },
)
def receive_event(
    payload: EventRequest,
    request: Request,
    response: Response,
    service: Annotated[ReceiveEventService, Depends(get_receive_event)],
) -> ReceiptResponse:
    """
    Registra un evento como notificacion pendiente.

    Respuestas:
      - 201: evento nuevo, notificacion creada.
      - 200: evento duplicado, se devuelve la notificacion existente. NO es 409
        porque para el productor (la outbox) la operacion SI fue satisfactoria:
        el evento quedo aceptado y no debe reintentarse.
      - 409: dos entregas simultaneas del mismo evento cruzaron la comprobacion
        de duplicados. El indice unico ya garantiza que solo exista una
        notificacion; el productor reintentara y recibira el 200 del punto
        anterior.
    """
    envelope = payload.to_envelope(request_id=request.headers.get("X-Request-Id"))
    receipt = service.execute(envelope)

    if receipt.duplicate:
        response.status_code = status.HTTP_200_OK

    return ReceiptResponse(
        notification_id=receipt.notification_id,
        event_id=receipt.event_id,
        duplicate=receipt.duplicate,
    )
