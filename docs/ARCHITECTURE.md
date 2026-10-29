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
- **Safety nets**: a "Full rebuild" button and `--full` flag, a scheduled full rebuild (setting: off/daily/weekly at a chosen local time, **default daily at 03:00**), and a
  `stillpress_dependencies_for_url` filter for extensions that know their own deps.
- **Output hashing**: `manifest(path, sha256, size, url_id, build_id)`. A build produces a changeset (added, modified,
  deleted), and deployers receive that changeset.

## Storage
Custom tables per site (`$wpdb->prefix`), managed with `dbDelta` and a schema version option: `builds`, `urls`, `deps`,
`query_sigs`, `changes`, `manifest`, `log`, and for search `search_terms`, `search_docs`, `search_dirty`. The build cache (rendered files) defaults to `wp-content/stillpress/cache/<site_id>/`
(public content only; protected with index.php and .htaccess deny). Uninstall drops tables and options. It never deletes a deploy target.

**URL to file.** A URL ending in `/` (or without an extension) is saved as `<path>/index.html`, whatever its response
type, because static hosts map a folder request only to `index.html`; `/feed/` becomes `feed/index.html`. The real type
is recorded with the URL: S3 and Cloudflare upload such files with it, and `Generated` writes it into `_headers` (one rule
per path, at most 100, for Netlify and Cloudflare). Hosts that choose types by extension alone (GitHub Pages, a plain web
server) serve these files as `text/html`, which feed readers handle (D19).

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
- **When changes go out** (`publish_mode`): `immediate` debounces content changes by a minute and starts an
  incremental build; `scheduled` leaves them pending until the next of `publish_times` on `publish_days`, when
  `stillpress_publish_window` builds only if something is pending; `manual` waits for the Publish button or the CLI.
- **Wall-clock schedules** (D18): the full rebuild and the publishing times are single Action Scheduler actions, each
  computed by `Build\Schedule::next()` in the site's timezone (`wp_timezone()`) and re-armed by the run itself, so they
  stay on the local clock across daylight-saving changes. `ensure_recurring()` (every `action_scheduler_init`) re-arms a
  missing run and reschedules when the settings or timezone change. `wp stillpress schedule` and the Publish page show the
  next runs.

## Deployers
`DeployerInterface { id(); label(); settings_schema(); validate(config); deploy(Changeset, Context): Result; }`, registered through
`stillpress_register_deployers`. Built in: **Local directory**, **ZIP**, **Amazon S3** (with optional CloudFront invalidation), **SFTP**, **Git**, **Netlify** and **Cloudflare** (Workers static assets).
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
  with `.stillpress-target`, and only recorded files are deleted (never recursively). Every SFTP request is a network round
  trip, so the deployer keeps them few: a deploy with no changes doesn't connect; the marker is checked with one request
  (the folder is listed only on a first deploy); folders that held files last time are assumed to exist (recreated if
  someone removed them), and new ones take one `MKDIR`. A file that replaces a published version is uploaded under a
  temporary name and moved into place with OpenSSH's `posix-rename` (atomic), or delete-then-rename where the server
  lacks it; a new file has no published version and is written in place. Deletes are one `REMOVE` (it fails on a folder),
  and emptied folders are removed by trying `RMDIR` upward. A dropped connection stops the deploy; the next one resumes
  from the records. `CountingSftp` in the tests pins the request counts.
- **Git** (D15) commits the build to one branch it owns and pushes over Git's smart-HTTP protocol with a token, in pure
  PHP (`Deploy\Git`: object IDs, trees, version-2 packs of whole objects, pkt-lines, receive-pack with report-status).
  Each publish is one commit whose tree is the whole site plus `.stillpress-target` and `.nojekyll`, with the branch head as
  parent, so pushes are fast-forwards and history is kept. A branch with commits from elsewhere is refused unless "Replace
  the branch's contents" is on. Only new and changed blobs are sent, located through `target_files` records of each file's
  SHA-256 and blob ID; when the branch head isn't the commit we last pushed, everything is sent again. Deploys over 16 MB
  push their blobs in batches to `refs/heads/stillpress-upload-<key>` (one commit per batch, so the server keeps them),
  then a final push moves the branch; the upload branch is deleted in a push of its own, so a server that refuses
  (it might be the default branch) only costs a warning. In an empty repository the real branch is created first,
  with a commit holding only the marker files, because hosts such as GitHub make the first branch pushed the default
  and refuse to delete it. The test Git server's hooks copy both of those GitHub rules.
