# Globetrotters AI Presence — Symfony Bundle

Serves your [Globetrotters](https://globetrotters.ai) Official AI Presence at your site's apex domain. The bundle pulls the published artefact set from your Globetrotters subdomain on a schedule, caches it, and serves it from a `kernel.request` subscriber that runs **before routing** — so it works even when a reverse proxy, security bundle, or catch-all controller would otherwise intercept `/.well-known/*`, and serving needs no filesystem write access.

What your apex serves once installed:

| Path | Content-Type |
|---|---|
| `/llms.txt` | `text/plain; charset=utf-8` |
| `/ai.json` | `application/json; charset=utf-8` |
| `/schema.json` | `application/ld+json; charset=utf-8` |
| `/.well-known/mcp.json` | `application/json; charset=utf-8` |
| `/.well-known/agent-card.json` | `application/json; charset=utf-8` |
| `/.well-known/globetrotters-apex-version.json` | `application/json; charset=utf-8` (version/drift marker) |

Every artefact response carries `X-Content-Type-Options: nosniff`, `Cache-Control: no-store, private`, `Surrogate-Control: no-store` and `Access-Control-Allow-Origin: *` (every artefact is public, unauthenticated, read-only metadata, so it's readable cross-origin by browser-context agent clients). Heavy files (`llms-full.txt`, `content.md`) are intentionally not served locally — they are linked back to Globetrotters by absolute URL.

On top of the routes, the bundle:

- **reports agent traffic** to those six paths back to Globetrotters, so an apex install still shows up in Presence Analytics (see [Reporting agent traffic](#reporting-agent-traffic));
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

    reporting:                                         # optional: see "Reporting agent traffic"
        endpoint: '%env(GLOBETROTTERS_INGEST_ENDPOINT)%'
        ingest_token: '%env(GLOBETROTTERS_INGEST_TOKEN)%'
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

## Reporting agent traffic

An apex install is **pull-and-cache, not proxy**. A request to `https://your-domain.example/llms.txt` is served by this bundle and terminates inside your application — it never touches a Globetrotters edge, so without this it is invisible and your apex looks like it gets no agent traffic at all.

Turn it on by pasting the two values Studio issues together on the apex install screen (the token is shown **exactly once**):

```bash
# .env.local, or better, the Secrets vault: bin/console secrets:set GLOBETROTTERS_INGEST_TOKEN
GLOBETROTTERS_INGEST_ENDPOINT=https://api.globetrotters.ai/presence/analytics/server-log
GLOBETROTTERS_INGEST_TOKEN=…
```

Both are required; until both are set nothing is captured and nothing is written to disk. What is sent, per served artefact request: a UUID, a UTC timestamp, the canonical path, the User-Agent, the client IP, the referer, the status and the byte size. No cookies, no per-visitor identifiers. The backend uses the IP transiently to verify the agent against published vendor ranges and to resolve a country, then drops it — it is never stored.

### Scheduling the flush

Events are buffered locally and flushed at most every 15 minutes. Three lanes, all sharing one interval, so whichever you have wins and the others stay dormant.

**1. Cron (recommended).** The command enforces the 15-minute cadence itself, so running it more often is safe:

```cron
*/5 * * * * cd /srv/app && bin/console gt:presence:flush >/dev/null 2>&1
```

**2. symfony/scheduler.** With `symfony/scheduler` and `symfony/messenger` installed the `gt` schedule dispatches a flush every 15 minutes, alongside the artefact refresh (`bin/console messenger:consume scheduler_gt`).

**3. `kernel.terminate` fallback (on by default).** For a shared host with no cron and no worker: after a response has been sent, an artefact request triggers at most one flush per 15 minutes. Because it runs post-response it costs the visitor nothing. Set `reporting.opportunistic_flush: false` to disable it.

`bin/console gt:status` reports which lane last flushed, how many events are buffered, how many were dropped, and whether client-IP resolution looks trustworthy.

### Behind a proxy or CDN

The client IP is what lets the backend confirm that a claimed ClaudeBot hit really came from Anthropic. Resolution uses Symfony's own [`framework.trusted_proxies`](https://symfony.com/doc/current/deployment/proxies.html) — **without it every hit reports your proxy's address and every row is recorded unverified.** `gt:status` flags this.

Behind Cloudflare, also set `reporting.trust_cloudflare_header: true` to read `CF-Connecting-IP`. It is off by default and only honoured for requests arriving from a declared trusted proxy: the header is forgeable, and trusting it blindly would let anyone claim a vendor IP.

### Full reporting options

```yaml
globetrotters_ai_presence:
    reporting:
        enabled: true                                        # kill switch for a configured install
        endpoint: '%env(GLOBETROTTERS_INGEST_ENDPOINT)%'
        ingest_token: '%env(GLOBETROTTERS_INGEST_TOKEN)%'
        buffer_dir: '%kernel.project_dir%/var/globetrotters-ai-presence'
        opportunistic_flush: true                            # the kernel.terminate lane
        trust_cloudflare_header: false                       # read CF-Connecting-IP
```

## Twig alternative for the head injection

The JSON-LD injection is automatic on `homepage_path`. If you'd rather place the tag explicitly, use the Twig function in your base template — the automatic injector detects the rendered tag and won't duplicate it:

```twig
{{ gt_ai_presence_head() }}
```

## Caveats

- **Static files shadow the kernel.** If a real file exists in `public/` for one of the artefact paths (or `public/robots.txt`), your web server serves it directly and the bundle never sees the request. Delete the static copies when migrating from the file-drop lane.
- **`cache:clear` empties `cache.app`.** The artefacts then fall through to your app until the next `gt:refresh`, and the reporting lane forgets when it last flushed successfully — buffered events themselves live in `buffer_dir` and survive. For durability across deploys, point `cache_pool` at a pool that survives cache clears (e.g. a Redis-backed pool).
- **Don't use a per-process pool.** `cache_pool` must be shared between CLI and web (filesystem, Redis, shared APCu) — with an in-memory pool, CLI refreshes would be invisible to web requests.
- The configured `website_url` is fetched with an SSRF guard (private/reserved IPs are rejected), a 5-second timeout, and a 1 MiB per-file size cap.
- **Reporting needs a writable `buffer_dir`**, shared by the web user and whoever runs the flush — the rest of the bundle needs no filesystem write access, and an install that doesn't report never creates the directory. It holds at most 5000 events or 512KB; past that the oldest are dropped and counted, and the count is reported so the gap is visible rather than silent. `gt:status` shows both.
- **An accepted flush is not proof the token is right.** The ingest endpoint answers `202` to a bad token, an unknown install and a malformed body alike, deliberately revealing nothing about which tokens exist. `gt:status` distinguishes "configured but never accepted" from "reporting normally", but confirm the numbers in Studio.

## Development

```bash
make install   # composer update
make ci        # php-cs-fixer + phpstan + phpunit (unit + integration)
```

The integration suite boots a real `HttpKernel` with a catch-all "antagonist" controller and a network-free fake fetcher, proving route pre-emption, robots decoration, raw-HTML JSON-LD, and stale-serve end to end. It also covers the reporting lane: capture through a live kernel, the console and `kernel.terminate` flush lanes, the buffer under forked concurrent writers, and the no-store headers surviving Symfony's own `HttpCache`.
