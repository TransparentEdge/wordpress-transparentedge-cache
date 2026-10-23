=== Transparent Edge Cache ===
Contributors: transparentedge
Tags: cache, cdn, varnish, performance, wpo, optimization, woocommerce, security
Requires at least: 5.5
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.11.0
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

Native caching, WPO and edge-security plugin for Transparent Edge CDN. Leverages Varnish Enterprise: Surrogate-Keys, Soft Purge, i3 image optimization, Speculation Rules, Remove Unused CSS, and intelligent warm-up.

== Description ==

Transparent Edge Cache is the official WordPress plugin for the [Transparent Edge](https://www.transparentedge.eu) CDN platform.

**CDN Features (Exclusive)**
* Surrogate-Keys: Surgical cache invalidation
* Soft Purge: Zero-downtime invalidation (default for automated purges)
* Tag-based Invalidation with automatic warm-up
* i3 Image Optimization: WebP, quality, resizing at the edge
* Graceful degradation: local optimizations keep working if the CDN becomes unavailable (circuit breaker + health check)

**Frontend Optimization (WPO)**
* CSS and JS Minification with disk cache
* Combine CSS/JS (with graceful fallback if the cache dir is not writable)
* Remove Unused CSS: per-template Used CSS generated asynchronously, served inline, rest deferred
* Defer JS and Delay JS execution — dependency-tree aware (never breaks scripts that rely on Backbone, Underscore, etc.)
* Lazy Load images, iframes and CSS background images
* LCP image preload with fetchpriority and HTML minification
* Self-host Google Fonts (GDPR)
* DNS Prefetch auto-detection
* Speculation Rules: prefetch/prerender coordinated with the CDN

**Security**
* Contracted-service detection (WAF, Bot Mitigation, Perimetrical) to gate features
* Security headers: generates a recommended VCL snippet (CSP defaults to Report-Only); optional PHP fallback
* Local hardening: block PHP execution in uploads, disable XML-RPC, limit login attempts

**Integrations**
* WooCommerce, Multisite, WPML, Polylang, Yoast, Elementor
* Object Cache with Redis, Memcached and APCu (auto-enabled; supports password-protected Redis)
* Apache and Nginx support

== Installation ==

1. Upload the plugin ZIP via Plugins, Add New, Upload Plugin
2. Activate and go to TE Cache
3. Complete the Setup Wizard or enter credentials manually

== Object cache ==

The page cache only protects anonymous visitors. Logged-in users, and every wp-admin / admin-ajax request, bypass it and hit the database directly. A persistent object cache (Redis or Memcached) absorbs that load. On a busy, editor-heavy site it is what keeps the database from being overwhelmed even with page cache on.

**Requirements (two things, and they fail separately):**

1. A running **Redis or Memcached daemon**, reachable from the web server.
2. The matching **PHP extension loaded in the php-fpm SAPI** — `phpredis` for Redis, `php-memcached` for Memcached. Note: the extension must be present in the SAPI WordPress actually runs under (php-fpm), not only in PHP-CLI. Verify with `php-fpm8.x -m | grep -i redis`, or by checking `/etc/php/8.x/fpm/conf.d/` for the extension's `.ini`.

When both are present, the plugin **auto-installs** the object-cache drop-in (TE Cache → Cache → Object cache → Automatic, on by default). It never overwrites an `object-cache.php` from another plugin silently — it flags it and offers a one-click Replace (the foreign drop-in is backed up first).

**Password-protected Redis (secured installs — the common production case):**

If your Redis has `requirepass` set, the daemon is up but the plugin cannot use it until you give it the password in `wp-config.php`. Without it the panel reports *"Redis detected but requires a password"* (and the backend stays unused — it does not silently fall back to a non-persistent cache). Add:

    define( 'WP_REDIS_HOST', '127.0.0.1' );   // default 127.0.0.1
    define( 'WP_REDIS_PORT', 6379 );          // default 6379
    define( 'WP_REDIS_PASSWORD', 'your-password' );
    define( 'WP_REDIS_DATABASE', 0 );         // optional, default 0

The object cache reuses these standard `WP_REDIS_*` constants. After adding them, restart php-fpm and reload the TE Cache panel; the Object cache card should turn green with a hit ratio.

**Redis over TLS (encrypted in transit):**

For a managed or remote Redis that requires TLS (e.g. AWS ElastiCache with in-transit encryption), enable it with either constant:

    define( 'WP_REDIS_SCHEME', 'tls' );   // or 'rediss'
    // alternatively: define( 'WP_REDIS_TLS', true );

The server certificate is **verified by default**. For an internal CA or a self-signed certificate, pass SSL stream-context options:

    define( 'WP_REDIS_TLS_OPTIONS', array( 'cafile' => '/etc/ssl/redis-ca.pem' ) );
    // self-signed (not recommended): define( 'WP_REDIS_TLS_OPTIONS', array( 'verify_peer' => false ) );

TLS requires `phpredis >= 5.3` in php-fpm. Plaintext connections are unchanged. The isolated page-cache connection honours the same settings, or its own `FLAVOR_EDGE_PC_REDIS_TLS` / `FLAVOR_EDGE_PC_REDIS_TLS_OPTIONS`.

**Isolating the page cache from a shared instance (optional):**

If the origin page cache uses Redis/Memcached on an instance shared with other sites, you can give it its own, isolated connection so a foreign `flush_all` / `flushDb` can never reach it:

    define( 'FLAVOR_EDGE_PC_REDIS_HOST', '127.0.0.1' );
    define( 'FLAVOR_EDGE_PC_REDIS_PORT', 6379 );
    define( 'FLAVOR_EDGE_PC_REDIS_PASSWORD', 'your-password' );
    define( 'FLAVOR_EDGE_PC_REDIS_DB', 2 );
    // Memcached equivalent: FLAVOR_EDGE_PC_MEMCACHED_HOST / _PORT

Each falls back to the matching `WP_*` constant when unset, so default behaviour is unchanged.

**If the backend goes down:** the panel shows the object cache as *"Not persistent"* in red (recomputing against the database every request) rather than pretending it is healthy. Start the service, or disable the object cache, to clear it.

== WP-CLI ==

The plugin registers a `wp te-cache` command for operations and deploy pipelines:

    wp te-cache purge --all                 # soft site-wide purge (add --ban for a hard recursive ban)
    wp te-cache purge --url=<url[,url]>      # purge one or more URLs (add --refetch to re-warm)
    wp te-cache purge --tag=<key[,key]>      # purge by Surrogate-Key tag
    wp te-cache warm                         # queue a background preload
    wp te-cache warm --block                 # run the preload to completion (CI/deploy)
    wp te-cache warm --status                # inspect a preload in progress
    wp te-cache status                       # API / object cache / page cache health
    wp te-cache config                       # effective configuration (credentials redacted)

Soft purge is the default; add `--hard` to force an immediate purge. The commands are thin wrappers over the same hardened purge/preload APIs the plugin uses internally.

== Changelog ==

= 1.11.0 =
Performance and reliability improvements across invalidation, the object cache and the origin page cache. Invalidation remains key-based and surgical: only what actually changed is purged.
* Object cache (Redis): the drop-in keeps a persistent connection per PHP-FPM worker and serves multi-key reads in a single round-trip (MGET / wp_cache_get_multiple), reducing calls on the logged-in and admin path.
* Media: an image is purged only when its file actually changes (size/mtime signature) — editing an attachment's title, alt text or caption no longer purges it. Thumbnails are evicted without a refetch, and bulk URL purges are capped (filter: flavor_edge_max_purge_urls).
* Publishing: invalidation is dispatched with a short, non-blocking timeout so saving a post returns promptly. New filter flavor_edge_full_purge_on_plugin_change scopes or disables the full purge that runs when a plugin is activated or deactivated.
* Page cache: a full flush is now an O(1) directory rename plus a scheduled cleanup instead of a synchronous recursive delete; the generated config file is rewritten only when its contents change; stored pages keep the already-optimized HTML so the optimizer runs once per miss.
* Warm-up: fully cron-driven, with a watchdog that resumes a run interrupted mid-way; the origin-health probe is briefly cached so repeated purges don't re-probe on each one.
* Autoload: a static class map replaces per-class filesystem lookups on every request (directory scan kept as a fallback); Speculation Rules are built once per request.

= 1.10.0 =
Two new capabilities on top of the stable 1.9.1 base. No changes to existing behaviour.
* NEW (WP-CLI): `wp te-cache purge|warm|status|config` for operations and deploy pipelines — soft/hard/tag/URL purge, background or blocking (`--block`) warm-up, and a health/config read-out (credentials are never printed). Thin wrappers over the already-hardened purge/preload APIs. See the new WP-CLI section.
* NEW (Redis over TLS): the object cache and the origin page cache can now connect to Redis over TLS (`rediss`) with certificate verification ON by default — for managed/remote Redis with in-transit encryption (e.g. ElastiCache). Enable with `WP_REDIS_SCHEME='tls'` (or `WP_REDIS_TLS=true`); adjust verification via `WP_REDIS_TLS_OPTIONS` for an internal CA or a self-signed cert. Requires phpredis >= 5.3 in php-fpm. Plaintext connections are unchanged; the drop-in regenerates on upgrade so the new code reaches existing installs. See the Object cache section.

= 1.9.1 =
Hardening pass after a full code audit, before deploying to a high-traffic site. No new features; correctness, safety and robustness only.
* FIX (upgrade safety): the object-cache, advanced-cache and wp-config drop-ins are now written ATOMICALLY (temp file + rename + opcache invalidation), preserving permissions and symlinks. An in-place write could be read half-formed by a concurrent request during the post-update drop-in regeneration → site-wide white screen. This closes that window.
* FIX (privacy): password-protected posts (wp-postpass_) and commenter "awaiting moderation" views (comment_author_) are no longer cached publicly — a hardcoded always-excluded cookie list now applies regardless of configuration.
* FIX: the "Skip wizard" button no longer wiped every setting (including the master switch) — it only dismisses now; the settings save handler rejects empty/non-form payloads.
* FIX: a cached API token now records its expiry, so a purge/warm no longer re-authenticates on every call (which could trip the circuit breaker); Retry-After now accepts the HTTP-date form.
* FIX: surrogate-key purges are sanitize_key-normalised to match the emitted keys (mixed-case menu/taxonomy ids now invalidate); REST responses no longer cache errors and always carry a purgeable key; language-varied page-cache entries are now purged (best-effort by configured languages; keep Vary-by-language off for URL-based i18n).
* FIX (Used CSS / Combine — opt-in features): the Used-CSS parser is brace-aware (no longer flattens @media / mangles @keyframes); critical CSS is keyed per section term; Combine respects each stylesheet's media attribute and dependency order, includes the minify flag + version in its cache key, and deregisters combined JS to prevent double execution. Self-hosted Google Fonts now download in the BACKGROUND (never synchronously in <head> — no more first-hit white-screen risk) with atomic writes and a lock.
* FIX (robustness): HTML output transforms are null-guarded (a PCRE backtrack limit can no longer blank a page); Memcached object-cache long TTLs no longer expire instantly; incr/decr persist; backend detection is memoised per request; page-cache write-latency telemetry only runs during a preload.
* FIX (preload): an API outage no longer drains the queue and falsely reports "completed"; a cross-process lock prevents the AJAX tick and cron from double-processing; an unreachable origin now gives up ("stalled") instead of rescheduling forever; unpublishing a post no longer warms a fresh 404.
* FIX (multisite/security): settings cache resets on switch_to_blog; the new-site hook no longer fatals outside wp-admin; network credentials are encrypted at rest; the login limiter no longer trusts spoofable client-IP headers by default; the wizard no longer re-enables Vary-by-device; login max-attempts is floor-clamped; rotated WP salts that make credentials unreadable are now flagged.

= 1.9.0 =
This release hardens the WordPress object-cache layer and the warm-up, after a full code review. These are the areas that fell short of a mature cache plugin on an editor-heavy site.
* NEW (object cache is now turnkey): when a live Redis or Memcached backend is detected, the object-cache drop-in is installed automatically (opt-out under TE Cache → Cache → Object cache). After a migration from another cache plugin the logged-in/admin path is no longer left hitting MySQL with no object cache. It never overwrites an object-cache.php from another plugin silently — it flags it and offers a one-click "Replace with ours" (the foreign drop-in is backed up first).
* FIX (password-protected Redis now reported correctly): on a secured install (Redis with requirepass) where phpredis IS installed but WP_REDIS_PASSWORD is not set, the backend was reported as a bare "Not available" with no hint. The detection now recognises the NOAUTH/WRONGPASS cases in the phpredis path too and tells you to add WP_REDIS_PASSWORD to wp-config.php; a configured-but-down Redis is reported as "not answering" rather than "extension not installed". See the new Object cache section in the readme. Documented the required WP_REDIS_* constants.
* FIX (our object-cache fixes now actually reach existing installs): the object-cache drop-in is a self-contained copy, so it did NOT update when the plugin updated — a site that enabled it on an older version kept running the old, buggy drop-in until it was manually re-enabled. The drop-in now carries a version stamp and is regenerated automatically on plugin upgrade when it is ours and out of date.
* FIX (the dashboard no longer reports a dead cache as healthy): if the object-cache backend went down, the drop-in fell back to a per-request array that is not persistent but kept counting "hits", so the panel looked alive while nothing was cached between requests. Degradation is now detected with a live backend check and shown honestly ("Not persistent — recomputing against the database on every request"); a backend blocked by a foreign drop-in is shown as "Blocked".
* FIX (APCu drop-in hardened to match Redis/Memcached): the APCu object cache used an unsalted prefix, so two installs in one PHP-FPM pool collided, and its flush cleared the entire APCu pool rather than only its own entries. It now salts keys per install and flushes only its own namespace (APCUIterator).
* NEW (warm-up builds its URL list locally and in priority order): the preload now assembles URLs directly from WordPress — home, then the busiest section archives, then the most-recent content — instead of fetching the sitemap over HTTP. This avoids the origin-to-CDN-and-back hairpin and warms the pages that matter first. The sitemap-over-HTTP path remains as an automatic fallback for headless/decoupled front ends. Filters: flavor_edge_preload_terms, flavor_edge_preload_recent_posts, flavor_edge_preload_urls, flavor_edge_preload_local_min.
* NEW (editorial write controls, opt-in): optional limits on stored revisions (wp_revisions_to_keep) and autosave interval (AUTOSAVE_INTERVAL), under TE Cache → Cache → Editorial write load. Off by default; every autosave and revision is a database write on the logged-in path that no cache absorbs, so an editor-heavy site can throttle it without editing wp-config.php. It never overrides an AUTOSAVE_INTERVAL already set in wp-config.php.
* On deactivation the plugin now removes its own object-cache and page-cache drop-ins, so a disabled plugin never leaves a live drop-in behind.

= 1.8.1 =
* FIX (sitemap preload returned "no URLs found"): the sitemap/preload HTTP requests now force HTTP/1.1. WordPress' wp_remote_get defaults to HTTP/1.0, which a CDN edge can silently drop over IPv4 (TCP/TLS connect, then 0 bytes after the GET), so the fetch timed out and the preload reported an empty sitemap. Applied to the sitemap fetch, the sub-sitemap fetch, the origin health probe and the HTTP warm fallback.
* FIX (large Yoast sitemaps): a sitemap index with many child sitemaps (e.g. 50+ post-sitemapN.xml) was fetched in full inside one AJAX request and timed out. The preload now stops fetching children as soon as it has enough URLs for the cap (flavor_edge_preload_max_urls).
* FIX (preload warmed nothing behind a CDN): a per-URL gethostbyname + private-IP check rejected every sitemap URL when the origin resolves its own hostname to a private IP (common for hairpin avoidance). Removed — the same-host match already bounds the preload to the site's own URLs, so the check was redundant and harmful.
* A candidate URL is now treated as a sitemap only when the body actually contains <sitemapindex> or <urlset>, not on any HTTP 200 (e.g. a 200 HTML page). Sitemap fetch timeout is filterable (flavor_edge_preload_sitemap_timeout, default 20s).

= 1.8.0 =
* NEW (adaptive warm-up pacing): the sitemap preload now auto-tunes its throughput to what the origin can take, instead of a fixed rate. It grows the batch size while the origin answers fast and the page-cache disk has headroom, and halves it on a platform rate limit, a slow origin, or a near-full disk (AIMD). On a strong origin a bulk warm of thousands of URLs finishes in a fraction of the time; on a weak one it stays slow and safe by itself. The guardrails are unchanged — it only moves within them. Toggle under TE Cache → Cache → Adaptive pacing.
* NEW (disk guardrail): because warming a URL also writes its page-cache entry, a bulk warm is disk I/O on the origin, not just HTTP fetches. The preload now pauses when the page-cache disk drops below a free-space floor (disk backend only; Redis/Memcached write to memory and are exempt). Filter: flavor_edge_preload_disk_floor.
* The preload URL cap is now filterable (flavor_edge_preload_max_urls) for sites with more than a few hundred URLs; adaptive-pacer thresholds are filterable too (flavor_edge_preload_batch_max, flavor_edge_preload_up_tolerance, flavor_edge_preload_down_tolerance, flavor_edge_preload_disk_green).
* FIX (Memcached surgical invalidation): a per-URL page-cache delete targeted the non-generation key while get/set use the generation-scoped key, so on Memcached the surgical delete always missed and changed pages rode the TTL instead of being evicted on publish. Now matched. Redis was unaffected (it is generation-less and already consistent).
* FIX (object cache no longer flushes a shared store): the bundled object cache's wp_cache_flush() did a Memcached flush_all / Redis flushDb that wiped the ENTIRE instance — taking the page-cache shield and any other tenant (a production site sharing the same server via the generic WP_MEMCACHED_* / WP_REDIS_* connection) down with it, on any flush fired by WordPress, WP-CLI or a third-party WPO plugin (e.g. on minify). It now invalidates only its own entries (generation bump on Memcached, prefix-scoped SCAN+DEL on Redis) and namespaces its keys per install, so two installs sharing one instance never collide or flush each other.
* NEW (dedicated page-cache connection): FLAVOR_EDGE_PC_MEMCACHED_HOST/PORT and FLAVOR_EDGE_PC_REDIS_HOST/PORT/PASSWORD/DB let the page cache live on its own Memcached instance or Redis DB index, isolated from the generic connection other plugins share — so a third-party flush_all/flushDb cannot reach the shield (flush_all is instance-wide and survives no key trick; isolation is the only real guard). Each falls back to the matching WP_* constant, so default behaviour is unchanged.
* FIX (origin page cache — archive coverage): a content change now evicts the affected taxonomy (category/tag), author and post-type archive pages from the origin page cache, not just the post permalink and the home. Before, those archives rode the short TTL, and with a long edge HTML TTL plus refetch an archive could be re-cached stale at the edge. (Multilingual note: the current-language archive URL is evicted; the edge still drops all languages via the surrogate tag.)
* FIX (storage backend detection): the Redis and Memcached page-cache options are now reported as "Available" only after a live round-trip to the server, not merely when the PHP client extension is present. Before, a box with the client library but no running daemon showed a green tick and, if selected, the page cache silently stored nothing (origin left unshielded). Memcached is now verified with a real set/get round-trip and Redis with connect+PING; a server-up-but-extension-missing case is detected for both so the advice is accurate.

= 1.7.0 =
* NEW (origin-safe warm-up): after a post is republished, the changed URLs are now re-warmed with a platform refetch instead of an origin-side GET. One call re-warms the URL on every edge node (the invalidation fans out to all nodes), while the shared refetcher nodes aggregate the origin fetches — the origin sees one or two requests instead of one per node — and it needs no MidTier. The origin GET is kept as an automatic fallback for when the platform API is unavailable (selectable under TE Cache → Cache → Warm method).
* NEW (sitemap preload, origin protection): the bulk sitemap warm no longer sends one origin GET per URL. Each batch is warmed with a single aggregated refetch, and the preload is gated so it cannot overload a weak origin — a live origin-health probe pauses it when the origin is slow or erroring, an optional off-peak window restricts it to quiet hours, and it backs off exponentially (honouring Retry-After) if the platform rate-limits. An advanced "prefetch" method is available for strong origins (populates without evicting, but fans out one fetch per node).
* NEW: TE_Api::prefetch() wraps the platform /prefetch/ endpoint; purge_urls() now reports the HTTP status and Retry-After, and treats a 206 partial purge as success (no longer trips the circuit breaker).
* FIX: warm-up now targets the canonical page URL — query string and fragment are stripped and an empty path is normalised to "/". A page is no longer warmed as several separate cache objects when WPML or another plugin decorates home_url()/get_permalink() during a save (e.g. a stray ?translation_priority=…), and "https://site" / "https://site/" collapse into one.
* Filters: flavor_edge_preload_health_url, flavor_edge_preload_health_timeout, flavor_edge_preload_health_max_latency, flavor_edge_preload_wait_delay.

= 1.6.0 =
* FIX (cache fragmentation): Vary: X-Device and Vary: Accept-Language are no longer sent by default. The plugin serves the same anonymous HTML to every device, and WPML/Polylang carry the language in the URL, so these only split the edge cache into extra copies per device bucket and per browser Accept-Language string, with no real variant behind them — hurting the hit ratio. Both are now opt-in (for sites that genuinely serve different HTML for the same URL), the Vary header is merged with (not replacing) any legitimate Vary already set while still stripping Cookie / User-Agent, and a one-time migration turns them off on existing installs with a dismissible notice.
* The origin page-cache backend selector shows, per backend, whether it is actually available on this server. If Redis or Memcached is selected without its PHP extension, the dashboard and the selector warn clearly and the Enable button is held back — previously the page cache could report "enabled" while silently storing nothing (no origin protection). The dashboard also distinguishes a page cache that is really serving ("Enabled") from one switched on but dormant because WP_CACHE is not set ("Enabled (not serving)"), instead of a misleading green.
* FIX: Admin tab bar wraps cleanly when many tabs are present (no more a lone, broken-looking tab on a second row).
* NEW (Origin Shield): Origin page cache — serves anonymous HTML before WordPress boots (disk / Redis / Memcached backends), so a CDN miss no longer regenerates WordPress. Partitioned by device, skips logged-in users, and is invalidated by the same purge events as the edge (one purge clears edge + origin). Opt-in; backend is selectable with its trade-offs shown on screen. Host allowlist and per-entry size cap protect a weak origin.
* NEW (Origin Shield): Dedicated homepage cache profile — the most-requested URL gets its own short-fresh + generous stale-while-revalidate policy, owned by the plugin instead of a hand-written VCL branch. Extensible per template via the flavor_edge_cache_profile filter.
* NEW (Origin Shield): Cookie guard — propose-only VCL that strips non-essential cookies before the cache lookup (so a stray analytics/consent cookie no longer forces a pass), plus an on-demand Set-Cookie / Vary: Cookie origin probe.
* NEW: Vary neutralisation at the origin — the anonymous document Vary never carries Cookie or User-Agent (which fragments the edge cache into one object per analytics cookie), re-asserted right before headers are sent so a theme/plugin cannot re-add it.
* NEW: Speculation Rules inline injection (now the default) — rules are written into the page <head> that the edge already caches, removing the per-navigation request to the /wp-json/ endpoint that could reach the origin.
* NEW: Serve-stale (grace/keep) VCL recommender — derives Varnish grace/keep from the Cache-Control the plugin sends, so stale-while-revalidate / stale-if-error are actually honoured at the edge.
* NEW: Object cache Memcached backend drop-in (previously detected and recommended but never generated — a server with Memcached and no Redis could write a broken drop-in). Installing an unsupported backend is now refused instead of writing one.
* FIX: Global stale-while-revalidate / stale-if-error are now configurable (previously hardcoded). The page cache never stores a response carrying Set-Cookie, and the wp-config.php backup is written to a non web-served location.

= 1.5.2 =
* HARDENING (origin protection): warm-up now holds a concurrency lock so simultaneous republishes can't stampede the origin with parallel warm-up runs. Batch size, pause and request timeout are filterable for delicate origins. Purge is debounced per post (default 5s) to absorb editors hitting "Update" repeatedly. Corrected the warm-up doc comment to match actual (blocking, paced) behaviour.

= 1.5.1 =
* FIX: CSS combine discarded inline CSS added via wp_add_inline_style() (e.g. theme mega-menu styles, WordPress core global-styles, block styles). It is now carried into the bundle in the correct cascade order.
* HARDENING: Google Fonts cached filename validates the font extension against an allowlist. Remove Unused CSS sanitizes the post type used in the cache filename.

= 1.5.0 =
* NEW: Remove Unused CSS / Critical CSS — asynchronous per-template generation, inline critical CSS + deferred rest, Surrogate-Key invalidation, graceful fallback to original CSS.
* NEW: Lazy load for CSS background images (IntersectionObserver, first two kept eager for LCP, data-no-lazy opt-out).
* NEW: Security module — contracted-service gating (TE_Capabilities), security-headers VCL recommender with CSP Report-Only default, local hardening (block PHP in uploads, disable XML-RPC, limit login attempts).
* NEW: JS dependency-tree safety — Delay/Defer/Combine now respect WordPress script dependencies, preventing "Backbone is not defined" / Marionette errors (e.g. Ninja Forms) without manual exclusions.
* FIX: Uploads .htaccess now preserves existing content instead of overwriting it.
* FIX: Login limiter uses True-Client-IP for correct client identification behind the CDN.

= 1.4.0 =
* NEW: Activation checks write permissions on cache directories and shows the exact fix commands if unwritable.
* NEW: CSS/JS combine degrades gracefully, serving original files if the cache directory is not writable.
* FIX: Tab layout wraps cleanly on narrow viewports; renamed the duplicate Invalidation tab to History.

= 1.3.1 =
* FIX: Admin form serialization for radio buttons and checkbox arrays (Speculation Rules settings were not saving correctly).

= 1.3.0 =
* NEW: Speculation Rules — automatic prefetch/prerender coordinated with the CDN, with smart per-plugin exclusions, conflict detection, and PHP or VCL header injection.

= 1.2.0 =
* SECURITY: Credentials encrypted at rest (AES-256-CBC). SSRF protection in sitemap preload and warm-up. Path-traversal and regex-injection fixes. Debug headers restricted to logged-in admins.
* FIX: purge_all now uses soft tag purge by default instead of BAN, preventing origin overload. All automated invalidations forced to soft purge.

= 1.1.0 =
* NEW: Graceful degradation — circuit breaker and periodic health check on the API; local optimizations keep working when the CDN is unavailable. Admin notices and degraded indicator. Optional purge on plugin activation/deactivation.

= 1.0.0 =
* Initial release
