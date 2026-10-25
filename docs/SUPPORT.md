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

