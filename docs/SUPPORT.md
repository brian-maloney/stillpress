# Support matrix

- **PHP 7.4 – 8.5.** 7.4 is WordPress 7.x's own floor and above Action Scheduler's 7.2. Choosing 8.1 would lose the share of sites still on
  7.4/8.0 for little gain.
- **WordPress 6.6 – 7.1** (+ trunk as allowed-to-fail). 6.6 moved the editor SlotFills into `@wordpress/editor`, which lets one editor
  integration cover the post and site editors. It also has a mature `WP_HTML_Tag_Processor` (6.2+) and `pre_option` (6.1+). That's about
  two years of releases.
- **DB:** MySQL 5.7/8.0, MariaDB 10.6+. **Multisite:** supported (subdomain + subdirectory).
- **CI matrix (not a full cartesian product):** (7.4, 6.6), (7.4, 7.1), (8.1, 6.8), (8.3, 7.1), (8.4, 7.1), (8.5, 7.1), (8.5, trunk), plus one multisite
  job and one MariaDB job. Playwright visual diffs run on (8.3, 7.1).
- **Visual-diff themes:** Twenty Twenty-Five (block); **Astra** (classic; the first production sites use it, so it gets the most fixture content);
  Hello Elementor + Elementor (page builder). All three are GPL. Twenty Twenty-One is a cheap fourth job if a vanilla classic baseline proves useful.


## Deploy targets

