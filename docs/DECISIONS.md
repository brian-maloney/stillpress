# Decisions

A running log of non-obvious choices. Newest at the bottom. Each entry says what we chose, why, and what would
make us revisit it.

## D1. Support WordPress 6.6+ and PHP 7.4+

WordPress 7.0 raised core's own floor to PHP 7.4, and Action Scheduler requires 7.2 and WordPress 6.5. WordPress 6.6
moved the editor SlotFills into `@wordpress/editor`, so one integration covers the post and site editors. 6.6 is about
two years of releases back from 7.1. Revisit when WordPress raises its PHP floor.

## D2. Bundle Action Scheduler (GPL-3.0-or-later) for background work

It survives timeouts, retries failed actions, and resolves multiple bundled copies by version. Our own code stays
GPL-2.0-or-later. Because GPLv3 code is bundled, the distributed plugin as a whole is effectively under GPLv3 terms,
which WordPress.org accepts. A `QueueInterface` keeps a WP-Cron fallback possible.

## D3. Rewrite URLs as text, not through a DOM

A DOM rewrite misses inline JavaScript, JSON-LD, JSON-escaped URLs in data attributes, and CSS. A single boundary-aware
text replacement over every encoded form of the origin covers all of them, and works the same for HTML, XML feeds,
sitemaps and CSS. Link *discovery* does use `WP_HTML_Tag_Processor`, because there we need attribute structure.

## D4. Dependency tracking is a conservative over-approximation

Exact tracking can't be done (direct `$wpdb`, transients, external APIs, time). We record objects, query signatures
and options, mark URLs with unknown dependencies as volatile, run a scheduled full rebuild (setting: off/daily/weekly,
default daily), and require differential tests to show incremental output equals full-rebuild output.

## D5. Development runs entirely in Docker through wp-env

The host has no PHP. PHP, Composer, PHPUnit, PHPCS and WP-CLI all run in the wp-env containers. Composer's platform is
pinned to PHP 7.4 so resolved dependencies stay installable on the lowest supported version.

wp-env 12 deprecates its built-in tests environment in favor of separate config files. It still works and is what
`npm run test:integration` uses; move to a `--config` file if it's removed.

## D6. PHPUnit 9.6 with Yoast polyfills 2.x

PHPUnit 9.6 is the newest line that runs on PHP 7.4 and is supported by the WordPress test suite. The container also
ships PHPUnit 10; we always run the Composer-installed `vendor/bin/phpunit` so results don't depend on the image.

## D7. Loopback spike results (WordPress 7.1.3, PHP 8.3, wp-env)

Tested before writing the renderer:

- **The site often can't reach its own public URL.** From the wp-env CLI container, `http://localhost:8888` fails with
  "connection refused", because the port is published on the host, not inside the container. The web container can reach
  it. WP-CLI builds therefore need the connect-to pin. `CURLOPT_CONNECT_TO` (`localhost:8888:wordpress:8888`) keeps the URL,
  Host header and TLS SNI unchanged while changing where the socket connects. We use it rather than `CURLOPT_RESOLVE`,
  because it can change the port too.
- **The pin must be attached in two places.** `wp_remote_request` goes through `WP_Http`, which fires the `http_api_curl`
  action. `WpOrg\Requests\Requests::request_multiple` (used for concurrent renders) bypasses `WP_Http`, so the pin goes in
  a Requests `curl.before_request` hook. That hook fires for single and multi handles alike; `curl.before_send` doesn't
  fire for multi requests.
- **The render header round trip works**: a request header reaches the render, which can send back a response header.
- **`pre_option` captures every option read**, including `_transient_*` and `_site_transient_*` rows and Action Scheduler
  internals. Those must be filtered out as dependency triggers. The home page of Twenty Twenty-Five read about 80 options.
- **`the_posts` catches block-theme database objects** (here a `wp_navigation` post), so menus, synced patterns and
  customized templates become post dependencies without special handling.
- **Concurrent requests work**: four renders via `request_multiple` in about 0.03 s on this setup.
- Unpinned and shortlink URLs (`/?p=1`, `/hello-world/`) come back as 301s to the canonical permalink. With `follow_redirects` off,
  the crawler records them as redirects.
