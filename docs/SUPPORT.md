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
