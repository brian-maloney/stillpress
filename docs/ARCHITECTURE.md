# Architecture

This document describes how Stillpress turns a WordPress site into static files. Non-obvious
choices and their reasons live in [DECISIONS.md](DECISIONS.md); security reasoning lives in
[THREAT_MODEL.md](THREAT_MODEL.md).

Key limits of the design, stated up front:

- **Dependency tracking is a conservative over-approximation, not an exact map.** Direct `$wpdb` queries,
  transients, external APIs, time-based output and object-cached fragments can escape any recorder. The design
  compensates with a "volatile" class of URLs, a scheduled full rebuild (default daily) and differential tests that
  require incremental output to equal full-rebuild output.
- **Plain permalinks (`?p=123`) cannot be published statically.** Pretty permalinks are required.


## Layers
```
 UI (admin screen, editor, admin bar, Site Health) ─┐
 REST (stillpress/v1)                              ├─> Services (BuildService, PublishService, StatusService, SettingsService)
 WP-CLI (wp stillpress …)                          ─┘           │
                                                                ▼
              Build pipeline:  Plan ─> Discover/Crawl ─> Render ─> Rewrite ─> Store(cache+manifest) ─> Deploy
                                 ▲                         │
          Change capture ─> Invalidator <── Dependency store (recorded during Render)
                                                                │
                                                 Queue (Action Scheduler; WP-Cron fallback)
```
UI, REST and CLI are thin adapters over one service layer, so everything the UI can do, the CLI can do too.

## Renderer (loopback)
- `LoopbackClient` uses the WP HTTP API (`wp_remote_request`; `WpOrg\Requests\Requests::request_multiple` for
  concurrency, default 4, configurable). Requests carry **no cookies**, so pages render as an anonymous visitor.
  `redirection => 0`; redirects get recorded, not followed blindly.
- Each request carries `X-Stillpress-Render: <build_id>.<expiry>.<hmac>`, an HMAC-SHA256 under a per-site random key
  stored in an option with `autoload=no`. The render-side `Recorder` boots only when the token verifies (`hash_equals`)
  and the build is active. The token **grants no privileges**. It only turns on recording, hides the admin bar and
  sets `DONOTCACHEPAGE`.
- At shutdown, the Recorder writes the deps for (build, URL) into the DB and sends back `X-Stillpress-Recorded: <build_id>`.
  If that header is missing, a page cache answered the request instead of WordPress. The URL is then marked `deps=unknown`
  (always rebuilt on any change) and Site Health warns.
- Response checks: status, content-type, size cap (default 20 MB), `logged-in` body class (must not appear), redirect target.
- The recorder can also mark a page as excluded with `X-Stillpress-Exclude` (password-protected posts by default, or
  anything the `stillpress_exclude_render` filter decides); excluded pages are removed like 404s.

## URL rewriting
- `OriginSet` holds every form of the origin: `home_url` and `site_url`, http and https, protocol-relative `//host`,
  JSON-escaped `https:\/\/host`, URL-encoded `https%3A%2F%2Fhost`, entity-encoded variants, and any extra aliases.
  Longest prefix wins, so a WP-in-subdirectory origin (`/wp/`) maps correctly onto a public root.
- **Rewriting is a boundary-aware textual replacement over the whole document**, not a DOM rewrite. That's how it
  covers inline JS, JSON-LD, srcset, CSS `url()`, `@import`, OG/canonical meta, feeds, sitemaps and the sitemap XSL
  in one pass. Boundaries prevent `example.com` → `example.com.evil` or `example.community`.
- Context-specific replacement keeps each escaping style intact (JSON-escaped slashes stay escaped).
- Output modes: absolute public URL (default), or root-relative for HTML. Canonical, OG, feeds and sitemaps always stay absolute.
- Clean-up passes (each filterable): remove EditURI/RSD, wlwmanifest, REST discovery `<link>`, oEmbed discovery,
  shortlink and pingback headers, and strip volatile nonces from known inline settings.

