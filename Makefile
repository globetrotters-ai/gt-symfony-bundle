.PHONY: install test test-unit test-integration stan cs cs-fix ci release

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

# Tags vX.Y.Z and pushes it; .github/workflows/release.yml does the rest.
release:
	@if [ -z "$(VERSION)" ]; then echo "❌ Usage: make release VERSION=x.y.z"; exit 1; fi; \
	if ! echo "$(VERSION)" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$$'; then \
		echo "❌ VERSION=$(VERSION) is not x.y.z."; exit 1; \
	fi; \
	if ! grep -q "^## \[$$(echo "$(VERSION)" | sed 's/\./\\./g')\]" CHANGELOG.md; then \
		echo "❌ CHANGELOG.md has no '## [$(VERSION)]' section."; exit 1; \
	fi; \
	if git rev-parse -q --verify "refs/tags/v$(VERSION)" >/dev/null; then \
		echo "❌ Tag v$(VERSION) already exists."; exit 1; \
	fi; \
	git tag -a "v$(VERSION)" -m "v$(VERSION)" && \
	git push origin "v$(VERSION)" && \
	echo "✅ Pushed v$(VERSION). The Release workflow now checks the CHANGELOG, runs CI and publishes the GitHub Release."
