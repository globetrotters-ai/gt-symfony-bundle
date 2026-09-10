# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **The AI user-agent allow-list is now the canonical registry.** `/robots.txt`
  names 20 agents instead of 11, in the order and vendor casing of gt-backend's
  `presence/services/ai_user_agents.py`, which every Globetrotters emitter now
  derives from. New names: `Claude-SearchBot`, `Perplexity-User`, `Googlebot`,
  `Bingbot`, `Applebot`, `Amazonbot`, `DuckAssistBot`, `Bytespider`,
  `cohere-ai`. `Anthropic-AI` is now spelled `anthropic-ai`, the vendor's own
  casing (robots matching is case-insensitive, so this changes nothing a
  crawler does). Nothing is blocked, and nothing that could fetch your site
  before can't now: naming an agent is signalling, not permission.
- **Every named group carries its own `Content-Signal: search=yes, ai-input=yes,
  ai-train=yes`.** Per RFC 9309 §2.2.1 a crawler obeys only its own
  most-specific matching group, so a single copy at the top of the file would
  reach none of the named agents.

- **`robots.txt`'s `Sitemap:` line now names this site's own
  `/ai-sitemap.xml`**, not a `sitemap.xml` on the configured Globetrotters
  origin. A cross-host `Sitemap:` directive is ignored
  by Google and Bing without cross-domain verification, so the old line was
  inert rather than harmful — but in bundle mode the content is served from
  your apex, and that is where the sitemap belongs. The host comes from the
  request, so a site reachable on several hostnames gets the right line on
  each, with nothing to configure.

### Added

- A fixture test (`tests/Fixtures/robots-ai-user-agent-groups.txt`) that fails
  the build if the emitted block drifts from the backend registry's own output,
  byte for byte. The fixture is generated from that module, not typed.
- **A generated sitemap at `/ai-sitemap.xml`**, served once a bundle is cached:
  a `<urlset>` over your homepage plus every artefact this install is actually
  serving, in the URL space of the request it arrives on. It can never
  advertise a URL your site does not answer, and needs no configuration to know
  your domain. `robots.txt` declares it, which is how crawlers discover a
  sitemap anyway.
- **`/sitemap.xml` is never claimed.** That path is the site's — served by the
  application, a static file, or redirected onto an SEO bundle's index — and a
  six-URL artefact listing is no substitute for it. robots.txt takes a *list* of
  `Sitemap:` directives, so ours is additive to whatever the site declares. This
  matches `gt-wordpress-plugin`, which serves the same document at the same
  path for the same reason.
- Globetrotters publishes a per-tenant `sitemap.xml`, but every URL in it is in
  the *GT host* URL space and this bundle serves artefacts verbatim; building
  the listing locally is what keeps it and the serving in step by construction.
- The sitemap response carries `nosniff` and both `no-store` headers, and no
  `Access-Control-Allow-Origin` (a sitemap is fetched server-side by a crawler).
  Serving it is not recorded as agent traffic.
- `<lastmod>` on each artefact URL, dated from the new `content_changed_at`
  state value: it moves only when the pulled content actually changes, so a
  daily refresh that changed nothing does not restamp every URL as fresh. It is
  omitted until the install has seen a content change, and never set on the
  homepage — that is your page, edited independently of the bundle, so the
  bundle cannot vouch for its date.

### Fixed

- **`HEAD /robots.txt` no longer returns a body.** Symfony empties a HEAD
  response's body before this bundle's `kernel.response` subscribers run, so the
  robots block was being appended to an already-emptied body — putting bytes on
  a HEAD response, prefixed with two blank lines.

## [0.4.0] - 2026-09-09

### Added

- **IndexNow key serving.** When Globetrotters has issued your environment a
  key, the bundle serves it at `/<key>.txt` — the apex root, which is where
  IndexNow looks and the only location whose scope covers every artefact. This
  is what makes an apex install announceable at all: the apex is served by your
  own stack, so nothing Globetrotters hosts can supply the file there, and
  without it every publish announces only the weaker GT-hosted mirror.
- The key rides on the version marker your normal refresh already pulls, so
  there is nothing to configure. It is never a required fetched path and never
  enters the bundle content hash: a keyless environment syncs exactly as before,
  byte for byte, and a key appearing, rotating or being withdrawn cannot trip
  drift detection.
- A refresh whose marker carries no key **clears** any key previously stored, so
  a withdrawn key stops being served rather than lingering as a file that
  verifies nothing. A *failed* refresh changes nothing, matching stale-serve.
- The key response carries `nosniff` and both `no-store` headers — a cached copy
  of a rotated-away key fails verification for as long as it lives — but not
  `Access-Control-Allow-Origin`: it is fetched server-side by a search engine,
  so the cross-origin grant stays scoped to the discovery documents that need
  it. Serving it is not recorded as agent traffic.
- A value outside IndexNow's own key grammar (`[A-Za-z0-9-]{8,128}`) reads as no
  key at all, matching the backend's own check — the marker arrives over the
  network and the value decides both which path is answered and what its body
  is.