## Discovery / crawl
- Seeds: home; core sitemaps (`wp-sitemap.xml` and any index listed in robots.txt); enumerated content (public posts of
  public post types, term archives, author archives, paginated archives up to a limit); feeds; robots.txt; favicon;
  additional URLs and paths from settings; a 404 rendered from a guaranteed-missing URL and saved as `404.html`.
- Frontier: a deduplicated, normalized URL set held in a DB table. `WP_HTML_Tag_Processor` (core) extracts links from
  href/src/srcset/poster/`link[rel]`/meta; a CSS tokenizer handles `url()` and `@import`. Only same-origin URLs are
  followed. Limits: depth, URL count, and a trap guard (calendar/infinite pagination). Query-string URLs are skipped
  and reported by default.
- Assets under uploads, themes, plugins or wp-includes are **copied from the filesystem**, which is faster than HTTP.
  This only happens after the path is proven inside an allowed root and its extension is on the allowlist (see the
  threat model). Anything else is fetched over HTTP.
- Redirects: origin 301/302s plus user-defined redirects become a `_redirects` file (a de facto standard) and a
  meta-refresh HTML fallback.

## Dependency-tracked incremental builds
**Recorded per URL during render** (stored in table `deps(url_id, type, key)`, indexed on `(type, key)`; this is the inverted map):
| type | captured via | invalidated by |
|---|---|---|
| `post:<id>` | `the_posts`, `the_post`, `get_post` cache hits via `posts_results` | `clean_post_cache`, meta changes, status transitions |
| `term:<id>` | `get_terms`, `get_object_terms`, `wp_get_object_terms` filters | `clean_term_cache`, `edited_term`, term relationships |
| `user:<id>` | post authors, `pre_user_query` results | `clean_user_cache`, `profile_update` |
| `option:<name>` | generic `pre_option` (WP 6.1+) | `updated_option`/`added_option`/`deleted_option` |
| `query:<sig>` | `the_posts` with `WP_Query` vars normalized into a signature (post_type, status, tax/author/date filters, order, limits) | a changed post matching the signature **before or after** the change |

- Nav menus, global styles, templates, template parts and synced patterns are posts or terms, so the table covers them.
- Posts are captured mainly through `posts_pre_query`, which fires even with `suppress_filters` (so `get_posts()` and
  menus are seen): the recorder keeps each `WP_Query` and reads its results and query vars at shutdown.
- Hierarchical changes: before a page or term changes, its descendants are snapshotted too, because their addresses
  contain the ancestor's slug. If the permalink structure embeds `%category%` (or another taxonomy) or `%author%`, renaming
  that term or author is a full change.
  Widgets and theme mods are options.
- Some options change constantly (cron, transients, `doing_cron`, Action Scheduler state, our own). They're denylisted
  as triggers (filterable). `rewrite_rules`, `permalink_structure` and the active theme trigger a full rebuild.
- Change capture writes to a `changes` table with a debounce (default 60 s), so a burst of saves becomes one incremental build.
- Affected set = URLs from the object deps ∪ matching query signatures ∪ option deps ∪ the changed post's old and new permalinks
  ∪ `deps=unknown` URLs ∪ URLs marked volatile (`stillpress_url_is_volatile` filter).
- **Orphans**: URLs that now return 404 or redirect get removed from the manifest, so deployers delete them.
- **Safety nets**: a "Full rebuild" button and `--full` flag, a scheduled full rebuild (setting: off/daily/weekly, **default daily**), and a
  `stillpress_dependencies_for_url` filter for extensions that know their own deps.
- **Output hashing**: `manifest(path, sha256, size, url_id, build_id)`. A build produces a changeset (added, modified,
  deleted), and deployers receive that changeset.

## Storage
Custom tables per site (`$wpdb->prefix`), managed with `dbDelta` and a schema version option: `builds`, `urls`, `deps`,
`query_sigs`, `changes`, `manifest`, `log`. The build cache (rendered files) defaults to `wp-content/stillpress/cache/<site_id>/`
(public content only; protected with index.php and .htaccess deny). Uninstall drops tables and options. It never deletes a deploy target.

