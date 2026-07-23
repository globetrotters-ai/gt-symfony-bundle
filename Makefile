.PHONY: install test test-unit test-integration stan cs cs-fix ci

install:
	composer update --prefer-dist --no-interaction --no-progress

test:
	vendor/bin/phpunit

test-unit:
	vendor/bin/phpunit --testsuite unit

test-integration:
	vendor/bin/phpunit --testsuite integration

stan:
	vendor/bin/phpstan analyse

cs:
	vendor/bin/php-cs-fixer check --diff

cs-fix:
	vendor/bin/php-cs-fixer fix

ci: cs stan test
