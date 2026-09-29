# Imagen del microservicio de notificaciones.
#
# Etapas:
#   1. deps    - instala las dependencias de EJECUCION en una capa propia. Se
#      separan del codigo para que una copia de un cambio en el codigo NO
#      reinstale pip.
#   2. test    - añade las dependencias de desarrollo para ejecutar la suite con
#      `docker compose --profile test run --rm notification-service-tests`.
#      Existe como etapa aparte para poder correr pruebas sin ensuciar la
#      imagen que se despliega.
#   3. runtime - copia el codigo y arranca uvicorn como usuario sin privilegios.
#      NO contiene pytest ni las pruebas: la imagen que corre en produccion no
#      necesita un framework de pruebas.
#
# El ORDEN importa: `runtime` va al final porque en un Dockerfile multi-etapa el
# destino por defecto es la ultima etapa. Asi, `docker build .` sin `--target`
# construye la imagen de produccion y no una que arranca pytest.

FROM python:3.12-slim AS deps

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /srv

# build-essential y libpq-dev hacen falta solo si `psycopg` tuviera que compilar
# desde el fuente. Se usa `psycopg[binary]`, que trae ruedas precompiladas, asi
# que en la practica esta capa podria no ser necesaria; se conserva porque un
# fallo de wheeled para la version de Python de la imagen dejaria el servicio sin
# poder construirse, y un fallo de construccion no se puede corregir en caliente.
RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential libpq-dev \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt ./

# `--require-hashes` no se usa porque fijamos versiones exactas en el archivo;
# el Dockerfile queda reproducible y sin un archivo de hashes que mantener.
RUN pip install --no-cache-dir -r requirements.txt


# Etapa de pruebas. Parte de `deps` para reutilizar la misma instalacion de
# dependencias de ejecucion, de modo que las pruebas se ejecutan contra
# exactamente las versiones que corren en produccion.
#
# Va ANTES de `runtime` a proposito: en un Dockerfile de varias etapas el
# destino por defecto es la ULTIMA etapa, de modo que dejarla al final haria que
# un `docker build .` sin `--target` produjese una imagen con pytest como
# comando de arranque. Manteniendo `runtime` al final, el build sin argumento
# genera justo la imagen que se despliega.
FROM deps AS test

WORKDIR /srv

RUN groupadd --system app && useradd --system --gid app --create-home app

COPY requirements-dev.txt ./
RUN pip install --no-cache-dir -r requirements-dev.txt

COPY --chown=app:app app ./app
COPY --chown=app:app tests ./tests
COPY --chown=app:app pytest.ini ./

USER app

CMD ["pytest"]


FROM python:3.12-slim AS runtime

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /srv

# Usuario dedicado: un proceso web no necesita ser root, y un incidente de
# seguridad no debe encontrar el servicio corriendo como root.
RUN groupadd --system app && useradd --system --gid app --create-home app

COPY --from=deps /usr/local/lib/python3.12/site-packages /usr/local/lib/python3.12/site-packages
COPY --from=deps /usr/local/bin /usr/local/bin

COPY --chown=app:app app ./app

USER app

EXPOSE 8000

# Liveness sin dependencias: si aqui falla, el proceso esta muerto de verdad.
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8000/api/v1/health', timeout=2).status == 200 else 1)"

# Un solo worker: el servicio es replicable por contenedor (scale horizontal)
# en lugar de por procesos, lo que ademas hace innecesario coordinar el
# `SELECT ... FOR UPDATE` entre workers de un mismo host.
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1"]