## [0.3.0] - 2026-09-09

### Added

- **Install profiles.** A `profile` option selects between `full_apex` (the
  previous and default behaviour — this site is the whole presence) and
  `subdomain_breadcrumb`, the both-lanes deployment the product recommends:
  keep serving the artefacts at the apex *and* link back to the presence
  published at the Globetrotters host, which is otherwise unreachable to a
  crawler that does not already know its name.
- **Breadcrumb injection, head markup only.** On `subdomain_breadcrumb` the
  bundle adds agent-discovery `<link>` relations to `<head>` and nothing else.
  Installing it stays transparent to your visitors: it never injects visible
  markup into a design it does not own. The visible anchor — the half that
  actually passes crawl authority — is available as
  `gt_ai_presence_breadcrumb_link()` for you to place inside your own layout.
- The three agent-discovery relations are emitted on **every HTML page**, since
  they name site-level surfaces and an agent may arrive anywhere.
  `rel="alternate"` stays on `homepage_path` alone, because it points at a
  document describing the destination rather than that page. Matches the
  WordPress plugin.
- Each discovery relation points at the canonical copy of its own file:
  root-relative for everything this install serves (so agents are sent to the
  apex copy that carries the index membership, not the mirror), absolute to the
  Globetrotters host only for `.well-known/ai-catalog.json`, which the apex
  bundle does not contain. Matches `Breadcrumbs::href()` in the WordPress
  plugin.
- `breadcrumb.anchor_text` (defaults to the destination name from `ai.json`) to
  set the wording of the anchor you place yourself.
- `gt_ai_presence_breadcrumb_head()` and `gt_ai_presence_breadcrumb_link()` Twig
  functions, for explicit placement of either half.

### Changed

- Rewriting a response body now **recomputes** its `ETag` from the injected bytes
  instead of dropping it, so an application that publishes an entity-tag keeps
  its conditional GETs through the JSON-LD, breadcrumb and `robots.txt`
  injections. The revalidation happens once, after every injection has run, so a
  client holding what was actually served gets a `304` while one holding a
  half-injected body correctly gets a fresh `200`. The weak/strong flavour is
  preserved, and a response that published no `ETag` is still left without one.
  `Last-Modified` and the digest headers are still dropped — a changed body says
  nothing about when the resource changed, and a subtly wrong digest is worse
  than none.

### Notes

- The canonical Globetrotters origin is **derived, never configured**: it is read
  from the cached `ai.json` on every refresh, so a custom-hostname activation or
  detach is picked up without a configuration change or a redeploy.
- The set of paths served locally is identical on both profiles. None of them is
  on the backend's offload list, so the profile changes the breadcrumb, not the
  footprint.
- Upgrading from 0.2.x is opt-in: on 0.x semver a minor bump is treated as
  breaking, so a `^0.2` constraint stays on 0.2.x until you move it to `^0.3`.
  Existing installs default to `full_apex` and behave exactly as before.

## [0.2.0] - 2026-08-25

First public release.

### Added

- Serves the apex artefact set — `/llms.txt`, `/ai.json`, `/schema.json`,
  `/.well-known/mcp.json`, `/.well-known/agent-card.json` and
  `/.well-known/globetrotters-apex-version.json` — from a `kernel.request`
  subscriber that runs before routing, so a catch-all controller, security
  bundle or reverse proxy cannot intercept them. Serving needs no filesystem
  write access.
- Scheduled refresh from the Globetrotters subdomain via `gt:refresh` (cron) or
  `symfony/scheduler`, with stale-serve: the cached bundle is only replaced by a
  fully successful pull, so an unreachable Globetrotters leaves the last known
  good version serving.
- Server-rendered, breakout-safe JSON-LD injection into the homepage, built from
  the cached `schema.json`, plus a `gt_ai_presence_head()` Twig function for
  explicit placement.
- `/robots.txt` decoration with the AI-crawler allow-list and a `Sitemap:`
  directive (or a generated `robots.txt` when the app has none).
- Agent-traffic reporting to the Globetrotters ingest endpoint, so an apex
  install shows up in Presence Analytics: a bounded NDJSON buffer, flush via
  `gt:presence:flush`, `kernel.terminate` and the scheduler, dropped-event
  accounting, and proxy/CDN-aware client IP resolution.
- `gt:status` for cache, refresh and reporting health.
- SSRF guard, 5-second timeout and 1 MiB per-file size cap on artefact fetches.
- `Access-Control-Allow-Origin: *` on every artefact response, so
  browser-context agent clients can read the public, unauthenticated discovery
  documents cross-origin.

### Security

- Artefact responses carry `X-Content-Type-Options: nosniff`,
  `Cache-Control: no-store, private` and `Surrogate-Control: no-store`, which
  survive Symfony's own `HttpCache`.
- Atomic cache publication: a failed pool write leaves no orphaned body items
  behind and never replaces the served bundle.
- Ingest acknowledgements must match exactly before a flush is treated as
  accepted.

[0.2.0]: https://github.com/globetrotters-ai/gt-symfony-bundle/releases/tag/v0.2.0
