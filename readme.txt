=== Transparent Edge Cache ===
Contributors: transparentedge
Tags: cache, cdn, varnish, performance, wpo, optimization, woocommerce, security
Requires at least: 5.5
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.5.2
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
