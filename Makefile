.DEFAULT_GOAL := help

-include .env
export

APP_UID := $(shell id -u)
APP_GID := $(shell id -g)

DC := docker compose
PHP_EXEC := $(DC) exec -u www-data php
DRUSH := $(PHP_EXEC) vendor/bin/drush

SCSS_SRC := scss/style.scss
SCSS_OUT := css/style.css

##@ Help

help: ## Show this help
	@awk 'BEGIN {FS = ":.*## "} \
		/^##@ / {printf "\n%s\n", substr($$0, 5)} \
		/^[a-zA-Z_-]+:.*## / {printf "  %-18s %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@echo ""

##@ Setup

init: ## Create .env from .env.example when it does not exist yet
	@if [ -f .env ]; then \
		echo ".env already exists, leaving it untouched"; \
	else \
		cp .env.example .env; \
		echo ".env created from .env.example"; \
	fi

install: ## Full setup: env file, images, containers and Drupal installation
	@$(MAKE) init
	@$(MAKE) build
	@$(MAKE) up
	$(PHP_EXEC) bash scripts/install.sh

reinstall: ## Reinstall Drupal from scratch, dropping the current database
	$(DC) exec -u www-data -e FORCE_INSTALL=1 php bash scripts/install.sh

##@ Containers

build: ## Build the application images
	$(DC) build

up: ## Start the containers in the background
	$(DC) up -d

down: ## Stop the containers, keeping the database volume
	$(DC) down --remove-orphans

destroy: ## Stop the containers and delete their volumes
	$(DC) down -v --remove-orphans

ps: ## Show the status of the containers
	$(DC) ps

logs: ## Follow logs, optionally for one service: make logs s=php
	$(DC) logs -f --tail=100 $(s)

shell: ## Open a shell in the php container as www-data
	$(PHP_EXEC) bash

##@ Drupal

drush: ## Run drush: make drush ARGS="status"
	$(DRUSH) $(ARGS)

composer: ## Run composer: make composer ARGS="require drupal/token"
	$(PHP_EXEC) composer $(ARGS)

composer-install: ## Install the PHP dependencies
	$(PHP_EXEC) composer install --no-interaction

cr: ## Rebuild the Drupal caches
	$(DRUSH) cache:rebuild

uli: ## Print a one-time login link for the admin account
	$(DRUSH) user:login

config-export: ## Export the Drupal configuration
	$(DRUSH) config:export -y

config-import: ## Import the Drupal configuration
	$(DRUSH) config:import -y

db-shell: ## Open a MariaDB shell on the project database
	$(DC) exec db mariadb -u$(DB_USER) -p$(DB_PASSWORD) $(DB_NAME)

##@ Development

xdebug-on: ## Recreate the php container with Xdebug enabled
	XDEBUG_MODE=debug $(DC) up -d --force-recreate php
	$(DC) restart nginx

xdebug-off: ## Recreate the php container with Xdebug disabled
	XDEBUG_MODE=off $(DC) up -d --force-recreate php
	$(DC) restart nginx

twig-debug-on: ## Enable Twig debugging and disable render caching
	$(DRUSH) theme:dev on

twig-debug-off: ## Disable Twig debugging and restore render caching
	$(DRUSH) theme:dev off

scss: ## Compile the theme SCSS once
	@$(DC) --profile dev run --rm sass \
		sass --no-source-map --no-error-css --style=compressed $(SCSS_SRC):$(SCSS_OUT) \
		&& echo "SCSS compiled to $(SCSS_OUT)" \
		|| { echo "SCSS compilation failed"; exit 1; }

scss-watch: ## Watch the theme SCSS and recompile on every change
	$(DC) --profile dev up sass

.PHONY: help init install reinstall build up down destroy ps logs shell \
	drush composer composer-install cr uli config-export config-import db-shell \
	xdebug-on xdebug-off twig-debug-on twig-debug-off scss scss-watch
