# Energie — test & utility targets.
#
#   make test           Run Python + PHP suites.
#   make test-py        Python (pytest) only.
#   make test-php       PHP (PHPUnit) only.
#   make test-db-setup  One-time: create energie_test DB and grant to energie user.
#   make test-db-drop   Tear down the test DB (safe; contents are ephemeral).
#
# test-db-setup needs root-level MySQL access on /tmp/mysql.sock. Every other
# target runs as the current user and uses the same dev config energie.py reads.

PYTHON  ?= python3
PHPUNIT ?= ./vendor/bin/phpunit
MYSQL   ?= mysql --socket=/tmp/mysql.sock

.PHONY: test test-py test-php test-db-setup test-db-drop

test: test-py test-php

test-py:
	$(PYTHON) -m pytest

test-php:
	$(PHPUNIT)

test-db-setup:
	$(MYSQL) -u root -e "CREATE DATABASE IF NOT EXISTS energie_test CHARACTER SET utf8mb4; \
	                     GRANT ALL PRIVILEGES ON energie_test.* TO 'energie'@'localhost'; \
	                     FLUSH PRIVILEGES;"
	@echo "✅ energie_test ready. Run 'make test' to verify."

test-db-drop:
	$(MYSQL) -u root -e "DROP DATABASE IF EXISTS energie_test;"
	@echo "✅ energie_test removed. Run 'make test-db-setup' to recreate."
