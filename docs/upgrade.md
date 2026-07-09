# Upgrade Notes

Updated: 2026-07-09

## Laravel 13 Status

The application is already upgraded to Laravel 13 and was verified against the official Laravel 13 upgrade guide through Laravel Boost documentation search.

Verified local versions:

- PHP 8.5.7
- Laravel Framework 13.19.0
- Laravel Boost 2.4.12
- Laravel MCP 0.8.2
- Laravel Pint 1.29.3
- PHPUnit 12.5.31
- Tailwind CSS 4.3.2

## Dependency Requirements

The Laravel 13 guide requires these project-level constraints, which are already present:

- `laravel/framework`: `^13.8`
- `laravel/boost`: `^2.4`
- `laravel/tinker`: `^3.0`
- `phpunit/phpunit`: `^12.5`

Pest is not installed in this project, so the Laravel 13 Pest constraint does not apply. PHPUnit 13 is available upstream, but this project intentionally stays on PHPUnit 12 because that is the Laravel 13 upgrade-guide target and the current test suite is configured for PHPUnit 12.

## Compatibility Review

Reviewed Laravel 13 breaking-change areas that apply to this application:

- No direct application references to deprecated `VerifyCsrfToken` or `ValidateCsrfToken` middleware aliases.
- Cache config already includes `serializable_classes => false`.
- Cache, Redis, and session prefixes are explicitly configured in application config files.
- No custom queue driver, cache store, dispatcher, response factory, notification, or `MustVerifyEmail` implementations were found.
- No direct references to the old Bootstrap pagination view names were found.
- No domain routes are registered, so Laravel 13 domain route precedence does not affect current routes.
- No custom model `boot()` methods instantiate models during model booting.

## MCP

Laravel Boost is the only required MCP server for Laravel upgrade work. The project config is:

```toml
[mcp_servers.laravel-boost]
command = "php"
args = ["artisan", "boost:mcp", "--env=local"]
```

The `--env=local` flag is required because Laravel Boost only registers its MCP command in local/debug environments.

## Commands Used

Use these commands for future Laravel 13 maintenance:

```bash
composer update laravel/framework laravel/boost laravel/tinker laravel/pint phpunit/phpunit --with-all-dependencies
composer validate --strict
composer audit
./vendor/bin/pint --dirty --format agent
php artisan test
npm run build
```

The 2026-07-09 targeted Composer update changed only `league/mime-type-detection` from 1.16.0 to 1.17.0.

## Verification Results

The Laravel 13 verification pass completed with:

- `composer validate --strict` passed.
- `composer audit` found no security advisories.
- `./vendor/bin/pint --dirty --format agent` passed.
- `php artisan test` passed: 37 tests, 151 assertions.
- `npm run build` passed. Vite still reports the existing large JavaScript chunk warning.

Not installed in this project:

- Pest
- PHPStan
- Rector
- `npm run lint`
