# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
