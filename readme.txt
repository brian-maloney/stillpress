=== Stillpress ===
Tags: static site, static html, performance, security, export
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish your WordPress site as fast, secure static files. Every feature is free.

== Description ==

Stillpress turns your WordPress site into plain HTML, CSS and images that any web host can serve, without PHP or a database. You keep writing in WordPress; visitors get a static copy that is fast, cheap to host and hard to break.

* **Works with your theme and plugins.** Pages are rendered by your own site, exactly as a visitor sees them, so block themes, classic themes and page builders all work.
* **Only what changed is rebuilt.** While a page renders, Stillpress records which posts, terms, authors and settings it used. Edit a post, and only the pages that show it are rebuilt and redeployed.
* **One choice to get started.** Activate, pick where the static site goes, and publish.
* **Publishes where you host.** A folder on your server, a ZIP file, Amazon S3 or any S3-compatible storage (with CloudFront invalidation), another server over SFTP, a Git repository for GitHub Pages, GitLab Pages and similar hosts, Netlify, or Cloudflare. Each target is configured on the settings screen or from WP-CLI, and credentials are stored encrypted and never shown again.
* **Publishes when you want.** Right after you save, at set times of day, or only when you click Publish. A full rebuild runs nightly (or weekly, or never) at a time you choose.
* **Search keeps working.** Your search forms search the static copy in the visitor's browser, using an index published with the site. No server, no outside service.
* **Feels like WordPress.** A "Publish to static" button in the editor, a status in the admin bar, a Site Health check, and a WP-CLI command for everything.
* **No upsells.** There is no premium version, no license key and no tracking. The plugin contacts no outside service except the deploy target you choose.

Links, images, srcset, stylesheets, scripts, structured data, feeds and sitemaps are all rewritten to your public address. A 404 page, a `_redirects` file and a `_headers` file (so feeds keep their type on Netlify and Cloudflare) are generated for you.

= For developers =

Every stage has actions and filters (see `docs/HOOKS.md`), and deploy targets are pluggable through a small interface. All features are available from WP-CLI: `wp stillpress build`, `status`, `why`, `deploy`, `zip`, `settings`, `target`, `secret`, `schedule`, `doctor` and more.

== Installation ==

1. Install and activate the plugin.
2. Go to **Static Site** in the admin menu.
3. Choose where the static site should go and click **Publish my site**.

Your site needs pretty permalinks (anything but "Plain" under Settings → Permalinks).

If your server can't reach its own address (common behind proxies and in containers), set `define( 'STILLPRESS_CONNECT_TO', '127.0.0.1:80' );` in wp-config.php to an address that reaches the web server.

== Frequently Asked Questions ==

= Do I need to pay for anything? =

No. Every feature is free and stays free.

= Does the plugin send data anywhere? =

No. It makes requests to your own site, to render pages, and to the deploy target you configure (for example your S3 bucket or Netlify site), to publish. Nothing else, and nothing until you set a target up.

= How are deploy credentials stored? =

Encrypted in the database, with a key derived from the security keys in wp-config.php, and never displayed again. You can also set any of them with a constant or an environment variable (for example `STILLPRESS_S3_SECRET_ACCESS_KEY`) so they are never stored at all. If the security keys change, saved credentials can no longer be read; Site Health says which ones to enter again.

= Can it replace files that aren't its own? =

No. On a folder, SFTP server or S3 bucket it only deletes files it published there itself, and a folder must be empty or marked as its own first. A Git branch or Cloudflare Worker that already has content from elsewhere is refused unless you allow replacing it.

= Does search work on the static site? =

Yes. Stillpress publishes a search index with your site, and your theme's search forms open a results window that searches it in the browser. Results include every published page (title and text from the page itself), and the index is updated along with the pages that change. You can turn it off under Settings → Search. Themes can choose what is searchable by marking elements with `data-stillpress-search`, or leave parts out with `data-stillpress-search-ignore`.

= What about forms and comments? =

They need a server, so on the static copy they don't work yet. A form receiver is planned.

= Which pages are left out? =

Drafts, private posts and password-protected posts are never published. You can leave out more addresses in Settings.

== Changelog ==

= 0.3.0 (in development) =
* Static search: an index of every published page is built with the site and searched in the browser. Search forms and `?s=` links open a results window; only the index files a query needs are downloaded, and only the parts of the index that changed are redeployed.

= 0.2.0 (in development) =
* Remote deploy targets: Amazon S3 and S3-compatible storage with CloudFront invalidation, SFTP, Git over HTTPS (GitHub Pages, GitLab Pages and others), Netlify, and Cloudflare (Workers static assets). Large deploys resume across background runs.
* Encrypted storage for deploy credentials, with constant and environment-variable overrides, and settings panels for each target, including a connection test.
* Publish at set times, right after changes, or only by hand; full rebuilds at a chosen time and weekday, kept on the local clock across daylight-saving changes.
* Feeds are saved as feed/index.html so /feed/ works on static hosts, with their real type sent wherever the host allows.
* Fixes: redirects that started answering 404 left the _redirects file; deploy errors from WP-CLI showed a "critical error" page instead of the reason.

= 0.1.0 =
* First release: loopback renderer, URL rewriting, folder and ZIP deploys, dependency-tracked incremental builds, editor and admin bar integration, Site Health, WP-CLI.

== Privacy ==

The plugin doesn't collect, store or transmit personal data. It contacts no outside service on its own: the only remote
requests go to deploy targets you configure (Amazon S3 or another S3-compatible service, CloudFront, an SFTP
server, a Git repository, Netlify, or Cloudflare), and they carry your built site and the credentials you entered for that target.

== Third-party libraries ==

* Action Scheduler by Automattic (GPL-3.0-or-later), used to run builds in the background.
* phpseclib (MIT), with paragonie/constant_time_encoding and paragonie/random_compat (MIT), used for SFTP deploys.
