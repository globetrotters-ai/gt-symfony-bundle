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
| `/.well-known/ai-catalog.json` | `application/json; charset=utf-8` (since 0.7.0) |
| `/.well-known/agent-skills/index.json` | `application/json; charset=utf-8` (since 0.7.0; also at `/.well-known/agent-skills` and `/.well-known/agent-skills/` since 0.9.1) |
| `/agent.json` | `application/json; charset=utf-8` (since 0.7.0) |
| `/AGENTS.md` | `text/markdown; charset=utf-8` (since 0.7.0; also at `/agents.md` since 0.9.1) |
| `/.well-known/globetrotters-apex-version.json` | `application/json; charset=utf-8` (version/drift marker) |
| `/.well-known/api-catalog` | `application/linkset+json` (since 0.8.2, generated locally, see below) |

Every artefact response carries `X-Content-Type-Options: nosniff`, `Cache-Control: no-store, private`, `Surrogate-Control: no-store` and `Access-Control-Allow-Origin: *` (every artefact is public, unauthenticated, read-only metadata, so it's readable cross-origin by browser-context agent clients). Heavy files (`llms-full.txt`, `content.md`) are intentionally not served locally — they are linked back to Globetrotters by absolute URL.

One further path is served when Globetrotters has issued your environment an **IndexNow key**: `/<key>.txt`, `text/plain; charset=utf-8`, body the key and nothing else. IndexNow verifies control of a host by reading that file, and it must sit at the apex root — a key under `/.well-known/` would scope submissions to that directory and cover none of the artefacts above. Being able to serve it is what lets new and updated presence content be announced from *your* domain rather than only from the Globetrotters-hosted mirror.

Nothing to configure: the key arrives on the version marker with your normal refresh, is stored in the bundle's runtime state, and is withdrawn on the first refresh whose marker no longer carries it. When there is no key the path is not served at all — your application answers its own 404, never a 200 with an empty body. The key response carries the same `nosniff` and `no-store` headers as the artefacts, and the bundle does **not** add `Access-Control-Allow-Origin` to it: the key is fetched server-side by a search engine, so the cross-origin grant stays scoped to the discovery documents that need it. If your app adds CORS headers to every response, they are left in place rather than stripped — the key is public by construction, so nothing is disclosed by that. Requests for it are not counted in Presence Analytics, which stays a measure of AI agents fetching your discovery files.

A rotated key reaches your install on its next refresh (daily by default), so submissions for your host can fail verification during that window.

**`/.well-known/api-catalog`** is an [RFC 9727](https://www.rfc-editor.org/rfc/rfc9727) API catalog: a linkset anchored on your homepage that points agents at `/llms.txt` (`service-doc`) and at the MCP card, the agent card and the AI catalog (`service-desc`), and lists your MCP server, the first remote in the cached MCP card, under `item` (since 0.9.1). Like the sitemap below it is generated from the cache on every request, in the URL space of the request it arrives on, and lists only what this install serves; with no bundle cached the path is left to your application. It carries the artefacts' headers, `Access-Control-Allow-Origin: *` included, and agent requests for it are counted in Presence Analytics.

One more path is served once a bundle is cached: **`/ai-sitemap.xml`** (`application/xml; charset=utf-8`), a sitemaps.org `<urlset>` over your homepage plus every artefact this install is actually serving, in the URL space of the request it arrives on. It is generated locally rather than fetched, so it can never advertise a URL your site does not answer, and it needs no configuration to know your domain. `robots.txt` declares it, so crawlers discover it the standard way.

**Not `/sitemap.xml`, deliberately.** That path is yours — served by your app, a static file, or redirected onto an SEO bundle's index — and the bundle never claims it. An artefact listing is not a substitute for your site's sitemap, and robots.txt takes a *list* of `Sitemap:` directives, so this one is additive to whatever you already declare.

The response carries `nosniff` and both `no-store` headers — the document is rendered from the cache and from this request's own host, so a shared TTL could keep advertising a URL a later refresh dropped, or hand a host alias another host's URLs — and no `Access-Control-Allow-Origin`, matching the IndexNow key: a sitemap is fetched server-side by a crawler. Requests for it are not counted in Presence Analytics; a crawler scheduling a fetch is not an agent consuming your presence.

On top of the routes, the bundle:

- **reports agent traffic** to those paths back to Globetrotters, so an apex install still shows up in Presence Analytics (see [Reporting agent traffic](#reporting-agent-traffic));
- optionally **counts human page views per page** of your site, first-party and cookie-free, so Presence Analytics can set agent traffic against it and show which views came from an AI answer that cited your site — **off by default** (see [Counting human page views](#counting-human-page-views-optional));
- injects a **server-rendered, breakout-safe JSON-LD** `<script>` (built from the cached `schema.json`) into your homepage HTML, so crawlers see it in the raw markup without executing JavaScript;
- adds a homepage **`Link` header** naming the API catalog and `llms.txt` under the IANA-registered `api-catalog` and `service-doc` relations validators look for, then the MCP card, the agent card and the AI catalog, the HTTP twin of the `<link>` tags below: on a `200` HTML response for `homepage_path` only, added next to any `Link` header your app already sends, never replacing it:

  ```
  Link: </.well-known/api-catalog>; rel="api-catalog", </llms.txt>; rel="service-doc", </.well-known/mcp.json>; rel="mcp", </.well-known/agent-card.json>; rel="agent-card", </.well-known/ai-catalog.json>; rel="ai-catalog"
  ```

- decorates `/robots.txt` with the AI-crawler registry plus a `Sitemap:` directive naming **your own host's** `/ai-sitemap.xml` (or serves a generated `robots.txt` when your app has none) — **without changing what any crawler may fetch** unless you opt in (see [robots.txt](#robotstxt));
- **stale-serves**: the cached bundle is only ever replaced by a fully successful pull — every file fetched, and every JSON artefact a well-formed JSON document — so an unreachable Globetrotters, or a proxy answering a maintenance page with `200`, leaves the last known good version serving.

## Requirements

- PHP 8.2+
- Symfony 6.4 LTS or 7.x

## Before you install

Four questions about the hosting decide the configuration. Each answer below says what to set, or that the default already fits.

**1. One web server, or several behind a load balancer?**
The default [cache pool](#cache-pool) is the bundle's own filesystem pool, under `var/globetrotters-ai-presence/cache`: local to each server, so a refresh fills only the server it runs on. With several servers, set `cache_pool` to a pool they all share, such as Redis, and run the refresh on one of them. With one server the default works; on a deploy that switches release directories (Deployer, Capistrano), list `var/globetrotters-ai-presence` as a shared directory, or each release starts with an empty pool.

**2. Do cron (or the Messenger worker) and PHP-FPM run as the same OS user?**
The refresh writes the [cache pool](#cache-pool) and the web server reads it, and with [reporting](#reporting-agent-traffic) configured both write `buffer_dir`. If they are the same user, nothing to do. If they differ, add both users to one group: the bundle creates both directories `2770` (setgid) and their files `0660`, so a shared group is all it needs. Either way, on a release-directory deploy, list `var/globetrotters-ai-presence` (the pool and the default `buffer_dir`) as a shared directory. `gt:status`, run as each user, reports a file that user cannot write.

**3. Cron or symfony/scheduler?**
Either works, but pick one: the two do the same job (a second lane running at the same moment skips, see [Keeping it fresh](#keeping-it-fresh)). Pick cron when the host has no Messenger worker: add the hourly `gt:refresh` line and the five-minute `gt:presence:flush` line. Pick [symfony/scheduler](#2-symfonyscheduler-if-you-already-run-messenger-workers) when a worker already runs. With neither, the opportunistic flush still sends reporting on PHP-FPM, FrankenPHP and LiteSpeed, but nothing refreshes the artefacts: they update only when `gt:refresh` runs, so make it a deploy step and expect published changes to reach the apex only at your next deploy.

**4. Is the site behind a reverse proxy, load balancer or CDN that terminates TLS?**
If so, set [`framework.trusted_proxies`](https://symfony.com/doc/current/deployment/proxies.html) and make sure the proxy forwards the original `Host`. The bundle builds absolute URLs from the request: the robots.txt `Sitemap:` line, every `/ai-sitemap.xml` entry and every link in `/.well-known/api-catalog`. Without `trusted_proxies` those URLs say `http://` and reporting records the proxy's IP instead of the agent's. If the proxy also rewrites `Host`, they name the backend's internal host, and the page-view counter's same-origin check rejects every view. Behind Cloudflare, also set `reporting.trust_cloudflare_header: true` (see [Behind a proxy or CDN](#behind-a-proxy-or-cdn)).

After installing, `bin/console gt:status` (add `--remote` to also query Globetrotters for the latest version) shows whether the bundle is connected, whether a bundle is cached, and how reporting is doing.

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
    website_url: 'https://your-site.globetrotters.ai'  # the published GT subdomain to pull from; must be https://
    refresh_interval: 'daily'                          # daily | weekly
    cache_pool: 'globetrotters_ai_presence.cache'      # optional: the default; see "Cache pool" before changing it
    homepage_path: '/'                                 # optional: where the JSON-LD head injection applies
    profile: 'full_apex'                               # full_apex | subdomain_breadcrumb — see "Install profiles"

    robots:                                            # optional: see "robots.txt" — the defaults change nothing a crawler may fetch
        ai_agents: 'inherit'                           # inherit | allow_all
        ai_train: false

    reporting:                                         # optional: see "Reporting agent traffic"
        endpoint: '%env(GLOBETROTTERS_INGEST_ENDPOINT)%'     # the default since 0.7.1; declare only to read another variable
        ingest_token: '%env(GLOBETROTTERS_INGEST_TOKEN)%'
```

Then trigger the first pull and verify:

```bash
bin/console gt:refresh --force   # pull now
bin/console gt:status            # installed vs latest version, last refresh, last error
curl -I https://your-domain.example/llms.txt
curl -s https://your-domain.example/.well-known/globetrotters-apex-version.json
```

Then click **Check install** in Studio's Deploy step. Studio reads that version marker off your site, checks that it names your organisation's destination and compares it with your latest publish (a newer publish shows as an update that the next refresh, or `gt:refresh --force`, picks up). When your organisation has verified this domain, the same check marks the install verified, which is what lets Studio issue the [reporting](#reporting-agent-traffic) token. No DNS record or extra file is needed. If your site redirects (bare domain to `www.`, say), Studio offers to switch to the address that actually serves.

## Keeping it fresh

Two supported wirings — **pick one lane**. Cron and the Scheduler do the same job, and two runners firing together would each pull the whole artefact set. The refresh takes a lock (`buffer_dir/refresh.lock`, the same kind the flush uses), so if two runs meet anyway — both lanes, two cron entries, a deploy hook firing during the cron run — the second one exits at once with `Another refresh is already running` and status 0, and the next one finds the refresh not due. The lock is per server: with several servers, schedule the refresh on one.

### 1. Cron (default, zero-worker deployments)

Schedule `gt:refresh` from system cron or a systemd timer. The command respects `refresh_interval` internally (it no-ops when a refresh isn't due yet), so running it hourly is safe:

```cron
0 * * * * cd /srv/app && bin/console gt:refresh >/dev/null 2>&1
```

### 2. symfony/scheduler (if you already run Messenger workers)

With `symfony/scheduler` and `symfony/messenger` installed, the bundle auto-registers a schedule named `gt` that asks for a refresh every hour and pulls only when `refresh_interval` has elapsed — the same due-check as the hourly cron line above. A worker started on an empty cache (after a deploy or `cache:clear`) therefore refills it within the hour, and a failed pull is retried hourly. Run it with:

```bash
bin/console messenger:consume scheduler_gt
```

Runs missed while the worker was stopped are collapsed into one on Symfony 7.1+. Symfony 6.4 LTS has no such option and replays them one by one, which is harmless: a refresh or a flush that is not yet due does nothing.

<a id="cache-pool"></a>

## Cache pool

The refresh and the serving are two processes: `gt:refresh` runs from cron (or the Scheduler's worker), and PHP-FPM serves. The cache pool is the only thing that carries a pulled bundle from the first to the second, so **every process must read the same pool**. If they don't, the refresh succeeds and reports the new version, and the apex keeps serving the bundle it had, for good.

**The default is the bundle's own pool, `globetrotters_ai_presence.cache`**: a filesystem pool under `%kernel.project_dir%/var/globetrotters-ai-presence/cache`. Leave `cache_pool` unset to use it. It is outside `var/cache`, so neither `cache:clear` nor a deploy that empties `var/cache` drops the bundle, and it is shared like `buffer_dir`: directories `2770` (setgid, so files take the directory's group) and files `0660`, whatever the umask, so a cron user and a web user in one group can both use it. Nothing in it is world-readable. On a threaded server (FrankenPHP in thread mode) the umask is left alone, since it is process-wide there; run the refresh as the server's user.

**Why not `cache.app`.** Before 0.9.4 the default was `cache.app`, and an application is free to map `cache.app` to APCu. APCu is memory private to one process: PHP-FPM's pool has one, each CLI run has its own (or none), so a cron refresh writes where the web never reads. `cache.app` also lives in `var/cache`, which `cache:clear` and every deploy empty. `cache.system` has the same problem: it puts an APCu tier in front of its files wherever APCu is enabled.

**Pools that are refused.** An APCu pool, an in-memory array pool, `cache.system`, and any chain or tag-aware pool with one of those in it fail the container build, naming the pool and this section. Nothing at runtime can tell such an install from a healthy one, so it never goes live.

**Choosing another pool.** Set `cache_pool` to a pool every process reads:

- **Several servers:** a Redis (or Memcached, or database) pool they all share, e.g. `framework.cache.pools.presence.pool: { adapter: cache.adapter.redis }` and `cache_pool: 'presence.pool'`. Run the refresh on one server only.
- **Several checkouts or releases** (Deployer, Capistrano, blue/green): the default pool sits under the project directory, so each release has its own. List `var/globetrotters-ai-presence` as a shared directory, and make sure cron runs in the live release (`cd /srv/app/current`), not a path pinned to an old one — a cron entry still pointing at an old release refreshes that release's pool while the web serves the new one.
- **Different OS users:** put the cron user and the web user in one group. With a pool of your own (a filesystem pool you declared), its files follow the umask, so give that pool's directory the group yourself.

**Checking it.** Every successful pull records who made it (version, time, host, project directory, `APP_ENV`, user), and the web server records the version it serves on homepage and artefact requests (`buffer_dir/served-version.json`, rewritten when the version changes or every five minutes, on every install). `gt:status` shows both as `Pulled by` and `Web serving`, and when a web request made after the pull still served another version it prints, in red:

```text
[ERROR] pulled v2026-10-18-050325 but the web serves v2026-10-14-104728: the CLI and the web server don't share the cache pool. …
```

The two lines name the user, host, environment and project directory on each side, which is usually the difference: a cron running `APP_ENV=prod` while the web runs `dev`, a cron pinned to an old release, another server.

**A refresh the pool refuses.** When the pool will not take the bundle (a directory the cron user cannot write, a Redis that is down), `gt:refresh` exits 1 with the pool's own reason (`fopen(…): Permission denied` and the path), the last good bundle keeps serving, and the failure is recorded in `buffer_dir/refresh-error.json` — outside the pool, which is the one place it could not be recorded. `gt:status` shows it in red, with the version the pull could not install and who ran it, until a pull succeeds. The served version also goes to Globetrotters with every report (`captureContext.servedVersion`), so Studio can show the same warning.

**Upgrading to 0.9.4.** No configuration change. An install that never set `cache_pool` moves to the new pool, which starts empty. Until the first refresh fills it, the bundle 0.9.3 left in `cache.app` keeps serving, read-only (`gt:status` says so under `Bundle cached`), and the first refresh is due at once whatever the interval. Still run `bin/console gt:refresh` as the deploy step that ships 0.9.4: the fallback serves only what `cache.app` still holds, and a deploy that clears `var/cache` (or restarts PHP-FPM with `cache.app` on APCu) leaves nothing to fall back on until the cron's next tick. Once the new pool holds a bundle, `cache.app` is not read again. An install that set `cache_pool` explicitly has no fallback. An install that set `cache_pool` explicitly keeps it, unless it is one of the refused pools above, in which case the build says so.

## robots.txt

The bundle appends one block to your `/robots.txt` — or serves it as the whole file when your app has none — naming the AI user agents in the Globetrotters registry, with a `Content-Signal` for them, an `Agentmap: /.well-known/ai-catalog.json` line for the catalog this install serves, and a `Sitemap:` line for `/ai-sitemap.xml` on the host the request arrived on.

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

**The list of agents follows Globetrotters, with no bundle update.** With reporting configured, the daily fetch that brings the AI vendors' address ranges also brings the current crawler registry, stored as `ai-user-agents.json` in `buffer_dir`, so a crawler Globetrotters adds is named on your robots.txt within a day. Without reporting, or when that list is missing, more than 14 days old or unusable, the bundle names the 26 agents it ships with, exactly as before. Only robots.txt product tokens (letters, `-` and `_`) are ever written on a `User-agent:` line.

The block never adds a `User-agent: *` group, which would be combined with yours. `HEAD /robots.txt` is answered with no body but with the decorated `GET`'s `ETag` and `Content-Length` (and, like the `GET`, no `Last-Modified`), so a `HEAD` can never invalidate a cached `GET`.

## Reporting agent traffic

An apex install is **pull-and-cache, not proxy**. A request to `https://your-domain.example/llms.txt` is served by this bundle and terminates inside your application — it never touches a Globetrotters edge, so without this it is invisible and your apex looks like it gets no agent traffic at all.

Turn it on by setting the two values Studio issues together on the apex install screen (the token is shown **exactly once**). Studio issues them once **Check install** has verified the install on a domain your organisation has claimed (see [Configure](#configure)):

```bash
# .env.local, or better, the Secrets vault: bin/console secrets:set GLOBETROTTERS_INGEST_TOKEN
GLOBETROTTERS_INGEST_ENDPOINT=https://api.globetrotters.ai/presence/analytics/server-log
GLOBETROTTERS_INGEST_TOKEN=…
```

That is all: since 0.7.1 the bundle reads these two variables with no config of its own. Check with `bin/console gt:status`, which shows the endpoint and the last four characters of the token, or names the variable it cannot see. If `.env.local.php` exists (`composer dump-env prod`), `.env.local` is ignored until you run `composer dump-env prod` again.

Declaring `reporting.endpoint` / `reporting.ingest_token` in the bundle config is only needed to read other variables (or literal values); a declared key replaces the default. Installs that already declare the documented `%env(GLOBETROTTERS_INGEST_*)%` bindings behave exactly as before.

The endpoint must be `https://`: it receives the token and every client IP. A literal `http://` value fails the container build; one read from a variable reads as unset at runtime, so reporting stays off and `gt:status` says why.

Both are required; until both are set nothing is captured and nothing is written to disk. What is sent, per served artefact request: a UUID, a UTC timestamp, the canonical path, the User-Agent, the client IP, the referer, the status and the byte size, plus the Web Bot Auth headers of a signed request (see below). No cookies, no per-visitor identifiers. The backend uses the IP transiently to verify the agent against published vendor ranges and to resolve a country, then drops it — it is never stored.

That covers **AI agents fetching your discovery files**. Since 0.8.0 it also covers **bots fetching the pages of your site** (below), on by default. Your human visitors are never reported unless you also turn on the page-view counter below, which is **off by default**; when on, it adds a tiny script that posts to your own site, and keeps counts only: no IP, no cookie, no User-Agent string, no referrer.

### Pages fetched by AI agents and other bots

Once reporting is configured, the bundle also reports which HTML pages of your application are fetched by AI agents and other bots, so Presence Analytics can show the pages AI agents actually read. It is **on by default**, and switched off with:

```yaml
globetrotters_ai_presence:
    reporting:
        site_capture:
            enabled: false
```

What is sent, per page fetched by a probable bot: the User-Agent, the page path (never the query string), the response status and size, and whether the visit came from a verified AI vendor address; for a request signed with Web Bot Auth, its `Signature-Agent`, `Signature-Input` and `Signature` headers as received and the host it was served on, which Globetrotters verifies against the agent's published keys. **Never an IP address, and nothing about human visitors.**

- Only main `GET`/`HEAD` requests answered with `Content-Type: text/html` are considered. The profiler (`/_profiler`, `/_wdt`), static assets, the page-view counter endpoint and the files this bundle serves itself are skipped.
- A request is kept only when its User-Agent looks automated: it names a bot, crawler, fetcher or AI agent (`bot`, `crawl`, `spider`, `GPT`, `Claude`, `Perplexity`, `curl`, `python`, `HeadlessChrome` and similar), or it lacks the `Mozilla/` token every browser sends, or it carries a `Signature-Agent` header (an agent signing its requests with Web Bot Auth while sending a browser's User-Agent, as Meta Muse does). An unsigned browser request is never written anywhere. Finer classification happens at Globetrotters, so a new crawler needs no bundle release.
- **Verification without storing the IP.** The bundle fetches the AI vendors' published crawler address ranges from Globetrotters (`<endpoint>/ranges`, with the ingest token) at most once a day, from `gt:refresh` or the Scheduler's hourly refresh poll, whether or not your presence is published yet. On a captured request it resolves the client IP with the same trusted-proxy rules as artefact events (see [Behind a proxy or CDN](#behind-a-proxy-or-cdn)), checks it against those ranges in memory, and keeps only the yes or no and the vendor. The list lives in `buffer_dir/vendor-ranges.json`, so a `cache:clear` does not lose it; a failed fetch keeps the previous list, and a list older than 14 days (or none yet) reports visits as unverified.
- Site events share the event buffer and its bounds, and go out with the regular flush. An ordinary page never triggers the `kernel.terminate` flush, so your own traffic never waits on reporting.

Bot counts are a floor: a request answered by a page cache or CDN in front of the application never reaches it. Each flush therefore carries a small `captureContext`: whether site capture is on, `symfony-http-cache` when the kernel runs behind Symfony's `HttpCache`, which CDN or proxy headers (`CF-Ray`, `X-Varnish`, `Via`, `X-Cache` and similar) were seen on recent requests, and the version and date of the vendor range list. Studio shows the bot numbers as "at least" when something sits in front.

#### Keeping AI agents out of a page cache (optional)

A cache in front of the application answers AI agents too, and those visits are never counted. You can let the AI assistants' live-answer and AI-search agents through to the application, uncached. The trade-off: those agents get uncached pages, a small extra load on your servers. Training crawlers and search engines stay cached. The bundle never changes your cache or proxy configuration, so this is a snippet you add yourself. The WordPress plugin can do it for you, for its supported page caches.

The list of agents is the `cacheExclusionUserAgents` member of the ranges document the bundle already fetches (`<endpoint>/ranges`, with your ingest token). It changes when AI vendors add agents. As of this release:

```text
OAI-SearchBot|ChatGPT-User|Claude-SearchBot|Claude-User|PerplexityBot|Perplexity-User|meta-externalfetcher|meta-webindexer|xAI-Grok|Grok-DeepSearch|DuckAssistBot|ExaSearchBot
```

**Symfony `HttpCache`** (your `AppCache` or kernel cache class): skip the lookup for those agents. `pass()` forwards the request to the application without reading or storing the cache.

```php
use Symfony\Bundle\FrameworkBundle\HttpCache\HttpCache;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AppCache extends HttpCache
{
    private const AI_AGENTS = '/OAI-SearchBot|ChatGPT-User|Claude-SearchBot|Claude-User|PerplexityBot|Perplexity-User|meta-externalfetcher|meta-webindexer|xAI-Grok|Grok-DeepSearch|DuckAssistBot|ExaSearchBot/i';

    protected function lookup(Request $request, bool $catch = false): Response
    {
        if (preg_match(self::AI_AGENTS, (string) $request->headers->get('User-Agent'))) {
            return $this->pass($request, $catch);
        }

        return parent::lookup($request, $catch);
    }
}
```

**Varnish** (`vcl_recv`):

```vcl
sub vcl_recv {
    if (req.http.User-Agent ~ "(?i)(OAI-SearchBot|ChatGPT-User|Claude-SearchBot|Claude-User|PerplexityBot|Perplexity-User|meta-externalfetcher|meta-webindexer|xAI-Grok|Grok-DeepSearch|DuckAssistBot|ExaSearchBot)") {
        return (pass);
    }
}
```

**nginx** `fastcgi_cache` (use `proxy_cache_bypass` / `proxy_no_cache` with `proxy_cache`):

```nginx
# http {} context
map $http_user_agent $gt_ai_agent {
    default 0;
    "~*(OAI-SearchBot|ChatGPT-User|Claude-SearchBot|Claude-User|PerplexityBot|Perplexity-User|meta-externalfetcher|meta-webindexer|xAI-Grok|Grok-DeepSearch|DuckAssistBot|ExaSearchBot)" 1;
}

# location {} that runs PHP
fastcgi_cache_bypass $gt_ai_agent;
fastcgi_no_cache     $gt_ai_agent;
```

A CDN needs the same rule in its own cache settings. Studio keeps showing your bot numbers as "at least" while `symfony-http-cache` or a CDN header is seen: the bundle cannot check what your proxy does with these agents.

### Scheduling the flush

Events are buffered locally and flushed at most every 15 minutes. Three lanes, all sharing one interval, so whichever you have wins and the others stay dormant. The interval is checked under the flush lock, so two lanes firing a second apart never both send; `gt:presence:flush --force` skips the interval but never the lock.

**1. Cron (recommended).** The command enforces the 15-minute cadence itself, so running it more often is safe:

```cron
*/5 * * * * cd /srv/app && bin/console gt:presence:flush >/dev/null 2>&1
```

**2. symfony/scheduler.** With `symfony/scheduler` and `symfony/messenger` installed the `gt` schedule asks for a flush every 5 minutes, alongside the artefact refresh, and the shared interval decides — so, like the cron line above, flushes land every 15 to 20 minutes (`bin/console messenger:consume scheduler_gt`).

**3. `kernel.terminate` fallback (on by default, where the runtime allows).** For a shared host with no cron and no worker: a request for one of the artefacts triggers at most one flush per 15 minutes, after its response has been sent. Your own pages, `robots.txt`, `/ai-sitemap.xml` and the IndexNow key never trigger it.

It only runs where PHP delivers the response before `kernel.terminate`: **PHP-FPM, FrankenPHP and LiteSpeed**. On Apache mod_php, the CLI server and other runtimes the visitor would still be waiting, so the lane stays off there and you need lane 1 or 2. Even where it runs, the visitor has their response but the PHP worker stays busy for the ingest call (up to 20 seconds) and serves no one else meanwhile — on a small worker pool, prefer cron. Set `reporting.opportunistic_flush: false` to disable it.

`bin/console gt:status` reports which lane last flushed, how many events are buffered, how many were dropped, whether client-IP resolution looks trustworthy, whether site bot capture is on, and how old the vendor range list is.

### Counting human page views (optional)

Presence Analytics can set agent traffic against how many **people** view each page of your site — the denominator it cannot otherwise see. It is **off by default**, a separate opt-in on top of reporting, and needs reporting configured:

```yaml
globetrotters_ai_presence:
    reporting:
        page_views:
            enabled: true
```

Then place the script in your base layout, anywhere in the page (before `</body>` is conventional):

```twig
{{ gt_ai_presence_beacon() }}
```

It renders nothing while the counter is off or reporting is not configured, so it is safe to leave in the template. If you would rather not touch templates, `auto_inject: true` has the bundle insert it before `</body>` on every `200` HTML response (never on XHR responses, never twice if you also placed it yourself). That is a second opt-in on purpose: otherwise the bundle only ever injects `<head>` markup that runs nothing.

**Content-Security-Policy.** The script is inline. If your policy forbids inline scripts, pass your per-request nonce to the Twig function — with NelmioSecurityBundle, `{{ gt_ai_presence_beacon(csp_nonce('script')) }}` — and it is rendered, escaped, as the tag's `nonce` attribute. The auto-injected copy carries no nonce, so `auto_inject` needs `'unsafe-inline'` in `script-src`; under a nonce-based policy use the Twig function instead.

```yaml
globetrotters_ai_presence:
    reporting:
        page_views:
            enabled: true
            auto_inject: true
```

**How it counts.** The script is one line, under 500 bytes, and the same byte for byte in the WordPress plugin:

```html
<script>(function(){var n=navigator,d=document,l=location;function s(){try{var b={p:l.pathname},r,u;try{r=new URL(d.referrer).host}catch(e){}if(r&&r!==l.host)b.r=r;try{u=new URLSearchParams(l.search).get('utm_source')}catch(e){}if(u&&u.length<=100)b.u=u;n.sendBeacon('/.well-known/globetrotters/pv',new Blob([JSON.stringify(b)],{type:'text/plain'}))}catch(e){}}try{if(!n.sendBeacon)return;if(d.prerendering){d.addEventListener('prerenderingchange',s,{once:true})}else{s()}}catch(e){}})();</script>
```

It posts the page's path — never its query string — to `/.well-known/globetrotters/pv` on **your own site**, and, when there is one, the **host** of the page that linked to it (never the full referring URL, and nothing when the visitor came from your own site) and the value of the page's `utm_source` parameter (left out when longer than 100 characters). The browser never talks to Globetrotters. A page the browser only *prerenders* (Speculation Rules) is not counted until it is actually shown. The bundle answers that `POST` before routing and the firewall — always with the same empty `204`, even while the counter is off, so pages cached while it was on do not run into your application's 404 — and, when the counter is on:

- counts it only when it comes from your own site: a real `Origin` header decides by its host; `Origin: null` (what a page under `Referrer-Policy: no-referrer` sends) counts as absent, and then `Sec-Fetch-Site: same-origin` decides, or failing both, the `Referer` host;
- counts only a path Globetrotters would accept (absolute, no query string or fragment, at most 512 characters; a trailing slash is folded);
- sorts the visitor into `browser` or `ai_browser` (a browser that says it is driven by ChatGPT, Comet, Perplexity or Claude, or a beacon carrying a Web Bot Auth `Signature-Agent` header) by the User-Agent, and does not count an obvious bot at all;
- for a `browser` view, looks the referring host up, then the `utm_source` value (cut at its first `?`, as Copilot sends `copilot.com?form=…`), in the list of AI assistants' hosts and `utm_source` values Globetrotters publishes (`chatgpt.com` → `chatgpt`, and so on; a host match wins). The `utm_source` value is not looked up when the referrer is a search engine on that list (Google, Bing, DuckDuckGo): an assistant's tag on a page a search engine indexed travels with the search result. It keeps **only the platform** it finds, or `none`. The host and the `utm_source` value are never written to disk, logged or sent. The list comes with the daily vendor-ranges fetch and is kept in its own small file, read only for a view that carries a referrer or a `utm_source`; while it is missing or older than 14 days every view counts as `none`. An `ai_browser` view always counts as `none`: its bucket already says an assistant drove it;
- increments a counter per UTC day, path, bucket and platform in `buffer_dir` — at most 2000 distinct paths a day, the rest counted under `(other)` (a path's platform rows are kept or folded together, so its total never splits). The file is replaced atomically on each increment, and a view that cannot take the lock within a few milliseconds is dropped rather than queueing your PHP workers behind it;
- answers `204` with `X-Content-Type-Options: nosniff`, `Cache-Control: no-store, private` and `Surrogate-Control: no-store`, and strips any cookie your application tries to add to that response.

It **never reads the client IP, never stores the User-Agent string, the referrer or the `utm_source` value, and never sets a cookie**. A day's counts are sent in the regular flush only once that UTC day has ended, each record with an id minted once so a retried flush cannot double-count, and deleted locally once accepted. Days older than a week are dropped, as Globetrotters would drop them. With `enabled` off, a flush neither seals nor sends page views; counts not yet sent stay on disk (a CLI or worker that is merely missing the setting cannot delete what the web tier counted) and age out after a week like any other day. Turn it back on and they go with the next flush. The counts are indicative: visitors with JavaScript off are missed, and a same-origin endpoint can be spammed — they are never billed on.

Page views are flushed by every lane, including a run with no agent traffic buffered, so cron and `symfony/scheduler` carry them as they carry events. On the `kernel.terminate` lane a beacon request can trigger the flush too (a beacon is fire-and-forget, nobody waits on it), so an install with no cron still sends them. `gt:status` shows whether the counter is on, how many records are waiting, and whether the AI referrer list is fresh.

**For your privacy policy.** Adapt this to your own wording and legal context:

> To understand which pages of our website people visit, we count page views with a first-party counter. When you open a page, a small script sends the address of that page (without any query string) to our own server, together with the name of the website that linked to it and its `utm_source` campaign tag, if any. Our server adds one to a daily count for that page, noting only whether the visit came from an AI assistant such as ChatGPT, and if so which one; the linking website and the campaign tag are then discarded. We do not set a cookie, do not store your IP address or browser details, and do not build any profile of you; only the daily total per page is kept. These totals — not any data about you — are shared with our AI-presence provider, Globetrotters, to report how our website is used alongside how AI assistants use it.

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
        site_capture:
            enabled: true                                    # pages fetched by bots (no IP, nothing about humans)
        page_views:
            enabled: false                                   # first-party human page-view counter, off by default
            auto_inject: false                               # insert the script before </body> for you
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
<link rel="ai-catalog" href="/.well-known/ai-catalog.json">
<link rel="mcp" href="/.well-known/mcp.json">
<link rel="agent-card" href="/.well-known/agent-card.json">
<link rel="related" href="https://ai.your-domain.example/">
```

`rel="alternate"` appears on `homepage_path` only — it points at a document describing the destination, so claiming it as an interior page's alternate would assert something untrue about that page. The three agent-discovery relations name where your *site's* surfaces live, which is equally true from every page, so they are emitted on **every HTML page**: an agent that arrives on a deep page is exactly the case that needs a pointer. `rel="related"` names the presence host itself, on every page too: once your install serves all the discovery files, it is the one relation that still points there.

**Installing this bundle is transparent to your visitors.** It never injects anything visible: it does not know your layout, and has no safe position to put an element into a design it does not own. Head markup only.

That has a real cost, worth stating plainly: a `<link>` is a discovery *pointer*, not a followed link, so on its own it does not pass the crawl authority that would let the subdomain inherit your apex's standing. A visible anchor is what does that — so the bundle hands you one to place yourself, inside your own layout:

```twig
{{ gt_ai_presence_breadcrumb_link() }}   {# renders <a href="https://ai.your-domain.example">…</a> #}
```

Set `breadcrumb.anchor_text` to control its wording. It is your markup, in your template, where you can see and style it.

**Each relation points at the canonical copy of its own file.** Anything this install serves itself (the paths above, which since 0.7.0 include all four) is linked root-relative, so agents are sent to *your* domain — the copy carrying your index membership, not the mirror. A relation names the Globetrotters host only for a file this install does not serve, or has not cached yet (a bundle cached before 0.7.0 has no `ai-catalog.json` until its next successful refresh); the split follows the served set, so a file added there starts resolving locally on its own.

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

**The locally served paths are identical on both profiles.** All of them stay at your apex either way — the backend's split is an *offload* list, and none of them is on it. This profile changes the breadcrumb, not the footprint.

## Twig alternatives to the automatic injection

Each injection is automatic on `homepage_path`. If you'd rather place markup explicitly, use these in your base template — the automatic injectors detect what you rendered and won't duplicate it:

```twig
{{ gt_ai_presence_head() }}             {# the JSON-LD tag, any profile #}

{# subdomain_breadcrumb only; both render '' on other profiles #}
{{ gt_ai_presence_breadcrumb_head() }}  {# in <head> #}
{{ gt_ai_presence_breadcrumb_link() }}  {# in your footer #}
```

`gt_ai_presence_breadcrumb_link()` is the only way the visible anchor ever reaches a page — there is no setting that makes the bundle place it for you.

The page-view script, when you have turned the counter on, is placed the same way; it renders `''` otherwise, and `reporting.page_views.auto_inject` is the opt-in that places it for you (see [Counting human page views](#counting-human-page-views-optional)):

```twig
{{ gt_ai_presence_beacon() }}           {# before </body>; or gt_ai_presence_beacon(csp_nonce('script')) under a CSP #}
```

## Caveats

- **Static files shadow the kernel.** If a real file exists in `public/` for one of the artefact paths (or `public/robots.txt`), your web server serves it directly and the bundle never sees the request. Delete the static copies when migrating from the file-drop lane.
- **The pool must be shared by the CLI and the web server.** See [Cache pool](#cache-pool): the default is; APCu, array and `cache.system` pools are refused at build. A pool of your own in `var/cache` (`cache.app` on the filesystem) is emptied by `cache:clear`, after which the artefacts fall through to your app until the next `gt:refresh`, and the reporting lane forgets when it last flushed successfully (buffered events live in `buffer_dir` and survive).
- **Changing or clearing `website_url` stops serving the previous source at once.** The cached bundle records the URL it was pulled from and is served only while that URL is configured. After a change, the next `gt:refresh` drops it and pulls the new source straight away, whatever the interval; a process with no `website_url` never deletes it. On the Scheduler lane the same happens on the next hourly poll; run `gt:refresh` in the deploy to have it at once.
- The configured `website_url` must be `https://`: what it serves is published at your apex, homepage JSON-LD included. A literal `http://` or scheme-less value fails the container build; one bound to an env var is refused at refresh, which records why for `gt:status` and keeps the last good bundle serving.
- The configured `website_url` is fetched with an SSRF guard (private/reserved IPs are rejected), a 5-second timeout, and a 1 MiB per-file size cap.
- **Reporting needs a writable `buffer_dir`**, shared by the web user and whoever runs the flush — serving needs no filesystem write access, and an install that doesn't report uses the directory only for the refresh lock (`refresh.lock`), the last refresh failure (`refresh-error.json`) and the web's served version (`served-version.json`), the one file the web server writes on an install that doesn't report — after the response, and an unwritable directory costs only that record. When those are different OS users (say `www-data` and a deploy user running cron), put both in one group: the bundle creates the directory `2770` (setgid, so every file takes its group) and every file `0660`, whatever the umask, and nothing in it is world-readable since the event log holds client IPs. Upgrading from 0.6.0 or earlier, where files were left to the umask, run `chmod 2770 <buffer_dir> && chmod 660 <buffer_dir>/*` once; `gt:status` names the event log or drop counter when the user running it cannot write them (the other files work across users as they are). On a per-release deploy (Deployer, Capistrano), list `buffer_dir` as a shared directory, or each release starts with an empty buffer. It holds at most 5000 events or 512KB; past that the oldest are dropped and counted, and the count is reported so the gap is visible rather than silent. `gt:status` shows both. With the page-view counter on it also holds one small file per day (`pageviews-<day>.json`, then `pageviews-sealed-<day>.json` until sent), a week at most. With reporting configured it also holds `served-version.json`, the bundle version the web server last served (see [Cache pool](#cache-pool)), and keeps the three lists fetched daily from Globetrotters: `vendor-ranges.json` (AI vendors' crawler addresses, for site capture), `ai-referrers.json` (AI assistants' hosts and `utm_source` values, for the page-view counter, about 1 KB) and `ai-user-agents.json` (the AI crawler registry robots.txt names, about 1 KB). **It must be a local POSIX filesystem** (not NFS, SMB or a Windows share): the counter relies on `flock()` and on seeing a file it has locked be unlinked or renamed away, which network filesystems do not reliably provide.
- **The breadcrumb needs a `</head>` in the response.** The block is inserted before the closing tag, so a page that streams, is served from a static cache, or omits `</head>` gets nothing — place it with `gt_ai_presence_breadcrumb_head()` instead.
- **Conditional GETs survive injection; `Last-Modified` does not.** Rewriting a body makes metadata describing the original representation untrue. The `ETag` is therefore **recomputed** from the injected bytes — preserving your weak/strong flavour — and revalidated once, after every injection has run, so a client holding what was actually served still gets a `304` while one holding a half-injected body correctly gets a fresh `200`. A response that published no `ETag` is left without one; the bundle will not invent a caching contract you did not opt into. `Last-Modified` is dropped rather than restamped: a changed body says nothing about when the underlying resource changed.
- **An accepted flush is not proof the token is right.** The ingest endpoint answers `202` to a bad token, an unknown install and a malformed body alike, deliberately revealing nothing about which tokens exist. `gt:status` distinguishes "configured but never accepted" from "reporting normally", but confirm the numbers in Studio.

## Development

```bash
make install   # composer update
make ci        # php-cs-fixer + phpstan + phpunit (unit + integration)
```

The integration suite boots a real `HttpKernel` with a catch-all "antagonist" controller and a network-free fake fetcher, proving route pre-emption, robots decoration, raw-HTML JSON-LD, and stale-serve end to end. It also covers the reporting lane: capture through a live kernel, the console and `kernel.terminate` flush lanes, the buffer under forked concurrent writers, and the no-store headers surviving Symfony's own `HttpCache`.

## Releasing

1. Bump the version in its three places: `GlobetrottersAiPresenceBundle::VERSION`, the `extra.branch-alias.dev-main` constraint in `composer.json`, and `CHANGELOG.md`, where the `[Unreleased]` notes move under a new `## [x.y.z] - YYYY-MM-DD` heading.
2. Merge to `main`.
3. On an up-to-date `main`, tag and push:

```bash
make release VERSION=x.y.z
```

The `Release` workflow then checks that the tag, `VERSION`, the branch alias and the `CHANGELOG.md` section agree, runs the full CI matrix, and publishes a GitHub Release with that section as its notes. Packagist picks up the tag on its own.

## Contributing and security

Issues are welcome; pull requests are by invitation, see [CONTRIBUTING.md](https://github.com/globetrotters-ai/gt-symfony-bundle/blob/main/CONTRIBUTING.md). Report a vulnerability privately as [SECURITY.md](https://github.com/globetrotters-ai/gt-symfony-bundle/blob/main/SECURITY.md) describes, never in a public issue.

## Related

- [Globetrotters.ai](https://globetrotters.ai): the Official AI Presence platform for travel brands this bundle serves.
- [Sign up for Studio](https://studio.globetrotters.ai/signup) to publish your presence and get the apex install details.
- The [WordPress plugin](https://globetrotters.ai/wp-plugin) ([source](https://github.com/globetrotters-ai/gt-wordpress-plugin)), the same apex companion for WordPress sites.

## License

Released under the [MIT License](LICENSE). © 2026 Globetrotters.ai
