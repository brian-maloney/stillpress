# Threat model

Stillpress is a high-risk surface. It makes HTTP requests from the server, writes files to disk, and (from
Milestone 2) holds credentials for remote hosts. This document lists what we protect, where trust changes hands,
and how each threat is handled. Every mitigation listed here should have a test; the "Tests" column names the suite.

## Assets

| Asset | Why it matters |
|---|---|
| Unpublished content (drafts, private, password-protected, scheduled posts) | Leaking it to a public static site is a privacy breach that can't be undone once deployed. |
| The server filesystem | The plugin writes and deletes files. A bad path could overwrite WordPress or leak `wp-config.php`. |
| Deploy credentials (M2+) | Keys to S3, SFTP, Git or hosting APIs give an attacker the public site. |
| Origin availability | A crawler that runs away can take down the WordPress origin. |
| Admin sessions and capabilities | Publishing and settings must be limited to the right users. |

## Trust boundaries

1. **Admin browser → WordPress** (REST, admin-post, AJAX). Requests can be forged (CSRF) or come from low-privilege users.
2. **WordPress → itself** (loopback render requests). The render token travels over HTTP; anything that can reach the
   site can send a forged token.
3. **Crawled HTML/CSS/XML → crawler.** Rendered output is *untrusted data*. Any plugin, theme or comment author can put
   arbitrary links, paths and markup in it.
4. **WordPress → filesystem** (build cache, local directory target, ZIP files, asset copying).
5. **WordPress → remote deploy targets** (S3 and CloudFront so far). Requests carry credentials; answers are untrusted.
6. **WP-CLI operator → WordPress.** Trusted at the level of the shell user.

## Threats and mitigations (STRIDE)

