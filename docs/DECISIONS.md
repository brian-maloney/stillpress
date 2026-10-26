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

## D8. URL rewriting: absolute URLs only in Milestone 1

Every encoded form of the origin (raw, JSON `\/`, JavaScript `/`, URL-encoded, HTML entities; with or without a scheme)
is rewritten to the public base URL, spelled in the same encoding. Root-relative output is deferred: deciding where a
relative URL is safe (it isn't in canonical links, Open Graph tags, feeds, sitemaps or share links) needs context the textual
rewriter doesn't have. Absolute URLs work on any host once the public URL is set.

When home is in a subdirectory (`https://origin/blog`), paths under it map to the public base path and paths outside it
(`/wp-includes/…`) keep their path on the public host. When the public base has a path (`https://example.org/static`),
files outside it can't be placed in the output root and are reported instead of written.

Protocol-relative matches (`//host`) must not follow a URL character, so `https://elsewhere//origin/x` isn't touched.

## D9. Direct file operations instead of WP_Filesystem for build output

The build cache, ZIP archives and local deploy targets are written with PHP's file functions (atomic temp file + rename).
WP_Filesystem exists so plugins can write into WordPress's own code directories through FTP credentials when the web server
user can't. We never write there, and a site build means thousands of files, where the abstraction costs time and gives
no safety. All paths still go through `PathMapper`, and targets through `TargetPolicy`. Files are created with the process
umask, like core's uploads.

## D10. A page stays published while the origin still serves it

Full builds re-render every URL that is currently published, then crawl from the seeds. A URL leaves the static site only
when the origin answers 404/410 (or it starts redirecting). We don't garbage-collect pages that merely stopped being linked:
WordPress still serves them, so removing them would break inbound links and bookmarks. This also gives the differential
test a precise target: after any edits, an incremental build must produce exactly what a full build produces.

## D11. Incremental builds follow only links to new URLs

While re-rendering affected pages, an incremental build queues a discovered link only if that URL isn't published right
now. That's how a new tag archive or a newly uploaded image gets picked up without re-crawling the site. URLs that returned
404 or redirected before count as unpublished: the differential test found that a deleted post can push `/page/4/` to a
404 and a later new post can bring it back, and only re-checking such links catches that. Assets
whose files change in place keep their URL and are refreshed by the next full build. Theme and plugin updates trigger a full
build anyway (`upgrader_process_complete`).

## D12. Secrets are keyed from the wp-config salts, per site and slot

Stored deploy credentials are sealed with sodium `secretbox` under a key derived by HKDF-SHA256 from
`wp_salt('auth') . wp_salt('secure_auth')`. The salts are the only per-install secret that lives outside the database,
so a leaked database dump alone doesn't reveal credentials. `wp_salt()` (rather than reading the constants) also covers
installs whose wp-config.php still has the sample phrases, where core generates salts into the database; there the
protection against a dump is weaker, which matches what core's own cookies get. The HKDF context is
`<blog_id>|<deployer>|<field>`: secretbox has no associated data, and binding the key to the slot stops anyone who can
write options from moving a ciphertext into a field whose value is shown or sent elsewhere. Rotating the salts loses stored
secrets on purpose; Site Health and `secret list` say which ones to enter again. Constants and environment variables
always win, so hosts that inject credentials never store them at all.

## D13. Hand-written SigV4 instead of the AWS SDK

The AWS SDK for PHP 3.x requires PHP 8.1 and pulls in Guzzle 7/8, JMESPath and `aws-crt-php`. Bundling it would mean
raising Composer's platform pin (D5), turning off Composer's platform check so 7.4 sites still load, and prefixing Guzzle
(Strauss or similar) so it can't clash with other plugins' copies; S3 would then be unavailable below PHP 8.1. A static-site
deploy needs five S3 calls and one CloudFront call, so we sign requests ourselves (`Deploy\Aws\SigV4`, about 250 lines).

This is request authentication, not new cryptography: an HMAC-SHA256 over a canonical request, computed by PHP's
`hash_hmac()`, with TLS from curl. The risk is a canonicalization mistake, which fails loudly (403) rather than silently.
It is tested against AWS's SigV4 test suite (`tests/fixtures/aws4_testsuite`, Apache-2.0, from botocore's copy, with its
LICENSE and NOTICE kept) and against the four worked examples from AWS's S3 signing documentation (retrieved from an
archived copy of `sig-v4-header-based-auth.html`, since that page now redirects). S3 mode doesn't normalize paths, as AWS
requires. Not supported, on purpose: instance-profile, SSO and assume-role credential providers (use constants or
environment variables with temporary credentials and a session token instead), and chunked/streaming signatures (large files
use multipart uploads). Revisit if we raise the PHP floor to 8.1+ and want those credential providers.

## D14. SFTP through phpseclib 3.0, unprefixed, with pinned host keys

phpseclib (MIT) is the only maintained pure-PHP SSH implementation, so SFTP works without the `ssh2` extension, which few
hosts install. The 4.x line requires PHP 8.1; the 3.0 line is still maintained alongside it and supports PHP 5.6+,
so `^3.0` resolves to 3.0.x under our 7.4 platform pin (D5).

We don't prefix its namespace for now. phpseclib 4 moved to a `phpseclib4` namespace, so only other plugins' copies of
3.0.x can collide with ours; whichever autoloader registers first wins. Within 3.0.x the API we use (`SFTP`,
`PublicKeyLoader`) is stable, so an older foreign copy should still work. Revisit when release packaging lands (M5):
prefixing with a build-time tool (Strauss or PHP-Scoper) is cheap then.

Host keys are pinned by fingerprint, required before the first deploy. Trust on first use would make the very first
connection, which also sends the password, the one an attacker needs to intercept. `target check` prints the presented
fingerprint so the admin can compare it with `ssh-keygen -lf` on the server.

rsync, which the plan listed beside SFTP, isn't included: it needs the `rsync` and `ssh` binaries plus `proc_open`, which
most WordPress hosts forbid. SFTP's change-only uploads cover the same need. Revisit if users on VPS hosts ask for it.

Integration tests run against a real OpenSSH server built from `alpine` (tests/sftp), not a mock: host-key, login and
rename behavior are what matter, and only a real server shows them.