- **Netlify** (D16) uses the API's file-digest deploys: one `async` deploy listing every file's SHA-1, then uploads of only
  the hashes Netlify reports missing. SHA-1s are kept per path beside the SHA-256 records, so unchanged files aren't
  re-hashed. A site with no changes since the last deploy creates no deploy (Netlify allows 100 API deploys a day). The open
  deploy's ID and a fingerprint of its file list are saved, so a deploy interrupted while Netlify prepares it or mid-upload
  resumes in the next slice; an expired or failed one is replaced. Once uploading starts, Netlify's deploy stops listing
  what it still needs, so the hashes left to upload are kept in that saved state, and a deploy only counts once Netlify
  reports it `uploaded`, `processing` or `ready` (one still `uploading` after three slices with nothing left to send is
  replaced). Files with `#` or `?` in their path are skipped, as Netlify requires. Netlify serves paths in lowercase
  and redirects mixed-case requests to them, so mixed-case asset names cost a redirect there.
- **Cloudflare** (D17) publishes an assets-only Worker through the documented direct upload for Workers static assets:
  a manifest of path → {hash, size} (hash: first 32 hex characters of SHA-256 over the base64 contents plus the extension,
  as in Cloudflare's example), multipart uploads of the buckets Cloudflare asks for, then a Worker upload with only a
  metadata part (`assets.jwt`, `html_handling: auto-trailing-slash`, `not_found_handling: 404-page`). A Worker of that name
  that Stillpress didn't create is refused unless replacing is on. Unchanged sites make no calls; the one-hour upload
  session and its remaining buckets are saved so a deploy resumes. Cloudflare Pages projects publish through the Git
  deployer instead (Pages builds from the branch).
- **TLS for remote targets.** `RequestsTransport` verifies certificates against WordPress's CA bundle (or a bundle passed
  in, which only tests do) on every handle. Requests applies its `verify` option to single requests only, so the bundle is
  set through the `curl.before_request` hook as well.

## Search (static)
Built in by default (D20); Pagefind is an optional backend where a binary is supplied or the target builds in CI.
- **Tokenizer** (`Search\Tokenizer`, pure): lowercase, fold accents, split into letter/digit runs, drop single
  characters, index Han/Hiragana/Katakana runs as overlapping pairs. The bundled client implements the same rules, and
  both are checked against `tests/fixtures/search-tokenize.json`.
- **Per-page terms**: when an HTML page is written, its searchable text (main content region, else the body minus
  header, nav, footer, script, style) is tokenized and stored with per-term scores (title hits weigh more) against the
  page's URL row; the highest-scoring terms are kept up to a cap. Removing a page removes its terms.
- **Shards**: terms group by their first two characters; a group over the posting limit splits by one more character
  (`Search\ShardKeys`, pure). Finalizing rewrites only shards whose terms changed, plus `meta.json` (shard keys,
  document count, format version) and the document chunks (`id → path, title, excerpt`, grouped by URL row ID).
  Everything lives under `stillpress-search/` in the output and deploys like any generated file. The writer runs at
  the start of finalizing, a group at a time within the slice budget; a full build marks every group dirty once, which
  restores any file that went missing. The `search` setting (`builtin`, `pagefind`, `pagefind_host` or `off`) decides whether pages are indexed;
  `off` removes the files and empties the tables. Changing it triggers a full rebuild, like any settings change.
- **Client** (`assets/js/search.js`, plain JavaScript, no build step): `Search\Frontend` enqueues it (deferred) only on
  render requests, so the live site keeps WordPress search; its settings (index and stylesheet addresses, labels) are
  printed as origin URLs and rewritten like everything else. It intercepts submits of any form with a field named `s`
  and opens a `<dialog>`; a `?s=` address (where those forms land without JavaScript, since static hosts ignore the
  query) opens it too. A query fetches `meta.json`, then the shards its words fall in (the word being typed also
  matches longer terms, from at most 12 shards) and the document chunks of the top results. Every word must match;
  scores are the stored term scores weighted by inverse document frequency. The stylesheet loads on first open.
- **Pagefind** (D21): `search = pagefind` runs the binary from `STILLPRESS_PAGEFIND_BINARY` (`Search\Pagefind`: argument
  list, no shell, minimal environment, time limit) at finalizing, and `PagefindWriter` copies its bundle into
  `pagefind/` as generated rows, removing files the new bundle lacks. `search = pagefind_host` publishes nothing and
  leaves the bundle to the host's build step. In both, the client loads `pagefind.js` and uses Pagefind's API with
  `baseUrl` set to the public path. The 404 page and redirect pages carry `data-pagefind-ignore="all"` in every mode.
  Site Health (and `wp stillpress doctor`) runs `pagefind --version` to show the binary works.
