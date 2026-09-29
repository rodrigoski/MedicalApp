"""
Pruebas de la API HTTP.

Verifican el CONTRATO PUBLICO: codigos de estado, forma de la respuesta,
autenticacion y propagacion del `X-Request-Id`. Si un cliente depende de
`data.notification_id`, aqui se comprueba que no cambia.
"""

from __future__ import annotations

import re

import pytest
from fastapi.testclient import TestClient

from app.domain.value_objects import NotificationStatus


class TestHealth:
    def test_liveness_no_requiere_credenciales(self, client: TestClient) -> None:
        response = client.get("/api/v1/health")

        assert response.status_code == 200
        body = response.json()
        assert body["status"] == "ok"
        assert body["service"] == "notification-service"

    def test_readiness_verifica_la_base_de_datos(self, client: TestClient) -> None:
        response = client.get("/api/v1/ready")

        assert response.status_code == 200
        body = response.json()
        assert body["checks"]["database"] == "ok"
        # El estado de la cola es informacion que el operador necesita de un
        # vistazo para saber si hay notificaciones atascadas.
        assert "notifications" in body
        assert body["notifications"]["pending"] == 0


class TestAuthentication:
    def test_rechaza_sin_credencial(self, client: TestClient, event_payload: dict) -> None:
        response = client.post("/api/v1/events", json=event_payload)

        assert response.status_code == 401
        assert response.json()["error"]["code"] == "auth.key_missing"

    def test_rechaza_una_clave_incorrecta(self, client: TestClient, event_payload: dict) -> None:
        response = client.post(
            "/api/v1/events",
            json=event_payload,
            headers={"Authorization": "Bearer clave-equivocada-pero-larga"},
        )

        assert response.status_code == 401
        assert response.json()["error"]["code"] == "auth.invalid_key"

    def test_acepta_x_api_key_como_alternativa(self, client: TestClient, event_payload: dict, auth_headers) -> None:
        clave = auth_headers["Authorization"].removeprefix("Bearer ")

        response = client.post("/api/v1/events", json=event_payload, headers={"X-Api-Key": clave})

        assert response.status_code == 201

    def test_los_endpoints_de_consulta_tambien_estan_protegidos(self, client: TestClient) -> None:
        assert client.get("/api/v1/notifications").status_code == 401
        assert client.post("/api/v1/process").status_code == 401


class TestReceiveEvent:
    def test_acepta_un_evento_nuevo(self, client: TestClient, auth_headers, event_payload: dict) -> None:
        response = client.post("/api/v1/events", json=event_payload, headers=auth_headers)

        assert response.status_code == 201
        body = response.json()
        assert body["event_id"] == event_payload["event_id"]
        assert body["duplicate"] is False
        assert body["notification_id"]

    def test_un_evento_reenviado_responde_200_y_no_duplica(
        self, client: TestClient, auth_headers, event_payload: dict
    ) -> None:
        """
        Prueba de idempotencia de punta a punta, incluyendo el codigo de estado.

        Es el contrato que la outbox de Laravel necesita: cualquier 2xx detiene
        los reintentos.
        """
        primero = client.post("/api/v1/events", json=event_payload, headers=auth_headers)
        segundo = client.post("/api/v1/events", json=event_payload, headers=auth_headers)

        assert primero.status_code == 201
        assert segundo.status_code == 200
        assert segundo.json()["duplicate"] is True
        assert segundo.json()["notification_id"] == primero.json()["notification_id"]

        listado = client.get("/api/v1/notifications", headers=auth_headers)
        assert len(listado.json()) == 1

    def test_rechaza_un_evento_incompleto_con_422(
        self, client: TestClient, auth_headers
    ) -> None:
        response = client.post(
            "/api/v1/events",
            json={"event_type": "appointment.created"},
            headers=auth_headers,
        )

        assert response.status_code == 422
        body = response.json()
        assert body["error"]["code"] == "validation.failed"
        campos = {error["field"] for error in body["error"]["context"]["errors"]}
        assert {"event_id", "aggregate_type", "aggregate_id"} <= campos

    def test_rechaza_un_agregado_con_identificador_invalido(
        self, client: TestClient, auth_headers, event_payload: dict
    ) -> None:
        response = client.post(
            "/api/v1/events",
            json={**event_payload, "aggregate_id": 0},
            headers=auth_headers,
        )

        assert response.status_code == 422

    def test_no_crea_nada_si_la_autenticacion_falla(
        self, client: TestClient, auth_headers, event_payload: dict
    ) -> None:
        client.post("/api/v1/events", json=event_payload)

        listado = client.get("/api/v1/notifications", headers=auth_headers)

        assert listado.json() == []


