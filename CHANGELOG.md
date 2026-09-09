# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0] - 2026-09-08

### Added

- **Install profiles.** A `profile` option selects between `full_apex` (the
  previous and default behaviour — this site is the whole presence) and
  `subdomain_breadcrumb`, the both-lanes deployment the product recommends:
  keep serving the artefacts at the apex *and* link back to the presence
  published at the Globetrotters host, which is otherwise unreachable to a
  crawler that does not already know its name.
- Breadcrumb injection on the `subdomain_breadcrumb` profile: the agent
  discovery `<link>` relations in `<head>`, and nothing else. The three
  agent-discovery relations are emitted on every HTML page, since they name
  site-level surfaces and an agent may arrive on any page; `rel="alternate"`
  stays on `homepage_path` alone, because it points at a document describing
  the destination rather than that page. Matches the WordPress plugin.
  Installing the bundle is transparent to visitors — it never injects visible
  markup into a design it does not own. The visible anchor, which is the half
  that actually passes crawl authority, is available as
  `gt_ai_presence_breadcrumb_link()` for you to place inside your own layout.
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
  and revalidates it against the request, instead of dropping it. An application
  that publishes an entity-tag keeps its conditional GETs through the JSON-LD,
  breadcrumb and `robots.txt` injections; a client holding what was actually
  served still gets a `304`. The weak/strong flavour is preserved, and a response
  that published no `ETag` is still left without one. `Last-Modified` and the
  digest headers are still dropped — a changed body says nothing about when the
  resource changed, and a subtly wrong digest is worse than none.

### Notes

- The canonical Globetrotters origin is **derived, never configured**: it is read
  from the cached `ai.json` on every refresh, so a custom-hostname activation or
  detach is picked up without a configuration change or a redeploy.
- The set of paths served locally is identical on both profiles. None of them is
  on the backend's offload list, so the profile changes the breadcrumb, not the
  footprint.

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
