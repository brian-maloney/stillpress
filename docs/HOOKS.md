# Actions and filters

Every extension point the plugin offers. This file is generated from the source docblocks by
`php bin/hooks.php > docs/HOOKS.md`; a unit test fails when a hook is missing its docblock.

Deployers are registered with `stillpress_register_deployers`; see `src/Deploy/DeployerInterface.php` for the contract.

## Actions you fire

### `stillpress_record_dependency`

Call `do_action( 'stillpress_record_dependency', $type, $key )` while a page renders to say it depends on something the
recorder can't see, for example data your plugin reads with direct SQL. Types: `post`, `term`, `user` (IDs), `option`
(name), `tq` (taxonomy name). It does nothing outside a static render.

## Actions

### `stillpress_before_loopback_batch`

Fires before a batch of loopback render requests is sent.

- `array<int|string,array<string,mixed>> $requests` Requests keyed by caller ID.

Since 0.1.0. Fired in `src/Render/LoopbackClient.php`.

### `stillpress_build_finished`

Fires when a build stops: done, failed or cancelled.

- `array<string,mixed> $build` Build row, including status and stats.

Since 0.1.0. Fired in `src/Build/Runner.php`.

### `stillpress_build_started`

Fires when a build has been created, before any work runs.

- `int $id` Build ID.
- `string $kind` full, incremental or urls.
- `string $trigger` manual, cli, schedule or content.

Since 0.1.0. Fired in `src/Build/BuildService.php`.

### `stillpress_changes_recorded`

Fires after content changes were recorded for the next incremental build.

- `int $written` Number of change rows written.

Since 0.1.0. Fired in `src/Deps/ChangeListener.php`.

### `stillpress_form_submitted`

Fires after a form submission from the static site was accepted.

- `\Stillpress\Forms\Submission $submission` The submission: form key, fields, page, visitor.
- `\Stillpress\Forms\Result $result` What the visitor is told.

Since 0.4.0. Fired in `src/Forms/Receiver.php`.

### `stillpress_register_deployers`

Fires once so extensions can register deployers.

- `Registry $registry` The registry; call register() on it.

Since 0.1.0. Fired in `src/Deploy/Registry.php`.

## Filters

### `stillpress_affected_url_ids`

Filters the URL IDs an incremental build will render.

- `int[] $ids` URL IDs.
- `array<int,array<string,mixed>> $changes` Changes being built.

Since 0.1.0. Fired in `src/Deps/Invalidator.php`.

### `stillpress_asset_roots`

Filters the URL path prefixes whose files may be copied straight from disk.

- `array<string,string> $roots` URL path prefix => absolute directory.

Since 0.1.0. Fired in `src/Crawl/AssetCopier.php`.

### `stillpress_clean_head`

Filters whether render requests drop head links that only work on a live WordPress site.

- `bool $clean` Default true.

Since 0.1.0. Fired in `src/Render/Recorder.php`.

### `stillpress_connect_to`