class TestNotifications:
    def test_consulta_una_notificacion_por_id(
        self, client: TestClient, auth_headers, event_payload: dict
    ) -> None:
        creada = client.post("/api/v1/events", json=event_payload, headers=auth_headers).json()

        response = client.get(
            f"/api/v1/notifications/{creada['notification_id']}", headers=auth_headers
        )

        assert response.status_code == 200
        body = response.json()
        assert body["status"] == "pending"
        assert body["event_type"] == "appointment.created"
        assert body["aggregate_id"] == 812
        assert body["attempts"] == 0

    def test_404_para_una_notificacion_inexistente(self, client: TestClient, auth_headers) -> None:
        response = client.get(
            "/api/v1/notifications/00000000-0000-4000-8000-000000000000", headers=auth_headers
        )

        assert response.status_code == 404
        assert response.json()["error"]["code"] == "notifications.not_found"

    def test_400_para_un_identificador_con_formato_invalido(
        self, client: TestClient, auth_headers
    ) -> None:
        response = client.get("/api/v1/notifications/no-es-uuid", headers=auth_headers)

        assert response.status_code == 400
        assert response.json()["error"]["code"] == "notifications.invalid_id"

    def test_filtra_por_estado(self, client: TestClient, auth_headers, event_payload: dict) -> None:
        client.post("/api/v1/events", json=event_payload, headers=auth_headers)

        pendientes = client.get("/api/v1/notifications?status=pending", headers=auth_headers)
        enviadas = client.get("/api/v1/notifications?status=sent", headers=auth_headers)

        assert len(pendientes.json()) == 1
        assert enviadas.json() == []

    def test_422_para_un_estado_desconocido(self, client: TestClient, auth_headers) -> None:
        response = client.get("/api/v1/notifications?status=inventado", headers=auth_headers)

        assert response.status_code == 422
        assert response.json()["error"]["code"] == "notifications.invalid_status"

    def test_limita_el_tamano_del_listado(self, client: TestClient, auth_headers) -> None:
        response = client.get("/api/v1/notifications?limit=5000", headers=auth_headers)

        assert response.status_code == 422


class TestProcessEndpoint:
    def test_entrega_las_notificaciones_pendientes(
        self, client: TestClient, auth_headers, event_payload: dict
    ) -> None:
        creada = client.post("/api/v1/events", json=event_payload, headers=auth_headers).json()

        respuesta = client.post("/api/v1/process", headers=auth_headers)

        assert respuesta.status_code == 200
        assert respuesta.json() == {
            "processed": 1,
            "sent": 1,
            "retry_scheduled": 0,
            "exhausted": 0,
            "skipped": 0,
        }

        consulta = client.get(
            f"/api/v1/notifications/{creada['notification_id']}", headers=auth_headers
        )
        assert consulta.json()["status"] == NotificationStatus.SENT.value
        assert consulta.json()["attempts"] == 1

    def test_no_hay_nada_que_procesar_si_la_cola_esta_vacia(
        self, client: TestClient, auth_headers
    ) -> None:
        respuesta = client.post("/api/v1/process", headers=auth_headers)

        assert respuesta.json()["processed"] == 0