Every remote target talks HTTPS (or SSH for SFTP) from PHP, with no extra extensions beyond what WordPress already
needs: curl or sockets for HTTP, OpenSSL, zlib for Git, sodium (or WordPress's sodium_compat) for stored credentials.
"Tested live" means a real deploy, change, delete and refusal against the service during M2 (October 2026); the rest is
covered by local servers and in-memory APIs in the test suite.

| Target | Needs | Limits and behaviour | Tested live |
|---|---|---|---|
| Folder on this server | A writable folder outside WordPress's own directories | Empty or marked with `.stillpress-target`; only its own files are deleted | system tests |
| ZIP | `zip` extension | Downloaded through an authenticated admin link | system tests |
| Amazon S3 / S3-compatible | Bucket, region, access key (`s3:ListBucket`, `s3:PutObject`, `s3:DeleteObject`, `s3:AbortMultipartUpload`) | Files over 16 MB upload in parts; custom endpoints are HTTPS only (path-style optional); no instance-role or SSO credential providers (use constants or environment variables, with a session token for temporary keys) | AWS S3 (us-west-2) |
| CloudFront | Distribution ID; `cloudfront:CreateInvalidation`, `cloudfront:ListInvalidations` | Changed paths invalidated after each deploy; more than 300 become `/*` | yes |
| SFTP | SSH host, user, key or password, the host key's SHA-256 fingerprint | Pure PHP (phpseclib 3.0), no `ssh2` extension; atomic replace needs OpenSSH's `posix-rename`; never the server's root folder; about two round trips per new file | Amazon Linux 2023 (OpenSSH) |
| Git | HTTPS repository URL and a token that may push (GitHub fine-grained: Contents read and write) | Smart HTTP only (no SSH remotes), SHA-1 repositories only; owns one branch; files up to 100 MB; works with any smart-HTTP server (GitLab, Gitea, Codeberg are untested) | GitHub |
| Netlify | Site ID, personal access token | File-digest deploys; 3 API deploys a minute and 100 a day (unchanged sites use none); paths are served in lowercase; names with `#` or `?` are skipped | yes |
| Cloudflare | Account ID, API token with Workers Scripts: Edit | Publishes an assets-only Worker (not a Pages project; Pages can build from the Git target); 25 MiB per file; Cloudflare's file count per version depends on the plan; `_headers` read up to 100 rules; add a route or custom domain yourself | yes |

Folder URLs are saved as `index.html` (D19). Feeds and other non-HTML folder responses keep their real type on S3,
Cloudflare and Netlify; GitHub Pages and plain web servers choose by extension and serve them as `text/html`.

## Search

Search on the static site runs in the visitor's browser (D20, D21). The `search` setting chooses where the index
comes from; all modes use the same dialog, opened by any form with a field named `s` and by `?s=` addresses.

| Mode | Needs | Limits and behaviour | Tested |
|---|---|---|---|
| Built in (`builtin`, default) | Nothing beyond Stillpress | Indexes published HTML only; up to 500 distinct terms per page (the highest-scoring); every word must match, the word being typed matches longer words; no stemming or typo tolerance; Chinese and Japanese are matched by character pairs | system tests, Playwright (Chromium) |
| Pagefind on this server (`pagefind`) | A `pagefind` binary for the server's platform, its absolute path in `STILLPRESS_PAGEFIND_BINARY`, and `proc_open()` enabled | Runs over the whole site at the end of every build (not in slices), limit 300 s; a failed run keeps the published index | stand-in binary in the test suite; Site Health runs `--version` |
| Pagefind by the host (`pagefind_host`) | A build step on the host that runs `npx pagefind --site .` on the deployed files | Stillpress publishes no index; search shows "isn't available" until the host has built `/pagefind/` | Playwright with Pagefind 1.5.2 run as the host would |
| Off | — | Search forms on the static site do nothing | system tests |

Browsers: the client uses `<dialog>`, `replaceChildren()` and Unicode property escapes in regular expressions, so
Chrome and Edge 86+, Firefox 98+ and Safari 15.4+. The Pagefind modes also need WebAssembly and dynamic `import()`, which those versions
have. The end-to-end tests run in Chromium only.

The 404 page and redirect pages carry `data-pagefind-ignore="all"`, and elements marked
`data-stillpress-search-ignore` are left out of both indexes; themes can limit the built-in index to elements marked
`data-stillpress-search`.

## Forms

Forms on the static site post to a receiver (D22), chosen with the `forms` setting. Answers appear in the form (the
client needs `fetch`, `FormData` and `HTMLFormElement.reportValidity()`, which every browser that runs the search client
has); without JavaScript the form posts normally and a short page answers.

| Receiver | Needs | Limits and behaviour | Tested |
|---|---|---|---|
| This site (`site`) | WordPress reachable from the internet at its own address (the static site at another address) | Rate limit in transients (5 per IP per 10 minutes); captcha checked here | integration, system tests, Playwright (Chromium, comments and Contact Form 7, with and without JavaScript) |
| PHP file (`receiver`) | PHP 7.4+ on any host; curl or `allow_url_fopen` to forward; `mail()` to email | Rate limit in files in the temporary folder (or `data_dir`); never deploy it with the static site | unit tests, including requests through `php -S` on PHP 7.4 and 8.5 |
| Cloudflare Worker (`receiver`) | A Worker; optional KV namespace bound as `STILLPRESS_RATE` | Without KV the rate limit counts per instance; forwards or sends to a webhook only (no email) | Node tests with the standard Request/Response API; not deployed live |
| Netlify Function (`receiver`) | Functions in the site's repository (`netlify/functions/`) | Rate limit per instance; forwards or sends to a webhook only | Node tests; not deployed live |

| Form | Adapter | Limits | Tested |
|---|---|---|---|
| Contact Form 7 | `WPCF7_ContactForm::submit()` | No file uploads; CF7's own captcha integrations aren't used (use the receiver's captcha) | Contact Form 7 6.2.4 (`npm run test:integration:forms`, Playwright); CI runs it on PHP 8.3+ with the latest WordPress, which that version requires |
| WPForms (Lite and paid) | `WPForms_Process::process()` | Its render-time and token anti-spam checks are bypassed (the receiver applies its own); no file uploads or payments; page and redirect confirmations send the visitor there | WPForms Lite 2.0.3 (`npm run test:integration:forms`) |
| Gravity Forms | `GFAPI::submit_form()` | Single-page forms only; no file uploads | Only against a stand-in that follows the documented return value: Gravity Forms isn't on WordPress.org |
| Comments | `wp_handle_comment_submission()` | Always anonymous; core's moderation, flood and duplicate checks apply; approved comments appear after the next publish | integration, Playwright |
| Marked forms (`data-stillpress-form`) | Email to `forms_email` | Fields are listed as name: value | integration, system tests |
