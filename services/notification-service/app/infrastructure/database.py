"""
Motor, sesion y esquema de la base de datos.

Se expone como una clase (`Database`) en lugar de un motor global por dos
motivos practicos:

  1. Las pruebas pueden crear una base SQLite en memoria por test y descartarla,
     sin estado compartido entre tests.
  2. El ciclo de vida (conectar, migrar esquema, cerrar) queda explicito y
     visible en el arranque de la aplicacion, en lugar de ocurrir por magia al
     importar un modulo.
"""

from __future__ import annotations

import logging
from collections.abc import Iterator
from contextlib import contextmanager

from sqlalchemy import Engine, create_engine, text
from sqlalchemy.orm import Session, sessionmaker
from sqlalchemy.pool import StaticPool

from app.infrastructure.models import Base

logger = logging.getLogger(__name__)


class Database:
    """Envoltura fina sobre SQLAlchemy: motor, fabrica de sesiones y esquema."""

    def __init__(self, url: str, *, echo: bool = False, pool_size: int = 5,
                 max_overflow: int = 10, pool_recycle: int = 1800) -> None:
        self._url = url
        self._engine: Engine = self._build_engine(
            url, echo, pool_size, max_overflow, pool_recycle
        )
        self._session_factory = sessionmaker(
            bind=self._engine, expire_on_commit=False, future=True
        )

    @staticmethod
    def _build_engine(
        url: str, echo: bool, pool_size: int, max_overflow: int, pool_recycle: int
    ) -> Engine:
        """
        Construye el motor adaptandose al motor de base de datos.

        SQLite se usa solo en las pruebas (en memoria), y necesita dos ajustes:
        no admite pool de conexiones ni `recycle`, y cada conexion debe poder
        ejecutarse en un hilo distinto del que la creo (FastAPI usa un thread
        pool). `StaticPool` mantiene UNA sola conexion, que es justamente lo que
        hace que una base en memoria no se vacie entre peticiones.
        """
        if url.startswith("sqlite"):
            is_memory = ":memory:" in url or url in ("sqlite://", "sqlite+pysqlite://")

            if is_memory:
                return create_engine(
                    url,
                    echo=echo,
                    future=True,
                    poolclass=StaticPool,
                    connect_args={"check_same_thread": False},
                )

            return create_engine(
                url,
                echo=echo,
                future=True,
                connect_args={"check_same_thread": False},
            )

        return create_engine(
            url,
            echo=echo,
            future=True,
            pool_size=pool_size,
            max_overflow=max_overflow,
            pool_recycle=pool_recycle,
            pool_pre_ping=True,
        )

    @property
    def engine(self) -> Engine:
        return self._engine

    def create_schema(self) -> None:
        """
        Crea las tablas si no existen.

        Se usa Alembic en un despliegue real; en este servicio la migracion es
        un unico `CREATE TABLE IF NOT EXISTS`, y se mantiene aqui para que el
        arranque sea reproducible tanto en Docker como en pruebas.
        """
        Base.metadata.create_all(self._engine)
        logger.info("notification.database.schema_ready")

    @contextmanager
    def session(self) -> Iterator[Session]:
        """
        Sesion transaccional con rollback automatico ante error.

        El `finally: close()` garantiza que ningun camino (excepcion incluida)
        devuelva la conexion al pool con una transaccion abierta, que es la causa
        clasica de bloqueos en PostgreSQL.
        """
        session = self._session_factory()
        try:
            yield session
            session.commit()
        except Exception:
            session.rollback()
            raise
        finally:
            session.close()

    def ping(self) -> bool:
        """
        Comprueba la conectividad. Se usa en el endpoint de readiness.

        Devuelve False en lugar de lanzar excepcion: un health check debe
        responder 503 con detalle, no propagar un error 500.
        """
        try:
            with self._engine.connect() as connection:
                connection.execute(text("SELECT 1"))
            return True
        except Exception as exc:  # noqa: BLE001 - aqui el fallo ES el dato
            logger.warning("notification.database.ping_failed", extra={"error": str(exc)})
            return False

    def dispose(self) -> None:
        self._engine.dispose()