## Queue
- Action Scheduler, bundled through Composer. It's GPL-3.0-or-later, and it resolves duplicate copies across plugins
  by version on its own. The combined distribution is GPLv3-compatible; our own code stays GPL-2.0-or-later. This goes
  in DECISIONS. `QueueInterface` keeps a WP-Cron fallback possible.
- A build is a state machine (`queued → discovering → rendering → finalizing → deploying → done|failed|cancelled`).
  Each AS action handles a batch within a time budget (default 20 s, kept under `max_execution_time`) and then
  schedules the next one. Batches can resume after a crash, and a lock prevents two builds running at once.
- WP-CLI can run the same pipeline synchronously in-process (`--sync`).
- Starting a build from the admin or REST also sends a signed, non-blocking request to `admin-ajax.php`
  (`stillpress_run`) that runs the first slice right away and chains the next, so a build starts within a second rather
  than at the next cron tick. Action Scheduler remains the fallback; `run_slice()` reports done, more or busy, and a busy
  slice reschedules itself 30 seconds later.

## Deployers
`DeployerInterface { id(); label(); settings_schema(); validate(config); deploy(Changeset, Context): Result; }`, registered through
`stillpress_register_deployers`. Built in: **Local directory**, **ZIP**, **Amazon S3** (with optional CloudFront invalidation) and **SFTP**.
- Local directory applies the changeset with an atomic write per file (temp file + rename). It only deletes files listed in its own
  manifest, and requires a marker file `.stillpress-target` in the target directory.
- ZIP is built from the cache and downloaded through an authenticated, nonce-checked admin endpoint (streamed). There's no public URL for it.
- **Target settings.** A deployer that implements `ConfigurableDeployerInterface` declares `fields()` (text, secret, bool,
  select; required, pattern, default, `restricted`). `TargetConfig` resolves each field from a `STILLPRESS_<TARGET>_<FIELD>`
  constant, then the environment, then the `targets` entry of the settings (secret fields: `Secrets`), then the default.
  Every write goes through the field's validation, including direct `Settings::update()` calls. `restricted` fields (hosts,
  endpoints) follow the filesystem-path rule: super admins only on multisite. `check_connection()` makes one cheap live
  request. CLI: `wp stillpress target list|show|set|check`.
- **Resumable deploys.** A deployer that implements `ResumableDeployerInterface` gets the slice deadline and may return with
  `done = false`; the runner keeps the build in `deploying`, adds up the counts in `deploy_progress`, and calls it again in the
  next slice. Progress lives in the deployer's own `target_files` records, so nothing is sent twice.
