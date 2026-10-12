# Contributing

Thanks for helping! Bug reports and pull requests are welcome at
https://github.com/PolyzonOrg/polyzon-php. For security issues follow [SECURITY.md](SECURITY.md) instead of
opening an issue.

## Development

Requires PHP 8.2+ with `ext-curl`, `ext-json` and `ext-openssl` (for the TLS tests), Composer 2, and pcov or
Xdebug for coverage.

```sh
composer install
composer validate --strict
composer test        # PHPUnit
composer coverage    # PHPUnit with line coverage; fails below 90 %
composer analyse     # PHPStan, level max + strict rules
composer cs          # PHP-CS-Fixer check (PER-CS 2.0); `composer cs:fix` fixes
composer audit       # known vulnerabilities in the (dev) dependencies
```

The test suite never calls the real API. `tests/bootstrap.php` starts a mock API on 127.0.0.1 (PHP's
built-in server with `tests/Fixtures/router.php`); the TLS, proxy and batch tests start small socket servers
(`tests/Fixtures/socket-server.php`). Retries never really wait: the tests replace the wait through
`Polyzon\Internal\Hooks`.

## Pull requests

- One focused change per pull request, with tests for new behaviour and fixed bugs.
- Keep every public symbol documented and update `README.md`, `README.ru.md` and the `Unreleased` section of
  `CHANGELOG.md`.
- Use [Conventional Commits](https://www.conventionalcommits.org/) for titles, e.g.
  `fix: honour Retry-After dates`, `feat: add …`.
- No runtime dependencies besides `ext-curl` and `ext-json`.

## Releasing (maintainers)

Packagist builds the package straight from this repository; there is no publish workflow.

1. Once: submit `https://github.com/PolyzonOrg/polyzon-php` at https://packagist.org/packages/submit (as the
   `polyzon` vendor) and make sure the GitHub integration (the Packagist webhook / GitHub app) is enabled, so
   every push updates the package.
2. Bump `Client::VERSION` in `src/Client.php`, move the changelog entries under the new version, merge.
3. Push a tag `vX.Y.Z` on `main` (`git tag -s v1.0.1 -m v1.0.1 && git push origin v1.0.1`) and create the
   GitHub release from it. Packagist picks the tag up within a minute; Composer users get
   `composer require polyzon/polyzon-php:^X.Y`.

The dist archive contains only `src/`, `composer.json` and the docs (see `.gitattributes`); CI checks that.
