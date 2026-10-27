# Security policy

## Reporting a vulnerability

Please **do not open a public issue** for a security problem. Email
**security@globetrotters.ai** instead, with:

- the affected version (`composer show globetrotters-ai/symfony-bundle`),
- what an attacker can do, and the steps or a proof of concept to reproduce it,
- your Symfony and PHP versions, and anything unusual about the setup (reverse
  proxy, CDN, cache pool).

We acknowledge reports within three working days, keep you updated while we
fix, and credit you in the release notes unless you would rather we did not.
Please give us a reasonable time to ship a fix before disclosing.

## Supported versions

Security fixes land on the latest minor release. The bundle is pre-1.0, so
upgrade to the newest `0.x` to receive them.

## Scope

In scope: this bundle's code — the request path that serves the artefacts,
robots.txt, the sitemap and the API catalog; the refresh and its SSRF guard;
the reporting lane (ingest token handling, the event buffer, the page-view
counter). Vulnerabilities in the Globetrotters platform itself (Studio, the
hosted presence, the ingest API) are also welcome at the same address.
