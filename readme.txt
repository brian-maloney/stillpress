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
* **One choice to get started.** Activate, pick where the static site goes (a folder on your server or a ZIP file), and publish.
* **Feels like WordPress.** A "Publish to static" button in the editor, a status in the admin bar, a Site Health check, and a WP-CLI command for everything.
* **No upsells.** There is no premium version, no license key and no tracking. The plugin never contacts any outside service.

Links, images, srcset, stylesheets, scripts, structured data, feeds and sitemaps are all rewritten to your public address. A 404 page and a `_redirects` file are generated for you.

= For developers =

Every stage has actions and filters (see `docs/HOOKS.md`), and deploy targets are pluggable through a small interface. All features are available from WP-CLI: `wp stillpress build`, `status`, `why`, `deploy`, `zip`, `settings`, `doctor` and more.

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

No. It only makes requests to your own site, to render pages. Remote deploy targets, when added, contact only the host you configure.

= What about forms, comments and search? =

They need a server, so on the static copy they don't work yet. Static search and a form receiver are planned.

= Which pages are left out? =

Drafts, private posts and password-protected posts are never published. You can leave out more addresses in Settings.

== Changelog ==

= 0.1.0 =
* First release: loopback renderer, URL rewriting, folder and ZIP deploys, dependency-tracked incremental builds, editor and admin bar integration, Site Health, WP-CLI.

== Privacy ==

The plugin doesn't collect, store or transmit personal data, and makes no requests to third-party services.

== Third-party libraries ==

* Action Scheduler by Automattic (GPL-3.0-or-later), used to run builds in the background.
