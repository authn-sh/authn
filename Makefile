# authn.sh — convenience targets around the Docker compose stack.
#
#   make up         start the production-shaped stack
#   make dev        start the dev stack (app + worker + scheduler + vite + mailpit)
#   make down       stop everything; pass V=1 to drop volumes
#   make logs [s=app]
#   make sh   [s=app]     shell into a service
#   make test             run the Pest suite inside the dev `app` container
#   make pint             run pint --test
#   make scale w=N        scale the worker service to N replicas
#   make build            rebuild images
#
# Self-hosters can keep a local `docker-compose.override.yml` next to
# this file — it's gitignored and compose auto-merges it.

DC          ?= docker compose
DC_PROD      = $(DC) -f docker-compose.yml
DC_DEV       = $(DC) -f docker-compose.yml -f docker-compose.dev.yml
SERVICE     ?= app

.PHONY: up down dev logs sh shell test pint scale build ps restart help

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
