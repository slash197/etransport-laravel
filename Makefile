export UID := $(shell id -u)
export GID := $(shell id -g)

RUN := docker compose run --rm php

.PHONY: build install validate test stan check smoke shell

build:
	docker compose build

install:
	$(RUN) composer install

validate:
	$(RUN) composer validate --strict

test:
	$(RUN) vendor/bin/phpunit

stan:
	$(RUN) vendor/bin/phpstan analyse --memory-limit=1G

check: validate stan test

# Calls ANAF's test environment; needs .env (see .env.example). make smoke runs etransport:check only;
# make smoke a=--upload declares a made-up transport. The other options are at the top of bin/smoke.php.
smoke:
	$(RUN) php bin/smoke.php $(a)

shell:
	$(RUN) sh
