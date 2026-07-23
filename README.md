# Globetrotters AI Presence — Symfony Bundle

Serves your [Globetrotters](https://globetrotters.ai) Official AI Presence at your site's apex domain. The bundle pulls the published artefact set from your Globetrotters subdomain on a schedule, caches it, and serves it from a `kernel.request` subscriber that runs **before routing** — so it works even when a reverse proxy, security bundle, or catch-all controller would otherwise intercept `/.well-known/*`, and it never needs filesystem write access.

What your apex serves once installed:

| Path | Content-Type |
|---|---|
| `/llms.txt` | `text/plain; charset=utf-8` |
| `/ai.json` | `application/json; charset=utf-8` |
| `/schema.json` | `application/ld+json; charset=utf-8` |
| `/.well-known/mcp.json` | `application/json; charset=utf-8` |
| `/.well-known/agent-card.json` | `application/json; charset=utf-8` |
| `/.well-known/globetrotters-apex-version.json` | `application/json; charset=utf-8` (version/drift marker) |

Every artefact response carries `X-Content-Type-Options: nosniff` and `Cache-Control: public, max-age=300`. Heavy files (`llms-full.txt`, `content.md`) are intentionally not served locally — they are linked back to Globetrotters by absolute URL.

On top of the routes, the bundle:

- injects a **server-rendered, breakout-safe JSON-LD** `<script>` (built from the cached `schema.json`) into your homepage HTML, so crawlers see it in the raw markup without executing JavaScript;
- decorates `/robots.txt` with the AI-crawler allow-list and a `Sitemap:` directive pointing at your Globetrotters-hosted sitemap (or serves a generated `robots.txt` when your app has none);
- **stale-serves**: the cached bundle is only ever replaced by a fully successful pull, so an unreachable Globetrotters leaves the last known good version serving.

## Requirements

- PHP 8.2+
- Symfony 6.4 LTS or 7.x

## Install

```bash
composer require globetrotters-ai/symfony-bundle
```

If your app doesn't use Symfony Flex, register the bundle manually in `config/bundles.php`:

```php
Globetrotters\AiPresenceBundle\GlobetrottersAiPresenceBundle::class => ['all' => true],
```

## Configure

```yaml
# config/packages/globetrotters_ai_presence.yaml
globetrotters_ai_presence:
    website_url: 'https://your-site.globetrotters.ai'  # the published GT subdomain to pull from
    refresh_interval: 'daily'                          # daily | weekly
    cache_pool: 'cache.app'                            # optional: which PSR-6 pool to use
    homepage_path: '/'                                 # optional: where the JSON-LD head injection applies
```

Then trigger the first pull and verify:

```bash
bin/console gt:refresh --force   # pull now
bin/console gt:status            # installed vs latest version, last refresh, last error
curl -I https://your-domain.example/llms.txt
curl -s https://your-domain.example/.well-known/globetrotters-apex-version.json
```

## Keeping it fresh

Two supported wirings — pick one.

### 1. Cron (default, zero-worker deployments)

Schedule `gt:refresh` from system cron or a systemd timer. The command respects `refresh_interval` internally (it no-ops when a refresh isn't due yet), so running it hourly is safe:

```cron
0 * * * * cd /srv/app && bin/console gt:refresh >/dev/null 2>&1
```

### 2. symfony/scheduler (if you already run Messenger workers)

With `symfony/scheduler` and `symfony/messenger` installed, the bundle auto-registers a schedule named `gt` that dispatches a refresh at the configured cadence. Run it with:

```bash
bin/console messenger:consume scheduler_gt
```

## Twig alternative for the head injection

The JSON-LD injection is automatic on `homepage_path`. If you'd rather place the tag explicitly, use the Twig function in your base template — the automatic injector detects the rendered tag and won't duplicate it:

```twig
{{ gt_ai_presence_head() }}
```

## Caveats

- **Static files shadow the kernel.** If a real file exists in `public/` for one of the artefact paths (or `public/robots.txt`), your web server serves it directly and the bundle never sees the request. Delete the static copies when migrating from the file-drop lane.
- **`cache:clear` empties `cache.app`.** The artefacts then fall through to your app until the next `gt:refresh`. For durability across deploys, point `cache_pool` at a pool that survives cache clears (e.g. a Redis-backed pool).
- **Don't use a per-process pool.** `cache_pool` must be shared between CLI and web (filesystem, Redis, shared APCu) — with an in-memory pool, CLI refreshes would be invisible to web requests.
- The configured `website_url` is fetched with an SSRF guard (private/reserved IPs are rejected), a 5-second timeout, and a 1 MiB per-file size cap.

## Development

```bash
make install   # composer update
make ci        # php-cs-fixer + phpstan + phpunit (unit + integration)
```

The integration suite boots a real `HttpKernel` with a catch-all "antagonist" controller and a network-free fake fetcher, proving route pre-emption, robots decoration, raw-HTML JSON-LD, and stale-serve end to end.
