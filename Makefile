# authn.sh — convenience targets around the Docker compose stack.
#
#   make up         start the production-shaped stack
#   make dev        start the dev stack (app + worker + scheduler + vite + mailpit)
#   make down       stop everything; pass V=1 to drop volumes
#   make logs [s=app]
#   make sh   [s=app]     shell into a service
#   make test             run the Pest suite inside the dev `app` container
#   make dusk             run the Dusk smoke suite against the running stack
#   make pint             run pint --test
#   make scale w=N        scale the worker service to N replicas
#   make build            rebuild images
#
# Self-hosters can keep a local `docker-compose.override.yml` next to
# this file — it's gitignored and compose auto-merges it.

DC          ?= docker compose
DC_PROD      = $(DC) -f docker-compose.yml
DC_DEV       = $(DC) -f docker-compose.yml -f docker-compose.dev.yml
DC_DUSK      = $(DC) -f docker-compose.yml -f docker-compose.dev.yml -f docker-compose.dusk.yml
SERVICE     ?= app

.PHONY: up down dev logs sh shell test dusk pint scale build ps restart help

up:
	$(DC_PROD) up -d --build

dev:
	$(DC_DEV) up -d --build

down:
ifeq ($(V),1)
	$(DC_DEV) down -v
else
	$(DC_DEV) down
endif

logs:
	$(DC_DEV) logs -f $(or $(s),$(SERVICE))

sh shell:
	$(DC_DEV) exec $(or $(s),$(SERVICE)) sh

test:
	$(DC_DEV) exec app php artisan test $(args)

# Boots Selenium and runs the browser smoke suite against the `make dev`
# stack. Tests use unique data + clean up after themselves, so they run
# against the live dev DB without disturbing the bootstrapped operator.
#
# Operator credentials default to op@example.com / super-secret-password;
# override with `make dusk DUSK_OPERATOR_EMAIL=… DUSK_OPERATOR_PASSWORD=…`
# (e.g. on a dev box that bootstrapped with custom creds). CI is expected
# to bootstrap with the defaults before invoking this target.
DUSK_OPERATOR_EMAIL    ?= op@example.com
DUSK_OPERATOR_PASSWORD ?= super-secret-password

dusk:
	$(DC_DUSK) up -d --force-recreate app selenium
	# Wait for Selenium WebDriver to be ready (up to ~20s).
	@for i in $$(seq 1 20); do \
		$(DC_DUSK) exec -T app curl -sf http://selenium:4444/wd/hub/status >/dev/null 2>&1 && break; \
		sleep 1; \
	done
	# Switch the app to manifest mode (built assets, no Vite HMR) by
	# clearing public/hot — Vite's dev server isn't reachable from the
	# Selenium container, and Dusk doesn't need HMR anyway.
	$(DC_DUSK) exec -T -u 0 app rm -f public/hot
	$(DC_DUSK) exec -T \
		-e AUTHN_BOOTSTRAP_ADMIN_EMAIL=$(DUSK_OPERATOR_EMAIL) \
		-e AUTHN_BOOTSTRAP_ADMIN_PASSWORD=$(DUSK_OPERATOR_PASSWORD) \
		app sh -c "php artisan migrate:fresh --force && php artisan authn:bootstrap"
	$(DC_DUSK) exec -T \
		-e DUSK_DRIVER_URL=http://selenium:4444/wd/hub \
		-e DUSK_MAILPIT_URL=http://mailpit:8025 \
		-e DUSK_OPERATOR_EMAIL=$(DUSK_OPERATOR_EMAIL) \
		-e DUSK_OPERATOR_PASSWORD=$(DUSK_OPERATOR_PASSWORD) \
		app php artisan dusk $(args)

pint:
	$(DC_DEV) exec app vendor/bin/pint --test

scale:
	@if [ -z "$(w)" ]; then echo "usage: make scale w=N"; exit 2; fi
	$(DC_DEV) up -d --scale worker=$(w)

build:
	$(DC_DEV) build

ps:
	$(DC_DEV) ps

restart:
	$(DC_DEV) restart $(or $(s),$(SERVICE))

help:
	@awk '/^# / { print substr($$0, 3) }' $(MAKEFILE_LIST) | head -20