| # | STRIDE | Threat | Mitigation | Tests |
|---|---|---|---|---|
| T1 | Spoofing / Info disclosure | **SSRF**: crawled links, "additional URLs" settings or redirects make the server request internal services (`http://169.254.169.254/`, `http://localhost:6379/`). | `UrlPolicy`: only `http`/`https`; host and port must belong to the origin set; userinfo (`user@host`) rejected; redirects never followed automatically (`redirection => 0`), each `Location` re-checked by the policy; the optional connect-to pin is set only by an admin or constant. | `UrlPolicyTest`, `UrlClassifierTest` |
| T2 | Tampering | **Path traversal / zip-slip**: a URL like `/..%2f..%2fwp-config.php` or `/%5c..%5c` maps to a file outside the output root. | `PathMapper`: decode once; reject `..`, `.` segments, NUL, `\`, control characters, drive letters, overlong segments and reserved device names; normalize; final path is checked to start with the root; parent directory `realpath` must stay inside the root; symlinks inside the root are refused (`lstat`). ZIP entry names go through the same mapper. | `PathMapperTest` (incl. 5,000-case fuzz), `LocalDirectoryTest`, `ZipTest` |
| T3 | Info disclosure | **Secret files copied as "assets"**: a link to `/wp-config.php.bak`, `/.env` or `/wp-content/debug.log`, or a symlink in uploads pointing elsewhere. | `AssetCopier` reads from disk only inside allowed roots (uploads, themes, plugins, wp-includes), with an extension allowlist; denies `.php*`, `.phar`, dotfiles, `.env`, `.sql`, `.log`, `wp-config*`, backups (`.bak`, `.old`, `~`); `realpath` must stay inside the allowed root. Anything else is fetched over HTTP, where WordPress and the web server apply their own access rules. | `AssetCopierTest` |
| T4 | Info disclosure | **Unpublished content in output**: drafts, private posts, previews or the admin bar leak into the static site. | Renders carry no cookies, so they run as an anonymous visitor. Seeds include only `publish` posts of public post types. Password-protected posts are excluded by default. A response whose `<body>` has the `logged-in` class is rejected. URLs with `preview=`, `p=` or `page_id=` queries are rejected. | `FullBuildTest` (system), `RecorderTest` |
| T5 | Tampering / DoS | **Destructive deploy target**: an admin (or attacker with admin) sets the output directory to `ABSPATH`, `wp-content` or `/`, and the deployer deletes or overwrites WordPress. | Targets that are the filesystem root, or that equal or contain `ABSPATH`, `WP_CONTENT_DIR`, the uploads, plugins, mu-plugins or themes directories, are rejected; so are targets inside plugins, themes, `wp-admin` or `wp-includes`. Paths are compared after resolving symlinks. The target must be empty or contain our `.stillpress-target` marker. Deployers delete only files listed in their own manifest. In multisite, only a super admin or a constant can set filesystem paths. | `TargetPolicyTest`, `LocalDirectoryTest`, `UninstallTest` |
| T6 | Elevation of privilege | **CSRF / missing capability checks** on publish, settings and downloads. | Each admin form and action has a nonce. Each REST route has a `permission_callback` that checks capabilities. Capabilities are custom primitives (`stillpress_manage`, `stillpress_publish`) plus a `stillpress_publish_post` meta cap mapped in `map_meta_cap`. | `RestPermissionsTest`, `AdminActionsTest`, `CapabilitiesTest` |
| T7 | Spoofing / DoS | **Forged render token** used to write junk dependency rows, or to make a request render differently. | HMAC-SHA256 with a per-site random key (`autoload=no`) over build ID, URL ID and expiry; compared with `hash_equals`; the build must be active. The token grants **no** privileges: it only turns on recording and disables the admin bar and page caching. Dependency rows per URL are capped (20,000). The background-kick endpoint uses a separate HMAC and can only run a slice of an already-active build, under the build lock. | `RenderTokenTest`, `RecorderTest` |
| T8 | Tampering | **Stored XSS in admin screens** through crawled URLs, titles or log lines. | Everything from the crawl is treated as untrusted and escaped on output (`esc_html`, `esc_url`, `esc_attr`). Log messages are plain text, never HTML. | Code review; log viewer uses `textContent` (see `assets/js/admin.js`) |
| T9 | DoS | **Origin overload or crawler traps** (infinite calendars, faceted URLs). | Concurrency cap (default 4), time budget per batch, response size cap (default 20 MB), maximum URL count, depth limit, query-string URLs skipped by default, back-off on 5xx responses. | `UrlClassifierTest`; limits exercised by system tests |
| T10 | Info disclosure | **Credential leakage** through logs, REST, WP-CLI output, exports or error messages. | Secrets resolve from constants, then environment variables, then sodium-encrypted options (a key per site and slot, so ciphertexts can't be moved between fields). The CLI takes values on standard input, never as arguments, so they stay out of shell history and process lists. Secret fields are write-only in the UI. `Redactor` scrubs known secret values from every log line. Secrets never appear in REST responses or `wp stillpress settings` output. | `RedactorTest`, `SecretBoxTest`, `SecretsTest`, `CliTest` (system) |
| T11 | Info disclosure | **ZIP archive reachable at a public URL.** | ZIPs are written outside public paths where possible, or with a random name and deny rules, and downloaded through an authenticated, nonce-checked endpoint that streams the file. | `ZipTest` |
| T12 | Tampering | **Supply chain**: dependencies or runtime downloads introduce malicious code. | Composer and npm lockfiles are committed. Nothing is downloaded at runtime. No external requests without opt-in. CI checks that dependency licenses are GPL-compatible. | CI |
| T13 | Info disclosure | **Multisite cross-site access**: site A's admin publishes into or reads site B's output. | Per-site tables, cache directories and targets; paths derived from the site ID; path settings restricted to super admins. | `MultisiteTest` |
| T14 | Spoofing / DoS | **Forged background kick** (`admin-ajax.php?action=stillpress_run`) to start work. | HMAC over the build ID; only runs a slice of an active build; the lease lock means at most one slice runs at a time. | `AdminActionsTest` |
| T15 | Spoofing / Info disclosure | **Remote target misuse**: a custom S3 endpoint aimed at an internal service (SSRF with signed requests), a redirect that replays a signed upload elsewhere, or a TLS man-in-the-middle reading credentials. | The endpoint is a host[:port] matched against a strict pattern and always HTTPS; on multisite only a super admin may set it. Redirects are never followed. TLS is verified against WordPress's CA bundle. Only the request's own host is contacted. | `TargetConfigTest`, `S3Test` |
| T16 | Tampering | **Deleting someone else's objects**: a deploy to a shared bucket removes files it didn't create. | The S3 deployer deletes only keys recorded in its own `target_files` rows for that endpoint, bucket and prefix; changing any of them starts a fresh record. Prefixes with `.` or `..` folders are refused. | `S3Test` |
| T17 | Info disclosure | **Credentials in settings, logs or answers**: a secret saved through a plain settings write, or echoed back in an S3 error. | Secret fields are dropped by `TargetConfig::sanitize` from any settings write and stored only by `Secrets`. Error messages pass through the `Redactor` before they reach the log or the build row. `target show` prints "(hidden)", and secrets are refused as CLI arguments. | `TargetConfigTest`, `S3Test`, `CliTest` (system) |

## Residual risks

- A user with `stillpress_manage` can choose where output goes within the rules above. That's an intentional power.
  On multisite, path settings are reserved for super admins. The same holds for a custom S3 endpoint: on a single site,
  a `stillpress_manage` user can point HTTPS requests at any host, including an internal address. Those requests only ever
  carry the site's own files and the credentials that user entered.
- Anyone who can reach the site can send requests with a forged render header. They gain nothing but a cheaper
  rejection path; the token check runs before any recording.
- Content rendered by third-party plugins may embed private data in public pages. We can't detect that in general;
  the static site shows whatever an anonymous visitor would see.
