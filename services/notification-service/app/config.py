"""
Configuracion del microservicio de notificaciones.

Todos los valores vienen del ENTORNO, nunca de literales en el codigo. Es la
misma disciplina que en el backend Laravel: la configuracion describe el
entorno donde corre el servicio (desarrollo, pruebas, produccion), y el codigo
no cambia entre entornos.
"""

from __future__ import annotations

from functools import lru_cache

from pydantic import Field, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Parametros del servicio, leidos de variables de entorno."""

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        case_sensitive=False,
        # Permite escribir `Settings(api_key=...)` en las pruebas ademas de
        # `API_KEY=...` en el entorno. Sin esto, Pydantic exigiria el alias y las
        # pruebas no podrian construir la configuracion de forma explicita.
        populate_by_name=True,
        extra="ignore",
    )

    # --- Identidad ---------------------------------------------------------
    service_name: str = "notification-service"
    version: str = "1.0.0"
    environment: str = "development"
    debug: bool = False

    # --- Servidor ----------------------------------------------------------
    host: str = "0.0.0.0"
    port: int = 8000

    # --- Seguridad ---------------------------------------------------------
    # API key compartida con el backend Laravel. Se acepta como cabecera
    # `Authorization: Bearer <clave>` porque es lo que envia Http::withToken().
    api_key: str = Field(default="dev-notifications-key-change-me", alias="API_KEY")

    # --- Base de datos -----------------------------------------------------
    # Base propia del microservicio: la outbox de Laravel y las notificaciones
    # son datos con distinto ciclo de vida y no se mezclan en un solo esquema.
    database_url: str = "postgresql+psycopg://clinic:clinic@postgres:5432/clinic_notifications"
    db_echo: bool = False
    db_pool_size: int = 5
    db_max_overflow: int = 10
    db_pool_recycle_seconds: int = 1800

    # --- Politica de reintentos ------------------------------------------
    max_attempts: int = 5
    backoff_base_seconds: int = 30
    backoff_max_seconds: int = 3600

    # --- Canal de salida ---------------------------------------------------
    # `log` deja traza en el log del contenedor; `webhook` envia el evento a un
    # sistema externo (pasarela de SMS, correo, etc.).
    channel: str = "log"
    webhook_url: str | None = None
    webhook_timeout_seconds: float = 4.0

    # --- Ventana de trabajo del procesador --------------------------------
    # Cada cuanto busca notificaciones pendientes o fallidas.
    poll_interval_seconds: int = 15
    batch_size: int = 25

    @field_validator("channel")
    @classmethod
    def _validate_channel(cls, value: str) -> str:
        permitidos = {"log", "webhook"}
        if value not in permitidos:
            raise ValueError(f"channel debe ser uno de {sorted(permitidos)}")
        return value

    @field_validator("api_key")
    @classmethod
    def _validate_api_key(cls, value: str) -> str:
        # Fallar rapido en el arranque es preferible a aceptar cualquier clave.
        if len(value.strip()) < 16:
            raise ValueError("API_KEY debe tener al menos 16 caracteres")
        return value.strip()


@lru_cache
def get_settings() -> Settings:
    """
    Devuelve la configuracion cacheada.

    `lru_cache` garantiza que la instancia sea unica: releer el entorno en cada
    peticion haria que un cambio de entorno a mitad de una prueba produzca
    resultados impredecibles.
    """
    return Settings()
