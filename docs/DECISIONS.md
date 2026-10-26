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

## D15. Git deploys: pure-PHP smart-HTTP push to a branch Stillpress owns

The maintained PHP Git libraries shell out to the `git` binary, which needs `proc_open` and an installed Git, and most
WordPress hosts have neither. Provider APIs (GitHub's Git Data API) are provider-specific and cost one call per file. So
the deployer speaks Git's smart-HTTP protocol itself: it builds blob, tree and commit objects, writes a version-2 pack of
whole objects (no deltas; zlib only) and sends it to `git-receive-pack` with a token over HTTPS. Object IDs and tree
ordering are checked against IDs computed by Git itself, and the integration tests push to a real `git-http-backend` and
run `git fsck` on the result. SSH remotes and SHA-256 repositories aren't supported.

The deployer owns one branch and commits the whole site as its tree, with the current head as parent. That keeps every
push a fast-forward, keeps history (a bad publish can be reverted with Git), and needs no fetch: we never read the
remote tree, only its refs. A branch whose head isn't the commit we last pushed is refused unless the admin opts in
("Replace the branch's contents"), which mirrors the marker rule for folders. When the head is unknown, every blob is
sent, since the server's contents can't be known without a fetch.

Large first deploys would exceed one slice, PHP's memory and some hosts' request limits, so blobs beyond 16 MB go up in
batches, each in a commit on a temporary `stillpress-upload-<key>` branch that keeps them reachable (unreachable objects
may be garbage-collected) until the final push moves the real branch and deletes the temporary one.

Writing the tests also showed that Requests ignores its `verify` option inside `request_multiple()`, so S3's concurrent
uploads had been verified against the system CA store rather than WordPress's bundle. `RequestsTransport` now sets the
bundle on every curl handle.

## D16. Netlify through file-digest deploys, never spending a deploy on an unchanged site

Netlify's API offers ZIP deploys and file-digest deploys. ZIP deploys re-upload the whole site every time and are capped
at 25,000 files; with digests, Netlify asks only for content it doesn't already store, so an incremental publish uploads
a few files. Every deploy still lists every file, because a Netlify deploy is always the whole site.

The API allows three deploys a minute and 100 a day. Auto-publish debounces content changes by a minute, so a busy site
could otherwise run out; a publish whose files match the last deploy therefore makes no API call at all, and a 429 stops
the deploy with a message while the changes stay pending for the next publish.

Requests over 30 seconds are cut off, so deploys are created `async` and polled for up to 20 seconds per call; the deploy ID
and a fingerprint of its file list are saved, and the next slice resumes it rather than creating another (which would
spend another deploy). Serverless functions aren't uploaded: a static export has none.

## D17. Cloudflare through Workers static assets, not Pages direct upload

Cloudflare documents only the last step of a Pages direct upload (create a deployment from a manifest of "uploaded
content hashes"). The upload steps (`upload-token`, `check-missing`, `assets/upload`, `upsert-hashes`) exist only in
`wrangler`'s source, and its content hash is BLAKE3, which PHP lacks: we would depend on undocumented endpoints and
either a new dependency or our own hash implementation. Workers static assets has a documented direct upload, its
documentation doesn't fix a hash algorithm, and Cloudflare's own example uses truncated SHA-256, so the deployer uses
that with PHP's built-in `hash()`. Where the documentation is ambiguous (its prose says multipart, its example sends
JSON) we follow `wrangler`'s implementation: one multipart part per file, named by its hash, with base64 contents and the
served content type (`application/null` for none). An assets-only Worker is uploaded with only a metadata part, again as
`wrangler` does.

Existing Cloudflare Pages projects can keep Pages by connecting it to the repository the Git deployer pushes to. Revisit
if Cloudflare documents the Pages upload API, or if it changes or starts verifying the Workers asset hash.

## D18. Schedules are local wall-clock times, re-armed as single actions

"Publish at set times" means times people read on a clock: 09:00 should stay 09:00 after a daylight-saving change.
The nightly full rebuild used one recurring Action Scheduler action every 86,400 seconds, anchored at 03:00 with the
site's UTC offset at the moment it was scheduled, so after each clock change it ran an hour early or late, and stayed
that way. Both schedules now compute their next run with `Build\Schedule` (pure, unit-tested across the 2026 European and
US changes) in `wp_timezone()`, schedule it as a single action, and schedule the following run when it fires. A run
that never got scheduled (a fatal error in a handler) is re-armed by `ensure_recurring()` on the next request, and
existing recurring rebuilds from earlier versions are replaced the first time it runs.

On the night clocks go forward, a time inside the skipped hour fires just after it (02:30 becomes 03:30); on the night
they go back, a repeated time fires once. The publishing mode replaced the `auto_publish` switch: stored values and
`settings set auto_publish` from scripts map to `immediate` or `manual`. In scheduled mode a publishing time with
nothing pending starts no build, so frequent times cost nothing on quiet days.

