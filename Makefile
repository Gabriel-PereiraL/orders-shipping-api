.DEFAULT_GOAL := help
COMPOSE := docker compose
RUN := $(COMPOSE) run --rm app

.PHONY: help up down install shell migrate migrate-test test test-unit stan cs cs-fix check

help: ## List the available targets
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | sed 's/:.*##/\t/' | expand -t22

up: ## Start the environment (builds the image on first run)
	$(COMPOSE) up -d --build

down: ## Stop the environment, keeping the database volume
	$(COMPOSE) down

install: ## Install PHP dependencies inside the container
	$(RUN) composer install

shell: ## Open a shell in the application container
	$(RUN) sh

migrate: ## Apply migrations to the development database
	$(RUN) vendor/bin/phinx migrate -e development

migrate-test: ## Apply migrations to the integration test database
	$(RUN) vendor/bin/phinx migrate -e test

test: ## Run the whole test suite
	$(RUN) vendor/bin/phpunit

test-unit: ## Run only the tests that need nothing but PHP
	$(RUN) vendor/bin/phpunit --testsuite unit

stan: ## Run static analysis
	$(RUN) vendor/bin/phpstan analyse

cs: ## Check code style
	$(RUN) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Fix code style
	$(RUN) vendor/bin/php-cs-fixer fix

check: cs stan test ## Everything CI runs
