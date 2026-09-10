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
| `src/Serving/` | The request path: `Router` plus the response/terminate subscribers, and the locally generated `Sitemap` |
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
| `kernel.response` | `Serving\RobotsFilter::captureHeadRepresentation` | 1 | Keeps a `HEAD` body before `ResponseListener` (0) empties it, so the `HEAD` can carry the decorated `GET`'s `ETag` and `Content-Length` |
| `kernel.response` | `Serving\RobotsFilter` | -20 | Decorates or generates `/robots.txt`; after `CacheAttributeListener` (-10) so late validators are dropped, before `ConditionalGetSubscriber` (-64) so it revalidates the final tag |
| `kernel.response` | `Serving\ArtefactHeaderSubscriber` | -1024 | Runs *last* on purpose, so it re-asserts `Router::NO_STORE_HEADERS` after anything downstream has had its chance to rewrite them |
| `kernel.terminate` | `Serving\ArtefactCaptureSubscriber` | 0 | Records the served request, after the response is sent |
| `kernel.terminate` | `Serving\OpportunisticFlushSubscriber` | -256 | Flushes after the capture above, at most every 15 min, for hosts with no cron and no Messenger worker — only on a served artefact request (`ATTRIBUTE_PATH`), and only where `Serving\ResponseFinalization` says the response was already delivered |

`Router` sets the response and stops propagation. A path miss or cold cache returns without touching the response so the app handles the request normally. Requests it serves are tagged with `Router::ATTRIBUTE_PATH` / `ATTRIBUTE_BYTES`, which is how the two later subscribers recognise them, so those attribute constants are an internal contract between four files.

### The IndexNow key

`Router` answers one path that is not in `ContentTypes::MAP`, because its name is only known at runtime: the IndexNow key file at `/<key>.txt`. IndexNow verifies control of a host by reading it and comparing the body to the key, and the file must sit at the apex **root** — a key under `/.well-known/` scopes submissions to that directory and would cover none of the artefacts. The apex is served by the integrator's own stack, so nothing Globetrotters hosts can supply it there; without this route a file-drop apex cannot be announced at all and every publish announces only the weaker GT-hosted mirror.

The key rides on the version marker (`Sync\ArtefactSync::resolveVersionMarker` → `Options` state `indexnow_key`), the only channel that costs nothing elsewhere. It is not a required fetched path: adding it to `requiredPaths()` would 404 in every keyless environment — dev, and staging, where IndexNow is off permanently — and abort *every* sync there. It is not a bundle file either: the marker is injected after hashing, so the key cannot perturb `contentHash` or trip drift detection. `Serving\IndexNowKey` applies the backend's own key grammar (`[A-Za-z0-9-]{8,128}`, `deploy_config_renderer._INDEXNOW_KEY_RE`) on the way in and on the way out — the marker arrives from `website_url`, which is untrusted input.

A served key is tagged with `Router::ATTRIBUTE_KEY`, deliberately **not** `ATTRIBUTE_PATH`: `ArtefactHeaderSubscriber` re-asserts the no-store headers for it, while `ArtefactCaptureSubscriber` — which keys off `ATTRIBUTE_PATH` — never sees it.

Rotation carries a lag this lane cannot avoid: a rotated key reaches an install only on its next refresh (daily by default), and submissions for that host fail verification during the window. Documented, not engineered around.

## Invariants worth preserving

