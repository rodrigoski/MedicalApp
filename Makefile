# =============================================================================
# ClinicApp - Makefile
#
# Objetivo: que todo lo que se hace a mano durante el desarrollo tenga un
# comando. Si un comando hay que acordarlo de memoria, no esta documentado.
#
# Uso:  make help
# =============================================================================

SHELL := /bin/sh
.DEFAULT_GOAL := help

COMPOSE      := docker compose
COMPOSE_BUILD := $(COMPOSE) build
COMPOSE_UP   := $(COMPOSE) up
COMPOSE_DOWN := $(COMPOSE) down
COMPOSE_EXEC := $(COMPOSE) exec
COMPOSE_RUN  := $(COMPOSE) run --rm
COMPOSE_LOGS := $(COMPOSE) logs -f

API          := api
MIGRATOR     := migrator
NOTIFICATIONS := notification-service
NOTIFICATIONS_TESTS := notification-service-tests
POSTGRES     := postgres

.PHONY: help
help: ## Muestra esta ayuda
	@echo ""
	@echo "ClinicApp - comandos disponibles"
	@echo "================================"
	@grep -E '^[a-zA-Z0-9_.-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'
	@echo ""

# -----------------------------------------------------------------------------
# Ciclo de vida
# -----------------------------------------------------------------------------
.PHONY: setup
setup: ## Crea .env a partir de .env.example (no sobrescribe uno existente)
	@if [ -f .env ]; then \
		echo ".env ya existe; no se toca."; \
	else \
		cp .env.example .env && echo ".env creado desde .env.example"; \
	fi
	@echo "Revisa .env y cambia las claves antes de exponer el stack."

.PHONY: up
up: ## Levanta todo el stack (construyendo imagenes)
	$(COMPOSE_UP) --build -d
	@echo ""
	@echo "  API           http://localhost:8000"
	@echo "  FastAPI        http://localhost:8001/docs"
	@echo "  Gateway        http://localhost:8080"
	@echo "  Health gateway http://localhost:8080/api/v1/health"
	@echo ""
	@echo "Sigue el arranque con: make logs"

.PHONY: up-mocks
up-mocks: ## Levanta el stack con diagnostico interactivo (sin -d)
	$(COMPOSE_UP) --build

.PHONY: down
down: ## Detiene el stack conservando los datos
	$(COMPOSE_DOWN)

.PHONY: destroy
destroy: ## Detiene el stack y BORRA el volumen de PostgreSQL
	$(COMPOSE_DOWN) -v
	@echo "Volumen eliminado. El proximo `make up` arranca con la base vacia."

.PHONY: restart
restart: down up ## Reinicia todo el stack

.PHONY: health
health: ## Comprueba contenedores, base de datos, API, microservicio y gateway
	sh scripts/healthcheck.sh

.PHONY: smoke
smoke: ## Recorrido funcional completo (reserva, traslape, cancelacion, eventos)
	sh scripts/smoke-test.sh

.PHONY: ps
ps: ## Estado de los contenedores
	$(COMPOSE) ps

.PHONY: logs
logs: ## Sigue los logs de todos los servicios
	$(COMPOSE_LOGS) --tail=100

.PHONY: rebuild
rebuild: ## Reconstruye las imagenes sin cache
	$(COMPOSE_BUILD) --no-cache
	@echo "Imagenes reconstruidas. Levanta con: make up"

# -----------------------------------------------------------------------------
# Base de datos
# -----------------------------------------------------------------------------
.PHONY: migrate
migrate: ## Ejecuta las migraciones pendientes
	$(COMPOSE_EXEC) $(API) php artisan migrate --force

.PHONY: migrate-status
migrate-status: ## Muestra el estado de las migraciones
	$(COMPOSE_EXEC) $(API) php artisan migrate:status

.PHONY: rollback
rollback: ## Revierte la ultima migracion
	$(COMPOSE_EXEC) $(API) php artisan migrate:rollback --force

.PHONY: fresh
fresh: ## Recrea la base: rollback total, migracion y seed demo
	$(COMPOSE_EXEC) $(API) php artisan migrate:fresh --seed --force

.PHONY: seed
seed: ## Carga datos de demostracion
	$(COMPOSE_EXEC) $(API) php artisan db:seed --class=DemoDataSeeder --force

