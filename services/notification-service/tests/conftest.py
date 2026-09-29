"""
Configuracion compartida de las pruebas.

`TestClient` de FastAPI arranca la aplicacion completa, incluido el `lifespan`.
Para que las pruebas sean deterministas y no dependan de servicios externos, se
sustituyen DOS cosas antes de arrancar:

  - la configuracion (`get_settings`), para apuntar a SQLite en memoria y a una
    API key conocida,
  - nada mas: el resto del grafo (casos de uso, repositorio, canal) es el real.

Como `environment == "test"`, el lifespan no arranca el procesador en segundo
plano, que competiria con los tests que comprueban que una notificacion sigue
en `pending`.

Por eso estas pruebas son integracion honesta: no hay una version "de pruebas"
del codigo que pueda divergir de la de produccion.
"""

from __future__ import annotations

from collections.abc import Iterator
from datetime import UTC, datetime

import pytest
from fastapi.testclient import TestClient

from app.config import Settings
from app.domain.policies import RetryPolicy

API_KEY = "test-notification-key-0123456789"
FIXED_NOW = datetime(2025, 1, 7, 10, 0, tzinfo=UTC)


@pytest.fixture
def test_settings() -> Settings:
    """Configuracion de pruebas: entorno `test` y clave conocida."""
    return Settings(
        service_name="notification-service",
        version="test",
        environment="test",
        api_key=API_KEY,
        database_url="sqlite+pysqlite://",
        channel="log",
        max_attempts=3,
        backoff_base_seconds=30,
        backoff_max_seconds=600,
        poll_interval_seconds=1,
        batch_size=10,
    )


@pytest.fixture
def client(monkeypatch: pytest.MonkeyPatch, test_settings: Settings) -> Iterator[TestClient]:
    """
    Cliente HTTP con el contenedor de pruebas ya instalado.

    Se sustituye `get_settings` ANTES de crear la aplicacion, de modo que el
    propio `lifespan` construye el contenedor correcto (motor SQLite en memoria,
    canal de log, sin procesador). No hay que parchear nada despues.
    """
    import app.main as main

    monkeypatch.setattr(main, "get_settings", lambda: test_settings)

    with TestClient(main.create_app()) as test_client:
        yield test_client


@pytest.fixture
def container(client: TestClient):  # type: ignore[no-untyped-def]
    """Contenedor de dependencias del cliente de pruebas."""
    return client.app.state.container


@pytest.fixture
def database(container):  # type: ignore[no-untyped-def]
    """Base de datos del cliente de pruebas, para aserciones de bajo nivel."""
    return container.database


@pytest.fixture
def repository(database):  # type: ignore[no-untyped-def]
    """Repositorio real sobre la base de pruebas."""
    from app.infrastructure.repositories import SqlAlchemyNotificationRepository

    with database.session() as session:
        yield SqlAlchemyNotificationRepository(session)


@pytest.fixture
def retry_policy() -> RetryPolicy:
    return RetryPolicy(max_attempts=3, base_delay_seconds=30, cap_delay_seconds=600)


@pytest.fixture
def auth_headers() -> dict[str, str]:
    """Cabecera de autenticacion que espera la API."""
    return {"Authorization": f"Bearer {API_KEY}"}


@pytest.fixture
def event_payload() -> dict:
    """Evento valido tal y como lo produce `DomainEvent::toArray()` de Laravel."""
    return {
        "event_id": "6f1d2c3a-9b8e-4f21-8a7b-0c1d2e3f4a5b",
        "event_type": "appointment.created",
        "aggregate_type": "appointment",
        "aggregate_id": 812,
        "occurred_at": "2025-01-07T15:00:00+00:00",
        "payload": {
            "patient_id": 44,
            "doctor_id": 3,
            "starts_at": "2025-01-08T13:00:00Z",
        },
    }