- **What is indexed**: `TextExtractor` reads the page with core's `WP_HTML_Tag_Processor`; nav, forms, scripts, media,
  hidden and screen-reader-only elements, and `data-stillpress-search-ignore` are skipped. The
  `stillpress_search_document` filter can change or drop (null) what a page contributes.

## Forms (static)
Forms on the static copy post to a **receiver** (D22). The `forms` setting chooses where: `off` (default: forms are
published as they are and don't work), `site` (this WordPress site's REST API receives them, so it must be reachable
from the internet at its own address) or `receiver` (a receiver run elsewhere, at `forms_endpoint`).
- **Adapters** (`Forms\Adapter\*`) recognize a kind of form in rendered HTML and process its submissions in WordPress:
  Contact Form 7 (`cf7:<id>`, through `WPCF7_ContactForm::submit()`), WPForms (`wpforms:<id>`, through
  `WPForms_Process::process()`), Gravity Forms (`gravityforms:<id>`, through `GFAPI::submit_form()`), the comment form
  (`comments`, through `wp_handle_comment_submission()`) and any form marked `data-stillpress-form="<name>"`
  (`form:<name>`, emailed to `forms_email`). `stillpress_form_adapters` adds more. Each runs the plugin with `$_POST`,
  the visitor's IP address and User-Agent set from the submission and no logged-in user (`Adapter\Request`).
- **Rewriting** (`Forms\FormMarkup`) runs in the processor right after URL rewriting, so a receiver on the origin keeps
  its address. A recognized form gets the receiver as `action`, `method="post"`, `data-stillpress-form`, and four fields
  inserted after its start tag: `stillpress_form` (key), `stillpress_page` (public page address), an empty
  `stillpress_t` and the `stillpress_hp` honeypot. Per-render tokens that adapters declare (WPForms' render-time tokens)
  are removed, so the markup is identical from build to build.
- **Client** (`assets/js/forms.js`, enqueued only on render requests while forms are on): captures `submit` on the
  document before form plugins' own handlers, fills in `stillpress_t` (seconds the page was open), sends `FormData`
  with `fetch()` asking for JSON, and shows the answer as text in the form, with a note beside each rejected field.
  With a captcha set up it loads the provider's script on pages that have forms. Without JavaScript the form posts
  normally and the receiver answers with a short HTML page (or a 303 to a plugin's confirmation page).
- **Shared rules** (`Forms\Protocol`): at most 100 fields, 200-byte names, 20,000-byte values, 100,000 bytes in all; a
  valid form key; an empty honeypot; and, when present, `stillpress_t` of at least 3 seconds. The standalone receivers
  repeat these rules, and all implementations are tested against `tests/fixtures/forms-cases.json`.
- **WordPress receiver** (`Forms\Receiver`, routes in `Forms\Endpoint`): `POST stillpress/v1/forms/submit` (site mode)
  checks the `Origin` header against the public site, the shared rules, the captcha (Turnstile or hCaptcha, opt-in)
  and a rate limit (5 per 10 minutes per IP, `stillpress_form_rate_limit`), then calls the adapter. `POST
  stillpress/v1/forms/relay` accepts a submission another receiver forwarded: JSON `{v, form, page, fields: [[name,
  value], …], ip, user_agent}` signed with the forms secret (`X-Stillpress-Signature: t=<time>,v1=<HMAC-SHA256 of
  "<time>.<body>">`, five minutes either way, each signature accepted once). Accepted submissions fire
  `stillpress_form_submitted` and, when `forms_webhook` and the secret are set, are sent on in the same signed format.
  Answers are `{ok, status: sent|invalid|spam|limited|error, message, errors, redirect}`; messages are plain text.
- **Standalone receivers** (`receivers/`, written by `wp stillpress forms receiver`): a single PHP file for any PHP
  host and a module for Cloudflare Workers or Netlify Functions. They apply the shared rules, an optional captcha and
  rate limit, and forward to the relay or any webhook (the PHP one can also email with `mail()`).

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
  `urls list|add|remove`, `why <url>` (shows recorded deps), `changes list|flush`, `settings get|set|list`, `secret list|set|delete`,
  `forms status|receiver`, `doctor` (Site Health checks), `clean`.
- Extension API: every pipeline stage has actions/filters (`stillpress_seed_urls`, `stillpress_pre_render`, `stillpress_rendered_response`,
  `stillpress_rewrite_rules`, `stillpress_output_path`, `stillpress_changeset`, `stillpress_register_deployers`,
  `stillpress_build_completed`, …). They're documented in `docs/HOOKS.md`, and a test fails if any `do_action`/`apply_filters` lacks a docblock.

