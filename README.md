# Globetrotters AI Presence — Symfony Bundle

[![Latest Version](https://img.shields.io/packagist/v/globetrotters-ai/symfony-bundle.svg)](https://packagist.org/packages/globetrotters-ai/symfony-bundle)
[![Downloads](https://img.shields.io/packagist/dt/globetrotters-ai/symfony-bundle.svg)](https://packagist.org/packages/globetrotters-ai/symfony-bundle)
[![CI](https://github.com/globetrotters-ai/gt-symfony-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/globetrotters-ai/gt-symfony-bundle/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/globetrotters-ai/symfony-bundle.svg)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

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

One further path is served when Globetrotters has issued your environment an **IndexNow key**: `/<key>.txt`, `text/plain; charset=utf-8`, body the key and nothing else. IndexNow verifies control of a host by reading that file, and it must sit at the apex root — a key under `/.well-known/` would scope submissions to that directory and cover none of the artefacts above. Being able to serve it is what lets new and updated presence content be announced from *your* domain rather than only from the Globetrotters-hosted mirror.

Nothing to configure: the key arrives on the version marker with your normal refresh, is stored in the bundle's runtime state, and is withdrawn on the first refresh whose marker no longer carries it. When there is no key the path is not served at all — your application answers its own 404, never a 200 with an empty body. The key response carries the same `nosniff` and `no-store` headers as the artefacts, and the bundle does **not** add `Access-Control-Allow-Origin` to it: the key is fetched server-side by a search engine, so the cross-origin grant stays scoped to the discovery documents that need it. If your app adds CORS headers to every response, they are left in place rather than stripped — the key is public by construction, so nothing is disclosed by that. Requests for it are not counted in Presence Analytics, which stays a measure of AI agents fetching your discovery files.

A rotated key reaches your install on its next refresh (daily by default), so submissions for your host can fail verification during that window.

One more path is served once a bundle is cached: **`/ai-sitemap.xml`** (`application/xml; charset=utf-8`), a sitemaps.org `<urlset>` over your homepage plus every artefact this install is actually serving, in the URL space of the request it arrives on. It is generated locally rather than fetched, so it can never advertise a URL your site does not answer, and it needs no configuration to know your domain. `robots.txt` declares it, so crawlers discover it the standard way.

**Not `/sitemap.xml`, deliberately.** That path is yours — served by your app, a static file, or redirected onto an SEO bundle's index — and the bundle never claims it. A six-URL artefact listing is not a substitute for your site's sitemap, and robots.txt takes a *list* of `Sitemap:` directives, so this one is additive to whatever you already declare.

The response carries `nosniff` and both `no-store` headers — the document is rendered from the cache and from this request's own host, so a shared TTL could keep advertising a URL a later refresh dropped, or hand a host alias another host's URLs — and no `Access-Control-Allow-Origin`, matching the IndexNow key: a sitemap is fetched server-side by a crawler. Requests for it are not counted in Presence Analytics; a crawler scheduling a fetch is not an agent consuming your presence.

On top of the routes, the bundle:

- **reports agent traffic** to those six paths back to Globetrotters, so an apex install still shows up in Presence Analytics (see [Reporting agent traffic](#reporting-agent-traffic));
- injects a **server-rendered, breakout-safe JSON-LD** `<script>` (built from the cached `schema.json`) into your homepage HTML, so crawlers see it in the raw markup without executing JavaScript;
- decorates `/robots.txt` with the AI-crawler registry plus a `Sitemap:` directive naming **your own host's** `/ai-sitemap.xml` (or serves a generated `robots.txt` when your app has none) — **without changing what any crawler may fetch** unless you opt in (see [robots.txt](#robotstxt));
- **stale-serves**: the cached bundle is only ever replaced by a fully successful pull — every file fetched, and every JSON artefact a well-formed JSON document — so an unreachable Globetrotters, or a proxy answering a maintenance page with `200`, leaves the last known good version serving.

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
    profile: 'full_apex'                               # full_apex | subdomain_breadcrumb — see "Install profiles"

    robots:                                            # optional: see "robots.txt" — the defaults change nothing a crawler may fetch
        ai_agents: 'inherit'                           # inherit | allow_all
        ai_train: false

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

Runs missed while the worker was stopped are collapsed into one on Symfony 7.1+. Symfony 6.4 LTS has no such option and replays them one by one, which is harmless: a refresh is an idempotent re-pull, and a flush that is not yet due sends nothing.

## robots.txt

The bundle appends one block to your `/robots.txt` — or serves it as the whole file when your app has none — naming the AI user agents in the Globetrotters registry, with a `Content-Signal` for them and a `Sitemap:` line for `/ai-sitemap.xml` on the host the request arrived on.

**Naming an agent is not neutral, so by default the block changes nothing a crawler may fetch.** Under RFC 9309 a crawler obeys only the group(s) naming it, and falls back to `User-agent: *` only when none does. A named group saying `Allow: /` would release that agent from every restriction in your wildcard group — WordPress's `Disallow: /wp-admin/`, a staging site's `Disallow: /` — and one added for an agent you already name would merge with your rules for it and outrank them. So:

- **agents your file already names are never added**: your group stays the only one that applies to them. Applebot counts as named when you name Googlebot, whose group Apple documents it follows;
- **every other agent inherits your `User-agent: *` rules**, carried once in a single shared group, so it obeys exactly what it obeyed before. With no wildcard rules (or no `robots.txt` at all) each agent gets `Allow: /`, which is what it already had;
- the signal is `Content-Signal: search=yes, ai-input=yes`, or your wildcard group's own `Content-Signal` when it has one. Nothing is said about training on your behalf.

Anything broader is a decision about your whole site, so it is an explicit opt-in:

```yaml
globetrotters_ai_presence:
    robots:
        ai_agents: 'allow_all'   # every named agent gets "Allow: /", overriding your wildcard
                                 # restrictions for it — never a group you wrote yourself
        ai_train: true           # adds "ai-train=yes" to the Content-Signal line
```

With both, and no wildcard rules of your own, the block is byte-for-byte the one Globetrotters serves on its own hosts. A `Content-Signal` your wildcard group declares is kept either way: `allow_all` grants crawl permission, and does not overwrite what you have said about how your content may be used.

The block never adds a `User-agent: *` group, which would be combined with yours. `HEAD /robots.txt` is answered with no body but with the decorated `GET`'s `ETag` and `Content-Length` (and, like the `GET`, no `Last-Modified`), so a `HEAD` can never invalidate a cached `GET`.

## Reporting agent traffic

An apex install is **pull-and-cache, not proxy**. A request to `https://your-domain.example/llms.txt` is served by this bundle and terminates inside your application — it never touches a Globetrotters edge, so without this it is invisible and your apex looks like it gets no agent traffic at all.

Turn it on by pasting the two values Studio issues together on the apex install screen (the token is shown **exactly once**):

```bash
# .env.local, or better, the Secrets vault: bin/console secrets:set GLOBETROTTERS_INGEST_TOKEN
GLOBETROTTERS_INGEST_ENDPOINT=https://api.globetrotters.ai/presence/analytics/server-log
GLOBETROTTERS_INGEST_TOKEN=…
```

The endpoint must be `https://`: it receives the token and every client IP. A literal `http://` value fails the container build; an env-bound one reads as unset at runtime, so reporting stays off and `gt:status` says why.

Both are required; until both are set nothing is captured and nothing is written to disk. What is sent, per served artefact request: a UUID, a UTC timestamp, the canonical path, the User-Agent, the client IP, the referer, the status and the byte size. No cookies, no per-visitor identifiers. The backend uses the IP transiently to verify the agent against published vendor ranges and to resolve a country, then drops it — it is never stored.

### Scheduling the flush

Events are buffered locally and flushed at most every 15 minutes. Three lanes, all sharing one interval, so whichever you have wins and the others stay dormant. The interval is checked under the flush lock, so two lanes firing a second apart never both send; `gt:presence:flush --force` skips the interval but never the lock.

**1. Cron (recommended).** The command enforces the 15-minute cadence itself, so running it more often is safe:

```cron
*/5 * * * * cd /srv/app && bin/console gt:presence:flush >/dev/null 2>&1
```

**2. symfony/scheduler.** With `symfony/scheduler` and `symfony/messenger` installed the `gt` schedule asks for a flush every 5 minutes, alongside the artefact refresh, and the shared interval decides — so, like the cron line above, flushes land every 15 to 20 minutes (`bin/console messenger:consume scheduler_gt`).

**3. `kernel.terminate` fallback (on by default, where the runtime allows).** For a shared host with no cron and no worker: a request for one of the six artefacts triggers at most one flush per 15 minutes, after its response has been sent. Your own pages, `robots.txt`, `/ai-sitemap.xml` and the IndexNow key never trigger it.

It only runs where PHP delivers the response before `kernel.terminate`: **PHP-FPM, FrankenPHP and LiteSpeed**. On Apache mod_php, the CLI server and other runtimes the visitor would still be waiting, so the lane stays off there and you need lane 1 or 2. Even where it runs, the visitor has their response but the PHP worker stays busy for the ingest call (up to 20 seconds) and serves no one else meanwhile — on a small worker pool, prefer cron. Set `reporting.opportunistic_flush: false` to disable it.

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
        opportunistic_flush: true                            # the kernel.terminate lane (PHP-FPM, FrankenPHP, LiteSpeed)
        trust_cloudflare_header: false                       # read CF-Connecting-IP
```

## Install profiles

`profile` mirrors the install profile shown in the Studio.

| Profile | What this site does |
|---|---|
| `full_apex` (default) | This site **is** the presence. Artefacts served here, JSON-LD inlined, nothing pointing elsewhere. |
| `subdomain_breadcrumb` | **Both lanes**, and what the product recommends. Everything `full_apex` does, **plus** a link back to the presence published at your Globetrotters host. |

### Why the second lane exists

If you also publish at `ai.<your-domain>` (or `<slug>.globetrotters.ai`), that host is a **separate site** to every crawler. It inherits none of your apex's index membership, crawl budget or authority, and nothing on the public web points at it — so it is reached only by something that already knows the hostname. `subdomain_breadcrumb` fixes that from the one place that already has the authority: your own homepage.

It injects discovery `<link>` relations into `<head>`, and **nothing else**:

```html
<!-- Globetrotters — AI presence -->
<link rel="alternate" type="application/ld+json" href="/schema.json">
<link rel="ai-catalog" href="https://ai.your-domain.example/.well-known/ai-catalog.json">
<link rel="mcp" href="/.well-known/mcp.json">
<link rel="agent-card" href="/.well-known/agent-card.json">
```

`rel="alternate"` appears on `homepage_path` only — it points at a document describing the destination, so claiming it as an interior page's alternate would assert something untrue about that page. The three agent-discovery relations name where your *site's* surfaces live, which is equally true from every page, so they are emitted on **every HTML page**: an agent that arrives on a deep page is exactly the case that needs a pointer.

**Installing this bundle is transparent to your visitors.** It never injects anything visible: it does not know your layout, and has no safe position to put an element into a design it does not own. Head markup only.

That has a real cost, worth stating plainly: a `<link>` is a discovery *pointer*, not a followed link, so on its own it does not pass the crawl authority that would let the subdomain inherit your apex's standing. A visible anchor is what does that — so the bundle hands you one to place yourself, inside your own layout:

```twig
{{ gt_ai_presence_breadcrumb_link() }}   {# renders <a href="https://ai.your-domain.example">…</a> #}
```

Set `breadcrumb.anchor_text` to control its wording. It is your markup, in your template, where you can see and style it.

**Each relation points at the canonical copy of its own file.** Anything this install serves itself (the six paths above) is linked root-relative, so agents are sent to *your* domain — the copy carrying your index membership, not the mirror. Only `.well-known/ai-catalog.json` names the Globetrotters host, because the apex bundle does not contain it. The split follows the served set, so a file added there starts resolving locally on its own.

```yaml
globetrotters_ai_presence:
    profile: 'subdomain_breadcrumb'
    breadcrumb:
        anchor_text: ''   # optional: wording for the anchor you place yourself.
                          # Defaults to "AI presence for <destination>" from ai.json.
                          # It lands on your own pages, so set it in your site's language.
```

**You never configure the host.** It is derived from the cached `ai.json` on every refresh, so when a custom hostname activates (or is detached) the links follow it on the next ordinary refresh — no config change, no redeploy. A configured host would keep resolving after such a flip while pointing at the wrong place, which is the one failure you would never notice.

Nothing is injected when no host can be derived: a breadcrumb to nowhere is worse than none.

**The locally served paths are identical on both profiles.** All six stay at your apex either way — the backend's split is an *offload* list, and none of them is on it. This profile changes the breadcrumb, not the footprint.

## Twig alternatives to the automatic injection

Each injection is automatic on `homepage_path`. If you'd rather place markup explicitly, use these in your base template — the automatic injectors detect what you rendered and won't duplicate it:

```twig
{{ gt_ai_presence_head() }}             {# the JSON-LD tag, any profile #}

{# subdomain_breadcrumb only; both render '' on other profiles #}
{{ gt_ai_presence_breadcrumb_head() }}  {# in <head> #}
{{ gt_ai_presence_breadcrumb_link() }}  {# in your footer #}
```

`gt_ai_presence_breadcrumb_link()` is the only way the visible anchor ever reaches a page — there is no setting that makes the bundle place it for you.

## Caveats

- **Static files shadow the kernel.** If a real file exists in `public/` for one of the artefact paths (or `public/robots.txt`), your web server serves it directly and the bundle never sees the request. Delete the static copies when migrating from the file-drop lane.
- **`cache:clear` empties `cache.app`.** The artefacts then fall through to your app until the next `gt:refresh`, and the reporting lane forgets when it last flushed successfully — buffered events themselves live in `buffer_dir` and survive. For durability across deploys, point `cache_pool` at a pool that survives cache clears (e.g. a Redis-backed pool).
- **Don't use a per-process pool.** `cache_pool` must be shared between CLI and web (filesystem, Redis, shared APCu) — with an in-memory pool, CLI refreshes would be invisible to web requests.
- **Changing or clearing `website_url` stops serving the previous source at once.** The cached bundle records the URL it was pulled from and is served only while that URL is configured. After a change, the next `gt:refresh` drops it and pulls the new source straight away, whatever the interval; a process with no `website_url` never deletes it. On the Scheduler lane, run `gt:refresh --force` after the deploy, or the new source waits for the next scheduled refresh.
- The configured `website_url` is fetched with an SSRF guard (private/reserved IPs are rejected), a 5-second timeout, and a 1 MiB per-file size cap.
- **Reporting needs a writable `buffer_dir`**, shared by the web user and whoever runs the flush — the rest of the bundle needs no filesystem write access, and an install that doesn't report never creates the directory. It holds at most 5000 events or 512KB; past that the oldest are dropped and counted, and the count is reported so the gap is visible rather than silent. `gt:status` shows both.
- **The breadcrumb needs a `</head>` in the response.** The block is inserted before the closing tag, so a page that streams, is served from a static cache, or omits `</head>` gets nothing — place it with `gt_ai_presence_breadcrumb_head()` instead.
- **Conditional GETs survive injection; `Last-Modified` does not.** Rewriting a body makes metadata describing the original representation untrue. The `ETag` is therefore **recomputed** from the injected bytes — preserving your weak/strong flavour — and revalidated once, after every injection has run, so a client holding what was actually served still gets a `304` while one holding a half-injected body correctly gets a fresh `200`. A response that published no `ETag` is left without one; the bundle will not invent a caching contract you did not opt into. `Last-Modified` is dropped rather than restamped: a changed body says nothing about when the underlying resource changed.
- **An accepted flush is not proof the token is right.** The ingest endpoint answers `202` to a bad token, an unknown install and a malformed body alike, deliberately revealing nothing about which tokens exist. `gt:status` distinguishes "configured but never accepted" from "reporting normally", but confirm the numbers in Studio.

## Development

```bash
make install   # composer update
make ci        # php-cs-fixer + phpstan + phpunit (unit + integration)
```

The integration suite boots a real `HttpKernel` with a catch-all "antagonist" controller and a network-free fake fetcher, proving route pre-emption, robots decoration, raw-HTML JSON-LD, and stale-serve end to end. It also covers the reporting lane: capture through a live kernel, the console and `kernel.terminate` flush lanes, the buffer under forked concurrent writers, and the no-store headers surviving Symfony's own `HttpCache`.

## License

Released under the [MIT License](LICENSE). © 2026 Globetrotters.ai
