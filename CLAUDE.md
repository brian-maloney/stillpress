# Stillpress

A GPL-2.0-or-later WordPress plugin that publishes a WordPress site as static files. Every feature is free: no premium
tier, no license keys, no phoning home.

Read before changing things: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/THREAT_MODEL.md](docs/THREAT_MODEL.md),
[docs/SUPPORT.md](docs/SUPPORT.md). Record non-obvious choices in [docs/DECISIONS.md](docs/DECISIONS.md) (append a new `Dn` entry).

## Hard rules

- **Clean-room.** Never read, copy or port code from other static-publishing plugins (Staatic, WP2Static, Simply Static or
  any other). Don't use their names, logos or wording anywhere in code, UI or docs.
- **Licensing.** Every dependency must be GPL-compatible. Check before adding one.
- **No external calls** without explicit user opt-in. No runtime downloads. No obfuscated or minified-only source.
- **Security.** Capability + nonce on every action. Sanitize input, escape output. All output paths go through
  `Stillpress\Output\PathMapper`; all fetched URLs go through `Stillpress\Security\UrlPolicy`. Never log secrets.
- **Check WordPress behavior** against developer.wordpress.org (or the core source in the container) instead of assuming.
- Supported range: WordPress 6.6+, PHP 7.4+. No PHP 8-only syntax (no union types, `match`, named args, constructor promotion,
  `readonly`, enums, nullsafe operator). PHPCompatibilityWP enforces this.

## Commands

There's no PHP on the host. Everything runs in Docker through wp-env.

```bash
npm install                 # once
npm run env:start           # WordPress on http://localhost:8888 (admin/password), tests site on :8889
npm run composer install    # PHP dependencies (Composer runs in the container)
npm run test:unit           # PHPUnit unit suite (no WordPress loaded)
npm run test:integration    # PHPUnit against the WordPress test suite (tests-cli container)
npm run test:system         # Real builds over HTTP on the tests site (ERASES the :8889 site's content)
npm run lint:php            # PHPCS: WordPress Coding Standards + PHPCompatibilityWP
npm run lint:php:fix        # PHPCBF auto-fix
npm run wp -- stillpress build --sync   # WP-CLI inside the dev site
```

Run one test: `npm run test:unit -- --filter PathMapperTest`.

System tests: `tests/system/IncrementalDifferentialTest.php` applies random edits and requires incremental output to equal
a full build. `STILLPRESS_DIFF_ITERATIONS=500` for a long run; a failure prints `STILLPRESS_DIFF_SEED=<n>` to reproduce it.
Pass env vars through wp-env with `npm run test:system -- ...` or `wp-env run tests-cli -- env VAR=1 vendor/bin/phpunit ...`. Check the PHP floor and ceiling quickly with
`docker run --rm -v "$PWD":/app -w /app php:7.4-cli vendor/bin/phpunit` (and `php:8.5-cli`).

## Conventions

- PHP namespace `Stillpress\` (PSR-4 under `src/`, file name = class name). Hooks, options, capabilities, transients and
  meta keys are prefixed `stillpress_`. Tables are `{$wpdb->prefix}stillpress_*`. Text domain `stillpress`.
- WordPress Coding Standards: tabs, `array()`, Yoda conditions, snake_case functions and variables, docblocks on everything.
- No DI container. `Plugin` builds objects with explicit constructor arguments.
- Pure logic (path mapping, URL rewriting, query-signature matching) has no WordPress calls so it can be unit tested.
  Anything touching WordPress gets an integration test.
- UI, REST and WP-CLI are thin adapters over `src/Service/`. A feature isn't done until the CLI can do it too.
- Every `do_action` / `apply_filters` has a docblock with `@since`, `@param` and a description. Each one is listed in docs/HOOKS.md.
- Tests: unit tests in `tests/unit/` (`*Test.php`, extend `PHPUnit\Framework\TestCase`); integration tests in `tests/integration/`
  (extend `WP_UnitTestCase`).

## Commits

Small and focused, each with tests, lint clean, and a message that explains *why*. Don't push unless asked.
