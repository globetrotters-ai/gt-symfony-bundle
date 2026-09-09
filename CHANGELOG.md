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
  discovery `<link>` relations in the homepage `<head>`, and a visible footer
  anchor — the half a crawler actually follows. Both mirror the snippet the
  Studio hands out, so a hand-pasted block and an installed bundle produce the
  same markup, and neither is injected twice.
- `breadcrumb.anchor_text` (defaults to the destination name from `ai.json`) and
  `breadcrumb.inject_anchor` for placing the footer link yourself.
- `gt_ai_presence_breadcrumb_head()` and `gt_ai_presence_breadcrumb_link()` Twig
  functions, for explicit placement of either half.

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
