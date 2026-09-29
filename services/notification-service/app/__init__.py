"""
Microservicio de notificaciones.

Estructura por capas, en el mismo criterio que el backend Laravel:

    app/domain          reglas de negocio, sin FastAPI ni SQLAlchemy
    app/application     casos de uso y puertos (Protocol)
    app/infrastructure  base de datos, canales, reloj
    app/api             HTTP: esquemas, dependencias, rutas
    app/config.py       configuracion por entorno

La dependencia fluye siempre hacia adentro: la API conoce la aplicacion, la
aplicacion conoce el dominio, y el dominio no conoce a nadie.
"""

__version__ = "1.0.0"
