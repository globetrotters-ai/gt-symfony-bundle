# Contributing

Thanks for your interest in the Globetrotters AI Presence bundle.

**Issues are welcome; pull requests are by invitation.** The bundle is one of
two apex companions (with the
[WordPress plugin](https://github.com/globetrotters-ai/gt-wordpress-plugin))
that must behave identically and track the Globetrotters platform, so changes
are made by the maintainers. A pull request opened without an invitation will
be closed, though the idea in it is still welcome as an issue.

## Opening an issue

- **Bug**: use the bug report template. Include the bundle version, PHP and
  Symfony versions, the output of `bin/console gt:status`, and what you
  expected against what happened. Redact your ingest token.
- **Feature request**: describe the problem you are trying to solve before the
  solution you have in mind.
- **Security problem**: never in a public issue. See [SECURITY.md](SECURITY.md).

## If you have been invited to send a pull request

```bash
make install   # composer update
make ci        # php-cs-fixer + phpstan + phpunit, the gates CI runs
```

Run `make cs-fix` before committing. CI also runs a gitleaks secrets scan over
the full history and the test matrix across PHP 8.2–8.4 and Symfony 6.4 / 7.4.
`CLAUDE.md` describes the architecture and the invariants a change must keep.
By contributing you agree that your contribution is licensed under the
[MIT License](LICENSE).