.PHONY: psql
psql: ## Abre una consola psql en PostgreSQL
	$(COMPOSE_EXEC) $(POSTGRES) psql -U clinic -d clinic_app

# -----------------------------------------------------------------------------
# Pruebas
# -----------------------------------------------------------------------------
.PHONY: test
test: test-backend test-notifications ## Ejecuta todas las pruebas

.PHONY: test-backend
test-backend: ## Pruebas de Laravel (unitarias, feature y arquitectura)
	$(COMPOSE_EXEC) $(API) php artisan test

.PHONY: test-unit
test-unit: ## Solo pruebas unitarias de Laravel
	$(COMPOSE_EXEC) $(API) php artisan test --testsuite=Unit

.PHONY: test-coverage
test-coverage: ## Pruebas de Laravel con informe de cobertura
	$(COMPOSE_EXEC) $(API) php artisan test --coverage --min=80

.PHONY: test-notifications
test-notifications: ## Pruebas del microservicio FastAPI
	$(COMPOSE) --profile test run --rm $(NOTIFICATIONS_TESTS)

.PHONY: test-notifications-cov
test-notifications-cov: ## Pruebas del microservicio con cobertura
	$(COMPOSE) --profile test run --rm $(NOTIFICATIONS_TESTS) \
		pytest --cov=app --cov-report=term-missing

# -----------------------------------------------------------------------------
# Calidad
# -----------------------------------------------------------------------------
.PHONY: arch
arch: ## Comprueba las reglas de arquitectura del backend
	$(COMPOSE_EXEC) $(API) php artisan architecture:check

.PHONY: routes
routes: ## Lista las rutas de la API
	$(COMPOSE_EXEC) $(API) php artisan route:list --path=api

.PHONY: config
config: ## Muestra la configuracion efectiva
	$(COMPOSE_EXEC) $(API) php artisan config:show clinic

.PHONY: lint
lint: ## Comprueba el estilo del codigo
	$(COMPOSE_RUN) --no-deps $(API) vendor/bin/php-cs-fixer fix --dry-run --diff || \
		echo "php-cs-fixer no esta instalado; se omite."

# -----------------------------------------------------------------------------
# Eventos y notificaciones
# -----------------------------------------------------------------------------
.PHONY: events-dispatch
events-dispatch: ## Entrega los eventos pendientes de la outbox al microservicio
	$(COMPOSE_EXEC) $(API) php artisan events:dispatch

.PHONY: events-prune
events-prune: ## Elimina eventos ya entregados y antiguos
	$(COMPOSE_EXEC) $(API) php artisan events:prune

.PHONY: notifications-process
notifications-process: ## Fuerza una pasada de entrega de notificaciones
	@$(COMPOSE_EXEC) $(NOTIFICATIONS) python -c "\
import os, urllib.request; \
req = urllib.request.Request('http://127.0.0.1:8000/api/v1/process', \
    headers={'Authorization': 'Bearer ' + os.environ['API_KEY']}); \
print(urllib.request.urlopen(req, timeout=30).read().decode())"

.PHONY: notifications-logs
notifications-logs: ## Logs del microservicio de notificaciones
	$(COMPOSE_LOGS) $(NOTIFICATIONS) --tail=100

# -----------------------------------------------------------------------------
# Utilidades
# -----------------------------------------------------------------------------
.PHONY: shell
shell: ## Consola de PHP dentro del contenedor de la API
	$(COMPOSE_EXEC) $(API) php artisan tinker

.PHONY: check
check: arch test ## Comprobaciones completas: arquitectura + pruebas
	@echo ""
	@echo "Comprobaciones completadas."

.PHONY: clean
clean: ## Limpia caches de Laravel y artefactos de Python
	$(COMPOSE_EXEC) $(API) php artisan optimize:clear || true
	@# El contenedor de pruebas es efimero (se borra con --rm), asi que sus
	@# artefactos desaparecen solos. Esto solo aplica a quien haya ejecutado
	@# pytest fuera de Docker. Se usa `find` y no `**/__pycache__` porque el
	@# doble asterisco no lo expande /bin/sh, que es el SHELL de este Makefile.
	@rm -rf services/notification-service/.pytest_cache services/notification-service/.coverage
	@find services/notification-service -type d -name __pycache__ -prune -exec rm -rf {} + 2>/dev/null || true
	@echo "Caches limpiadas."