- **S3** mirrors the build into a bucket under an optional key prefix, through a minimal hand-written client (`Deploy\Aws`)
  signed with SigV4 (D13). Changed files go up with PutObject a few at a time over `Requests::request_multiple` (redirects off,
  WordPress's CA bundle); files over 16 MB go up as multipart uploads whose upload ID and part ETags are saved after each part,
  so a slice never runs past its deadline. Deletes use DeleteObjects and only ever name keys this deployer uploaded. A 3xx/4xx
  answer stops the deploy with S3's error code; 5xx and network errors mark the file failed for the next deploy to retry. With
  a distribution ID, the public paths of changed and deleted files (plus the folder of each `index.html`) are invalidated once
  the deploy finishes; more than 300 paths become a single `/*`. Custom endpoints (R2, MinIO, B2) are HTTPS only, and buckets
  with dots use path-style addressing because their virtual-host names don't match S3's certificate.
- **SFTP** (phpseclib 3, D14) mirrors the build into a remote folder over one SSH connection per slice. The host key is pinned
  by its SHA-256 fingerprint and compared with `hash_equals` before login; there is no trust on first use, and
  `target check` shows the presented fingerprint while none is set. Login takes a private key (OpenSSH or PEM, optional
  passphrase) or a password. The remote folder follows the local folder's rules: never the server's root, empty or marked
  with `.stillpress-target`, and only recorded files are deleted (never recursively). Each file is uploaded under a temporary
  name and moved into place with OpenSSH's `posix-rename` (atomic), or delete-then-rename where the server lacks it. Folders
  emptied by deletes are removed. A dropped connection stops the deploy; the next one resumes from the records.

## Secrets
`Security\Secrets` resolves `(deployer, field)` in this order: `STILLPRESS_<DEPLOYER>_<FIELD>` constant → environment variable of
the same name → the `stillpress_secrets` option (autoload off), sealed by `Security\SecretBox` with sodium `secretbox`. Each slot's
key is HKDF-SHA256 over `wp_salt('auth') . wp_salt('secure_auth')` with the site ID, deployer and field as context, so a
ciphertext copied into another slot or site doesn't open. If the salts are rotated, stored secrets become unreadable;
`source()` reports `unreadable` and Site Health names the fields to re-enter. Secret fields are write-only in forms and never
appear in REST/CLI output, logs or exports. Every value `Secrets` returns is registered with the `Redactor`, which scrubs it
from every log line. `wp stillpress secret list|set|delete` manages stored values; `set` reads from standard input or a hidden prompt.

## WordPress-native UX
- A top-level menu **Static Site**, with a *Publish* page (status, Publish / Full rebuild, history, log, changeset preview) and a
  *Settings* page (Settings API; `option_page_capability_stillpress` lets `stillpress_manage` save it, not only `manage_options`).
  Every configurable deploy target gets a panel generated from its `fields()`; a script shows only the selected destination's
  panel (without JavaScript all panels show and the form still works). Fields merge one by one over the stored values, so
  disabled inputs and other targets keep theirs; an invalid value keeps the old one and adds a notice. Secret boxes are
  always empty: an empty box keeps the saved secret, "Remove the saved value" deletes it, and submitted secrets go straight
  to `Secrets` (`stillpress_settings[secrets]` never reaches the option). Fields pinned by a constant or environment variable,
  and restricted fields for users who can't edit server paths, render disabled with a note. *Test connection* calls
  `POST stillpress/v1/targets/<id>/check` (needs `stillpress_manage`), which runs `check_connection()` on the saved settings.
- Admin bar node: static status (up to date / N pending / building x%), refreshed through the Heartbeat API.
- Editor integration: a pinned `PluginSidebar` header icon (beside Publish), a `PluginPostStatusInfo` row with status and a "Publish to static" button, and a `PluginPostPublishPanel`. Classic editor: `post_submitbox_misc_actions`. (The block editor has no SlotFill directly next to the Publish button.) REST through `apiFetch` with the `wp_rest` nonce.
- Site Health tests: loopback reachable, recorder header present (page-cache bypass), pretty permalinks, cache and target writable,
  queue running, `zip`/`curl` extensions, origin/public URL sanity.
- Capabilities: primitive caps `stillpress_manage` (settings, targets) and `stillpress_publish` (build/deploy). They're added to
  administrator (both) and editor (publish) on activation, and removed on uninstall. Meta cap `stillpress_publish_post` is mapped in
  `map_meta_cap` to `stillpress_publish` + `edit_post`. In multisite, filesystem paths are set **only by a super admin or a constant**.
- Activation defaults: target = Local directory at `wp-content/stillpress/public/<site>/`, public URL = current home URL. The one
  onboarding choice: *where to deploy*.
- WP-CLI: `wp stillpress build [--full] [--url=…] [--sync] [--no-deploy]`, `status`, `cancel`, `deploy [--target]`, `zip [--out]`,
  `urls list|add|remove`, `why <url>` (shows recorded deps), `changes list|flush`, `settings get|set|list`, `secret list|set|delete`, `doctor` (Site Health checks), `clean`.
- Extension API: every pipeline stage has actions/filters (`stillpress_seed_urls`, `stillpress_pre_render`, `stillpress_rendered_response`,
  `stillpress_rewrite_rules`, `stillpress_output_path`, `stillpress_changeset`, `stillpress_register_deployers`,
  `stillpress_build_completed`, …). They're documented in `docs/HOOKS.md`, and a test fails if any `do_action`/`apply_filters` lacks a docblock.