Filters where loopback render requests connect ("host" or "host:port"; empty for the URL's own host).

- `string $connect_to` Pin from the settings or the STILLPRESS_CONNECT_TO constant.

Since 0.1.0. Fired in `src/Build/Pipeline.php`.

### `stillpress_data_dir`

Filters the base data directory.

- `string $dir` Absolute path.

Since 0.1.0. Fired in `src/Support/Paths.php`.

### `stillpress_dependencies_for_url`

Filters the dependencies recorded for a URL before they are stored.

- `array<string,string[]> $deps` Keys by type (post, term, user, option, query, tq, uq, cq).
- `int $url_id` URL ID.

Since 0.1.0. Fired in `src/Render/Recorder.php`.

### `stillpress_exclude_patterns`

Filters the URL patterns that are never published.

- `string[] $patterns` Glob patterns ("*" wildcard) or regexes prefixed with "~", matched against the URL path.

Since 0.1.0. Fired in `src/Crawl/UrlClassifier.php`.

### `stillpress_exclude_render`

Filters whether the page rendered in this request is left out of the static site.

Return a short reason (letters, digits and dashes) to exclude it, or an empty string to publish it.
Runs inside the render request, so conditional tags such as is_singular() work.

- `string $reason` Default "password-protected" for posts behind a password, otherwise "".

Since 0.1.0. Fired in `src/Render/Recorder.php`.

### `stillpress_form_adapters`

Filters the form adapters. An adapter recognizes a kind of form in published pages and processes its
submissions; see `src/Forms/Adapter/AdapterInterface.php`. The first adapter that recognizes a form wins.

- `\Stillpress\Forms\Adapter\AdapterInterface[] $adapters` Adapters for Contact Form 7, WPForms, Gravity

Since 0.4.0. Fired in `src/Forms/Adapters.php`.

### `stillpress_form_client_ip`

Filters the IP address of a visitor who sends a form, used for the rate limit, the captcha check and the
form plugins. By default it is REMOTE_ADDR; behind a proxy or CDN, return the address the proxy reports,
but only for requests that really came through it (forwarding headers are easy to forge).

- `string $ip` REMOTE_ADDR.

Since 0.4.0. Fired in `src/Forms/Endpoint.php`.

### `stillpress_form_rate_limit`

Filters how many form submissions one IP address may send, and in how many seconds.

- `array{0:int,1:int} $limit` Submissions and window; 5 in 600 seconds by default. Zero submissions turns
- `string $ip` Visitor IP address.

Since 0.4.0. Fired in `src/Forms/Receiver.php`.

### `stillpress_form_settings`

Filters the settings of the forms client: captcha provider and site key, and labels.

- `array<string,mixed> $settings` Settings printed for assets/js/forms.js.

Since 0.4.0. Fired in `src/Forms/Frontend.php`.

### `stillpress_global_options`

Filters option names whose change triggers a full rebuild.

- `string[] $list` Exact option names.

Since 0.1.0. Fired in `src/Deps/OptionRules.php`.

### `stillpress_header_rules`

Filters the rules written to the `_headers` file, which Netlify and Cloudflare read.

- `array<int,array{0:string,1:array<string,string>}> $rules` URL path and the headers to send for it.

Since 0.2.0. Fired in `src/Build/Generated.php`.

### `stillpress_ignored_options`

Filters option names that are never recorded as dependencies and never trigger rebuilds.

- `string[] $list` Exact option names.

Since 0.1.0. Fired in `src/Deps/OptionRules.php`.

### `stillpress_ignored_post_meta`

Filters post meta keys whose changes never affect the public site.

- `string[] $keys` Meta keys.

Since 0.1.0. Fired in `src/Deps/ChangeListener.php`.

### `stillpress_kick_builds`

Filters whether builds are started with an immediate loopback request.

- `bool $kick` Default true. Return false to rely on Action Scheduler and WP-Cron only.

Since 0.1.0. Fired in `src/Build/Queue.php`.

### `stillpress_manifest`

Filters the manifest (relative path => SHA-256) before it is deployed.

- `array<string,string> $manifest` Files that make up the site.
- `string $target` Deployer ID.

Since 0.1.0. Fired in `src/Build/Runner.php`.

### `stillpress_mute_changes`

Filters whether content changes in the current request are ignored.

- `bool $muted` True during render requests.

Since 0.1.0. Fired in `src/Deps/ChangeListener.php`.

### `stillpress_output`

Filters a document after URL rewriting and before it is hashed and written.

Use this to strip per-request values (nonces, timestamps) that would otherwise make every build look
like a change.

- `string $body` Document.
- `string $mime` Content type.
- `string $url` Origin URL.

Since 0.1.0. Fired in `src/Build/Processor.php`.

### `stillpress_output_path`

Filters the output path of a URL, relative to the output root.

The result is validated again, so it can't escape the output directory.

- `string $relative` Relative path such as "about/index.html".
- `string $url` Origin URL.
- `string $content_type` Response Content-Type.

Since 0.1.0. Fired in `src/Build/Processor.php`.

### `stillpress_redirect_rules`

Filters redirect rules written to the `_redirects` file.

- `array<int,array{0:string,1:string,2:int}> $rules` From path, target, status code.

Since 0.1.0. Fired in `src/Build/Generated.php`.

### `stillpress_search_document`

Filters what the static search index stores for a page. Return null to leave the page out of search.

- `array{title:string,text:string,excerpt:string}|null $document` Title, searchable text and excerpt.
- `string $url` Origin URL of the page.

Since 0.3.0. Fired in `src/Search/PageIndexer.php`.

### `stillpress_search_settings`

Filters the settings of the static search client: backend ("builtin" or "pagefind"), index address, public
root path, stylesheet address, result limit and labels. Return an empty "style" to style the dialog in the
theme instead.

- `array<string,mixed> $settings` Settings printed for assets/js/search.js.

Since 0.3.0. Fired in `src/Search/Frontend.php`.

### `stillpress_settings`

Filters the effective settings.

- `array<string,mixed> $settings` Settings after defaults and wp-config.php constants are applied.

Since 0.1.0. Fired in `src/Support/Settings.php`.

### `stillpress_slice_budget`

Filters the time budget, in seconds, of one background build slice.

- `float $budget` Seconds. Defaults to 20, or a third of max_execution_time when that is lower.

Since 0.1.0. Fired in `src/Build/Queue.php`.

### `stillpress_term_change_moves_posts`

Filters whether a change to a term's address also changes post addresses (forcing a full build).

Custom post types whose rewrite rules contain a taxonomy can return true here.

- `bool $moves` Default: the permalink structure contains the taxonomy tag.
- `string $taxonomy` Taxonomy.
- `int $term_id` Term ID.

Since 0.1.0. Fired in `src/Deps/ChangeListener.php`.

### `stillpress_watched_user_meta`

Filters user meta keys whose changes rebuild that user's pages.

- `string[] $keys` Meta keys.

Since 0.1.0. Fired in `src/Deps/ChangeListener.php`.

