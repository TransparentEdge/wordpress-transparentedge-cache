=== Transparent Edge Cache ===
Contributors: transparentedge
Tags: cache, cdn, varnish, performance, wpo, optimization, woocommerce, security
Requires at least: 5.5
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.8.0
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
* Object Cache with Redis and APCu
* Apache and Nginx support

== Installation ==

1. Upload the plugin ZIP via Plugins, Add New, Upload Plugin
2. Activate and go to TE Cache
3. Complete the Setup Wizard or enter credentials manually

== Changelog ==

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