- **Stale-serve.** The cached bundle is only ever replaced by a *fully successful* pull. A partial or failed refresh must leave the last known good version serving. "Successful" includes every JSON artefact parsing to an object (JSON-LD: or a list of node objects) — a `200` maintenance page is a failure. Keep that check shape-only; a field-level schema here would reject legitimate publications. `tests/Integration/RefreshStaleServeTest.php` guards this.
- **A bundle is served only for the `website_url` it was pulled from.** Stale-serve is for the *same* source failing; a bundle from another source is never a fallback. `ArtefactCache` stamps the normalized source into the manifest on `store()`, and its one read gate (`manifest()`) refuses a bundle from another source, or any bundle once `website_url` is cleared, so every reader — router, head injection, breadcrumb, sitemap, Twig, robots — is covered at once. The config changes with a deploy while the pool may outlive it, and serving cannot write, so the refresh is what clears it: `ArtefactSync::forgetForeignBundle()`, called by `run()` and, before its due-check, by `gt:refresh`, drops the bundle and resets the state learned from it, as `gt-wordpress-plugin`'s `forget()` does on a settings save — but only in a process that is itself connected. A CLI or worker with no `website_url` is as likely a missing env var as a withdrawn presence, and forgetting there would let it delete the bundle a correctly configured web tier serves. A manifest from 0.4.0 or earlier carries no stamp and stays servable while connected, so an upgrade does not go dark.
- **The flush interval is decided under the flush lock.** `Flusher::run()` checks `FlushGate::isDue()` inside `withLock()`, for every lane; a caller's own pre-check is only a cheap early exit. `--force` bypasses the interval, never the lock. The Scheduler polls every 5 minutes rather than every 15 so trigger jitter cannot make it skip alternate flushes.
- **Serving needs no filesystem write access.** Only reporting writes (to `reporting.buffer_dir`). Do not introduce a write on the serving path.
- **`website_url` is untrusted input.** It is fetched through `NoPrivateNetworkHttpClient` (wired in `config/services.php`) so a configured URL cannot be pointed at a private or reserved IP, including across redirects. Keep any new outbound fetch on that client, not on raw `http_client`.
- **The ingest endpoint is https-only, at three layers.** It receives the bearer token and every captured client IP. The config tree refuses a literal non-https `reporting.endpoint` at build; `AnalyticsOptions::endpoint()` reads one as `''` (unconfigured) at runtime, which is the layer that holds for the documented `%env()%` binding, since the build only ever sees its placeholder; and `IngestClient::post()` refuses before sending, with an error that never carries the token. All three share `AnalyticsOptions::isHttpsUrl()`, the same rule as `gt-wordpress-plugin`'s `Options::is_https_url()`.
- **`reporting.ingest_token` is deliberately not a container parameter.** It is injected straight into `AnalyticsOptions`, because a parameter would land in the compiled container's parameter bag and in `debug:container --parameters`. See the comment in `loadExtension()` before changing how it is wired.
- **Header text is duplicated on purpose.** `Router::NO_STORE_HEADERS` is written in served order so the code, the README table and the tests all read identically. Change all three together.
- **The key is served only when one is stored, and only at its own URL.** No stored key means the path falls through to the application's normal 404 — never a 200 with an empty body, which would answer a verification fetch with a file that fails it. `ArtefactSync` writes `indexnow_key` on every *successful* pull including a keyless one, so a key rotated away upstream stops being served rather than lingering; a failed pull writes nothing, so the last known key keeps serving alongside the last known good bundle. It is served only while a bundle from the current source is: after `website_url` changes, the state still holds the previous source's key until the refresh forgets it.
- **The artefact set is matched before the key.** The edge proxy declares `serve_indexnow_key` *before* its `/{filename}` catch-all and relies on the key grammar to keep `/llms.txt` reaching the artefact handler; `Router::onKernelRequest` reaches the same outcome structurally, so no key can ever shadow a served file.
- **The key response is not CORS-readable.** `Router::CORS_HEADERS` is granted to the artefacts because a browser-context agent client cannot read a discovery document without it. The key file is fetched server-side by a search engine, so the grant stays scoped to the paths that need it — `Router::keyHeaders()` is `NO_STORE_HEADERS` only. It *does* carry both no-store headers, for the artefacts' measurement reason and one of its own: a cached copy of a rotated-away key fails verification for as long as it lives.
- **Serving the key is not agent traffic.** Presence Analytics counts agent fetches of the artefact set; a search engine reading the key to verify host control is neither, and folding it in would inflate the numbers a customer reads as demand for their presence.
- **The sitemap is generated, never fetched.** Globetrotters publishes a per-tenant `sitemap.xml`, but its URLs are in the GT host's space and `src/Sync/` caches every artefact **verbatim** — there is no origin-rewriting machinery in this repo, and adding one for a single file is not worth it. `Serving\Sitemap` builds the listing from the paths the cache would actually serve, in the request's own URL space, so it structurally cannot list a URL this install does not answer. That is the failure the backend's `offloaded_paths` strip exists to prevent, closed by construction.
- **`/ai-sitemap.xml`, never `/sitemap.xml`.** `Router` pre-empts routing, so claiming `/sitemap.xml` would shadow whatever the site does there (its own sitemap, a static file, a redirect onto an SEO bundle's index). The cost is measurable, not theoretical: `readiness/services/tech_checks/files_and_access.py::check_sitemap` scores a 5-9 URL sitemap at 60 against 80-100 for a populated one, so shadowing would *lower* a customer's public readiness score. A distinct path collides with nothing and needs no yield heuristic, because robots.txt takes a *list* of `Sitemap:` directives. `gt-wordpress-plugin` serves the same document at the same path for the same reason — keep them aligned.
- **The apex validator finds `/ai-sitemap.xml` through robots.txt.** Since [gt-backend#958](https://github.com/globetrotters-ai/gt-backend/pull/958), `apex/services/probes/robots_sitemap.py::probe_sitemap_includes_llms` walks the same discovery order as `check_sitemap`: `/sitemap.xml`, then every robots.txt `Sitemap:` declaration on the apex's own registrable domain (eTLD+1; declarations on other domains are refused and never fetched, and at most 10 are read), then `/sitemap-index.xml` and `/sitemap_index.xml`. It passes on the first candidate that lists `/llms.txt` or `/llms-full.txt`, not the first that answers, so a bundle install passes even when the site's own `/sitemap.xml` answers first. The pass rides on `RobotsFilter`'s `Sitemap:` line, which names the request's own host and so always sits on the apex's registrable domain; keep it that way. The path stays put: the probe now discovers the declared sitemap, so there is no score to "fix" by moving it onto `/sitemap.xml`.
- **`content_changed_at` is not `last_refresh`.** The sitemap's `<lastmod>` reads the former. Refreshes run daily whether or not anything moved; stamping URLs with the refresh date claims a freshness the content does not have. `ArtefactSync` writes it only when the content hash changes. It is never a fallback source, `0` omits `<lastmod>`, and the homepage is never stamped — it is the customer's page, not ours to date (the backend's `lastmod_locs` restriction). Matches `gt-wordpress-plugin`.
- **Heavy files stay remote.** `llms-full.txt` and `content.md` are linked back to Globetrotters by absolute URL, never served locally.

- **The robots.txt AI user-agent list is a mirror, not a source.** `RobotsFilter::AI_BOTS` and `tests/Fixtures/robots-ai-user-agent-groups.txt` follow gt-backend's `libs/globetrotters-business/.../presence/services/ai_user_agents.py`. Regenerate the fixture from that module (the command is in `RobotsFilterTest`'s docblock) rather than editing either by hand, and keep `gt-wordpress-plugin` in step in the same cycle. The fixture is what `aiUserAgentGroups(aiTrain: true)` emits — the fully opted-in form, not the default.
- **Decorating robots.txt never changes the effective crawl policy by default.** Under RFC 9309 a named group *replaces* the `*` rules for that agent, and one appended for an already-named agent *merges into* the site's (equal-length `Allow` beats `Disallow`). So agents the site names (or reaches through a documented vendor fallback, `FALLBACK_GROUPS`) are never added, and the rest inherit the site's `*` member lines. `Allow: /` over wildcard restrictions (`robots.ai_agents: allow_all`) and `ai-train=yes` (`robots.ai_train`) are explicit opt-ins. Groups are read as Google reads them (`RobotsPolicy`): only an `Allow`/`Disallow` line ends a user-agent section — `Content-Signal`, `Crawl-delay`, `Sitemap` and unknown lines between `User-agent` lines do not — and a section the site's file leaves open is closed with a pathless `Disallow:` before anything is appended. `RobotsFilterTest::testDecoratingNeverChangesWhatAnAgentMayFetch` checks all of this against a port of google/robotstxt's matcher; extend its fixtures rather than weakening it, and never rewrite that evaluator in terms of `RobotsPolicy`, or the two can share a mistake again.
- **The block emits no `User-agent: *` group and no `Agentmap:` line**, though the backend emits both. The wildcard would duplicate one the host app may already own in the decorate path, and `Agentmap: /.well-known/ai-catalog.json` is root-relative while this bundle does not serve `ai-catalog.json` — it would advertise a 404 at the customer's apex. Adding the line requires adding the artefact to the served set first.

## Releasing

The version lives in three places that must move together: `GlobetrottersAiPresenceBundle::VERSION`, the `CHANGELOG.md` heading, and the `extra.branch-alias.dev-main` constraint in `composer.json`. Packagist publishes from the git tag, so tag only after all three agree. CHANGELOG follows Keep a Changelog and the project is on semver (currently 0.5.0, first public release 2026-08-25). The number is kept in step with `gt-wordpress-plugin` so the same behaviour ships under the same version on both.

## Local testing against a real app

`../gt-symfony-bundle-testbed/` is a throwaway Symfony 7.4 skeleton (local only, no git remote) that exists purely to host this bundle in a real application. Use it for anything the integration suite cannot reach, such as interaction with a real reverse proxy or another bundle's listeners. Nothing in the testbed is a deliverable and it is safe to reset.
