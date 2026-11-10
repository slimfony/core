# Contributing

Thank you for looking at slimfony/core. Please read [GUIDELINES.md](GUIDELINES.md) first: it says what the core is for, what it will not grow into, and the rules every change follows (section 6, *Definition of done*, is binding).

## Setting up

```
composer install
composer ci
```

`composer ci` runs the tests, PHPStan at its strictest level and the code style check; a change is ready when it exits with 0. The tests that need a real database run when `TEST_DATABASE_URL` is set (CI always sets it), for example `pdo-pgsql://postgres:postgres@127.0.0.1:5432/slimfony_test`; the tests that need APCu want `php -d apc.enable_cli=1`. Without them those tests are skipped, not failed. JWT tests generate keys with OpenSSL; if that fails on your machine, point `OPENSSL_CONF` at an existing `openssl.cnf`.

## A change

- Keep it small, and keep the library small: a new dependency or abstraction has to earn its place (GUIDELINES section 1). Deleting code is welcome.
- Behaviour is tested, deterministically and few; reuse `tests/Support`. The samples in the README and in the guide are executed by `tests/ReadmeTest.php` (and its links are checked), so they must keep working.
- Public changes go into the `[Unreleased]` part of the CHANGELOG, and a breaking one also into UPGRADE.md. See [STABILITY.md](STABILITY.md) for what counts as public.
- Commit messages say what and why in the first line. One topic per commit; the CI must be green.

## Reporting

A bug or an idea: open an issue with what you did, what you expected and what happened. A vulnerability: not as an issue; follow [SECURITY.md](SECURITY.md).

By taking part you agree to the [Code of Conduct](CODE_OF_CONDUCT.md).