class TestCrossCutting:
    def test_el_envoltorio_de_error_iguala_al_del_backend(self, client: TestClient) -> None:
        """
        Los dos servicios cuelgan del mismo gateway. Si el microservicio
        nombrase las cosas de otra forma, cada cliente necesitaria dos rutas de
        lectura distintas y un fallo entre servicios seria mas dificil de
        diagnosticar que el fallo en si.
        """
        response = client.get("/api/v1/notifications", headers={"X-Request-Id": "req-envoltorio"})

        body = response.json()

        assert body["success"] is False
        assert set(body["error"]) >= {"code", "detail", "status", "context"}
        # `detail` y no `message`: es el nombre que usa Laravel.
        assert "message" not in body["error"]
        # El identificador va en la raiz, igual que en el backend, y no anidado
        # dentro de `error`.
        assert body["request_id"] == "req-envoltorio"
        assert "request_id" not in body["error"]

    def test_el_codigo_del_error_es_estable_entre_ejecuciones(self, client: TestClient) -> None:
        """
        El `code` es lo que un cliente programa. La prueba fija el valor exacto
        para que un cambio de redaccion del mensaje no rompa a nadie y para que
        un cambio de codigo se note aqui y no en produccion.
        """
        response = client.post("/api/v1/events", json={"event_type": "appointment.created"})

        assert response.status_code == 422
        assert response.json()["error"]["code"] == "validation.failed"

    def test_propaga_el_request_id_del_backend(
        self, client: TestClient, auth_headers, event_payload: dict
    ) -> None:
        """
        Un mismo identificador atraviesa la reserva de la cita, la outbox y este
        servicio. Con eso, un `grep` en los logs reconstruye la historia de una
        peticion completa, que es lo que exige una auditoria de incidente.
        """
        response = client.post(
            "/api/v1/events",
            json=event_payload,
            headers={**auth_headers, "X-Request-Id": "req-abc-123"},
        )

        assert response.headers["X-Request-Id"] == "req-abc-123"

    def test_rechaza_un_request_id_con_caracteres_de_control(
        self, client: TestClient
    ) -> None:
        """
        El servicio se publica tambien en un puerto propio, asi que el
        identificador no siempre viene de Nginx: alguien puede alcanzar el
        puerto y controlar esa cadena, que acaba en una cabecera de respuesta y
        en un log.

        Se descarta el valor entero en vez de "limpiarlo": un identificador al
        que hubo que quitarle caracteres ya no aparece en ninguna otra parte del
        rastro, asi que propagar una mutilacion solo produciria un engano.
        """
        malicioso = "req-1\nX-Admin: true"

        response = client.get("/api/v1/health", headers={"X-Request-Id": malicioso})

        emitido = response.headers["X-Request-Id"]

        assert emitido != malicioso
        assert "\n" not in emitido
        assert re.fullmatch(r"[A-Za-z0-9._-]{1,64}", emitido)

    def test_rechaza_un_request_id_demasiado_largo(self, client: TestClient) -> None:
        """
        Una cadena sin limite de longitud acabaria integra en el log y ademas
        infla la respuesta. El tope de 64 caracteres es el mismo que impone el
        backend, para que ambos servicios rechacen exactamente lo mismo.
        """
        response = client.get("/api/v1/health", headers={"X-Request-Id": "a" * 65})

        assert len(response.headers["X-Request-Id"]) <= 64

    def test_acepta_un_request_id_generado_por_nginx(self, client: TestClient) -> None:
        """
        El caso que de verdad importa: el identificador de 32 hex que emite
        Nginx debe llegar intacto a la cabecera de respuesta, porque es la union
        que permite seguir una peticion entre los dos servicios.
        """
        de_nginx = "0123456789abcdef0123456789abcdef"

        response = client.get("/api/v1/health", headers={"X-Request-Id": de_nginx})

        assert response.headers["X-Request-Id"] == de_nginx

    def test_genera_un_request_id_cuando_el_cliente_no_envia_uno(
        self, client: TestClient
    ) -> None:
        response = client.get("/api/v1/health")

        assert response.headers["X-Request-Id"]

    def test_expone_el_tiempo_de_respuesta(self, client: TestClient) -> None:
        response = client.get("/api/v1/health")

        assert float(response.headers["X-Response-Time-ms"]) >= 0

    def test_las_todas_las_respuestas_llevan_cabeceras_defensivas(
        self, client: TestClient
    ) -> None:
        response = client.get("/api/v1/health")

        assert response.headers["X-Content-Type-Options"] == "nosniff"
        assert response.headers["X-Frame-Options"] == "DENY"
        assert response.headers["Referrer-Policy"] == "no-referrer"

    def test_el_404_devuelve_json_y_no_html(self, client: TestClient) -> None:
        """
        Un consumidor que espera JSON recibe JSON en cualquier caso.

        Sin esto, un error de ruta produce el HTML de depuracion de FastAPI y el
        cliente falla al parsear, ocultando el problema real.
        """
        response = client.get("/api/v1/no-existe")

        assert response.status_code == 404
        assert "error" in response.json()
