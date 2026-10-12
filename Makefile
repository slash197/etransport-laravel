export UID := $(shell id -u)
export GID := $(shell id -g)

RUN := docker compose run --rm php

.PHONY: build install validate test stan check shell

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

shell:
	$(RUN) sh
