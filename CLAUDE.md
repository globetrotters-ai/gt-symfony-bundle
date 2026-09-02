# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
make install            # composer update --prefer-dist (this is a library: composer.lock is gitignored)
make test               # full phpunit suite
make test-unit          # tests/Unit only
make test-integration   # tests/Integration only (boots tests/Fixtures/TestKernel.php)
make stan               # phpstan, level 8, src/ only
make cs                 # php-cs-fixer check --diff
make cs-fix             # php-cs-fixer fix
make ci                 # cs + stan + test, the same gates CI runs
```

CI (`.github/workflows/ci.yml`) has a `lint` job (php-cs-fixer + phpstan on PHP 8.3) and a `tests` matrix of PHP 8.2/8.3/8.4 crossed with Symfony 6.4.*/7.4.*, pinned via `SYMFONY_REQUIRE` and flex. A change that only passes on one Symfony major will fail the matrix, so check both branches when touching anything version-sensitive.

Style is `@Symfony` + `@Symfony:risky` + `declare_strict_types`, applied to `src/`, `config/` and `tests/`. Run `make cs-fix` before committing rather than hand-formatting.

## Architecture

A Symfony bundle (`globetrotters-ai/symfony-bundle` on Packagist) that serves a site's Official AI Presence at its **apex domain**. It pulls the published artefact set from the customer's Globetrotters subdomain on a schedule, caches it in a PSR-6 pool, and serves it. It is the Symfony counterpart of `gt-wordpress-plugin/`, and the two should stay behaviourally aligned.

Namespace is `Globetrotters\AiPresenceBundle\` (PSR-4 from `src/`). The config key and extension alias are `globetrotters_ai_presence`, derived from the bundle class name.

`src/GlobetrottersAiPresenceBundle.php` is the composition root: it holds the whole config tree (an `AbstractBundle`, so no separate `Extension`/`Configuration` classes) and delegates service wiring to `config/services.php`, `config/analytics.php`, `config/scheduler.php` and `config/twig.php`.

Module map:

| Directory | Role |
|---|---|
| `src/Serving/` | The request path: `Router` plus the response/terminate subscribers |
| `src/Client/`, `src/Sync/`, `src/Cache/` | Pull artefacts from the subdomain, validate, cache |
| `src/Analytics/` | Agent-traffic reporting: NDJSON buffer, flush gate, ingest client |
| `src/Command/` | `gt:refresh`, `gt:status`, `gt:presence:flush` |
| `src/Scheduler/` | `symfony/scheduler` alternative to cron for refresh and flush |
| `src/Twig/` | `gt_ai_presence_head()` for explicit JSON-LD placement |

### Request lifecycle

Ordering is load-bearing and every priority below is a deliberate choice, not a default:

| Event | Class | Priority | Why |
|---|---|---|---|
| `kernel.request` | `Serving\Router` | 64 | Ahead of `RouterListener` (32) and the security firewall (8) so a catch-all controller or security bundle cannot claim the artefact paths; behind `ValidateRequestListener` (256) so trusted-host validation still runs |
| `kernel.response` | `Serving\HeadInjector` | -10 | Injects server-rendered JSON-LD into the homepage HTML |
| `kernel.response` | `Serving\RobotsFilter` | -20 | Decorates or generates `/robots.txt` |
| `kernel.response` | `Serving\ArtefactHeaderSubscriber` | -1024 | Runs *last* on purpose, so it re-asserts `Router::NO_STORE_HEADERS` after anything downstream has had its chance to rewrite them |
| `kernel.terminate` | `Serving\ArtefactCaptureSubscriber` | 0 | Records the served request, after the response is sent |
| `kernel.terminate` | `Serving\OpportunisticFlushSubscriber` | -256 | Flushes after the capture above, at most every 15 min, for hosts with no cron and no Messenger worker |

`Router` sets the response and stops propagation. A path miss or cold cache returns without touching the response so the app handles the request normally. Requests it serves are tagged with `Router::ATTRIBUTE_PATH` / `ATTRIBUTE_BYTES`, which is how the two later subscribers recognise them, so those attribute constants are an internal contract between four files.

## Invariants worth preserving

- **Stale-serve.** The cached bundle is only ever replaced by a *fully successful* pull. A partial or failed refresh must leave the last known good version serving. `tests/Integration/RefreshStaleServeTest.php` guards this.
- **Serving needs no filesystem write access.** Only reporting writes (to `reporting.buffer_dir`). Do not introduce a write on the serving path.
- **`website_url` is untrusted input.** It is fetched through `NoPrivateNetworkHttpClient` (wired in `config/services.php`) so a configured URL cannot be pointed at a private or reserved IP, including across redirects. Keep any new outbound fetch on that client, not on raw `http_client`.
- **`reporting.ingest_token` is deliberately not a container parameter.** It is injected straight into `AnalyticsOptions`, because a parameter would land in the compiled container's parameter bag and in `debug:container --parameters`. See the comment in `loadExtension()` before changing how it is wired.
- **Header text is duplicated on purpose.** `Router::NO_STORE_HEADERS` is written in served order so the code, the README table and the tests all read identically. Change all three together.
- **Heavy files stay remote.** `llms-full.txt` and `content.md` are linked back to Globetrotters by absolute URL, never served locally.

## Releasing

The version lives in three places that must move together: `GlobetrottersAiPresenceBundle::VERSION`, the `CHANGELOG.md` heading, and the `extra.branch-alias.dev-main` constraint in `composer.json`. Packagist publishes from the git tag, so tag only after all three agree. CHANGELOG follows Keep a Changelog and the project is on semver (currently 0.2.0, first public release 2026-08-25).

## Local testing against a real app

`../gt-symfony-bundle-testbed/` is a throwaway Symfony 7.4 skeleton (local only, no git remote) that exists purely to host this bundle in a real application. Use it for anything the integration suite cannot reach, such as interaction with a real reverse proxy or another bundle's listeners. Nothing in the testbed is a deliverable and it is safe to reset.
