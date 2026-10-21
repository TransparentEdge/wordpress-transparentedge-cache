<?php
/**
 * Intelligent cache invalidation module.
 *
 * Hooks into WordPress events (save_post, menu updates, widget changes, etc.)
 * and determines the minimal set of Surrogate-Keys to purge.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Invalidation {

	/**
	 * Queue of tags pending invalidation (batched at shutdown).
	 *
	 * @var array
	 */
	private static $pending_tags = array();

	/**
	 * Queue of URLs pending invalidation.
	 *
	 * @var array
	 */
	private static $pending_urls = array();

	/**
	 * Queue of URLs to warm after tag purge.
	 * These are the resolved URLs (term links, post permalinks, etc.)
	 * that correspond to the purged tags.
	 *
	 * @var array
	 */
	private static $warmup_urls = array();

	/**
	 * Whether a full purge is pending.
	 *
	 * @var bool
	 */
	private static $full_purge_pending = false;

	/**
	 * Initialize invalidation hooks.
	 */
	public static function init() {
		if ( ! TE_Settings::is_module_enabled( 'invalidation' ) ) {
			return;
		}

		$s = TE_Settings::get_all();

		// Warm-up cron hook.
		add_action( 'flavor_edge_warmup_process', array( __CLASS__, 'process_warmup_queue' ) );

		// Post lifecycle.
		if ( $s['purge_on_post'] ) {
			add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 10, 2 );
			add_action( 'delete_post', array( __CLASS__, 'on_delete_post' ), 10, 1 );
			add_action( 'wp_trash_post', array( __CLASS__, 'on_delete_post' ), 10, 1 );
			add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 10, 3 );
		}

		// Comments.
		if ( $s['purge_on_comment'] ) {
			add_action( 'comment_post', array( __CLASS__, 'on_new_comment' ), 10, 3 );
			add_action( 'edit_comment', array( __CLASS__, 'on_edit_comment' ), 10, 2 );
			add_action( 'wp_set_comment_status', array( __CLASS__, 'on_comment_status' ), 10, 2 );
		}

		// Menus.
		if ( $s['purge_on_menu'] ) {
			add_action( 'wp_update_nav_menu', array( __CLASS__, 'on_menu_update' ), 10, 1 );
		}

		// Widgets.
		if ( $s['purge_on_widget'] ) {
			add_action( 'update_option_sidebars_widgets', array( __CLASS__, 'on_widget_update' ) );
		}

		// Theme switch.
		if ( $s['purge_on_theme_switch'] ) {
			add_action( 'switch_theme', array( __CLASS__, 'on_theme_switch' ) );
		}

		// Plugin activation / deactivation.
		if ( $s['purge_on_plugin_change'] ) {
			add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_change' ), 10, 2 );
			add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_change' ), 10, 2 );
		}

		// Term changes.
		add_action( 'edited_term', array( __CLASS__, 'on_term_edit' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'on_term_delete' ), 10, 4 );

		// WooCommerce stock changes.
		if ( $s['woo_purge_stock'] ) {
			add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_woo_stock_change' ) );
			add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_woo_stock_change' ) );
		}

		// Flush the queue at shutdown.
		add_action( 'shutdown', array( __CLASS__, 'flush_queue' ), 1 );
	}

	// -------------------------------------------------------------------------
	// Event handlers.
	// -------------------------------------------------------------------------

	/**
	 * Handle post save.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public static function on_save_post( $post_id, $post ) {
		// Skip autosaves, revisions, and non-published content.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		// Skip non-public post types.
		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type || ! $post_type->public ) {
			return;
		}

		self::queue_post_tags( $post_id, $post );
	}

	/**
	 * Handle post status transitions (e.g. publish → trash, draft → publish).
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 */
	public static function on_transition_post_status( $new_status, $old_status, $post ) {
		// Purge when going from published to unpublished or vice versa.
		if ( ( 'publish' === $old_status && 'publish' !== $new_status ) ||
			 ( 'publish' !== $old_status && 'publish' === $new_status ) ) {
			// T2.7: only warm the post's own permalink when it ENDS UP published. When
			// it is leaving 'publish' (trash/draft/pending/private) the permalink is no
			// longer publicly viewable, so warming it after the purge would cache a
			// fresh 404 at the edge. Tags/URLs are still purged; archives/home still warm.
			$warm_self = ( 'publish' === $new_status );
			self::queue_post_tags( $post->ID, $post, $warm_self );
		}
	}

	/**
	 * Handle post deletion.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_delete_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		// T2.7: the post is being trashed/deleted — still purge its tags/URLs, but do
		// NOT warm its own (now-dead) permalink, or the edge re-caches a fresh 404.
		// (This fires on wp_trash_post BEFORE the status flips, so a status check here
		// would still read 'publish'; pass the intent explicitly instead.)
		self::queue_post_tags( $post_id, $post, false );
	}

	/**
	 * Handle new comment.
	 *
	 * @param int        $comment_id       Comment ID.
	 * @param string     $comment_approved Approval status.
	 * @param array      $commentdata      Comment data.
	 */
	public static function on_new_comment( $comment_id, $comment_approved, $commentdata ) {
		if ( 1 === $comment_approved || '1' === $comment_approved ) {
			$comment = get_comment( $comment_id );
			if ( $comment && $comment->comment_post_ID ) {
				self::$pending_tags[] = 'post-' . $comment->comment_post_ID;
				self::$warmup_urls[]  = get_permalink( $comment->comment_post_ID );
			}
		}
	}

	/**
	 * Handle comment edit.
	 *
	 * @param int   $comment_id Comment ID.
	 * @param array $data       Comment data.
	 */
	public static function on_edit_comment( $comment_id, $data ) {
		$comment = get_comment( $comment_id );
		if ( $comment && $comment->comment_post_ID ) {
			self::$pending_tags[] = 'post-' . $comment->comment_post_ID;
			self::$warmup_urls[]  = get_permalink( $comment->comment_post_ID );
		}
	}

	/**
	 * Handle comment status change.
	 *
	 * @param int    $comment_id Comment ID.
	 * @param string $status     New status.
	 */
	public static function on_comment_status( $comment_id, $status ) {
		$comment = get_comment( $comment_id );
		if ( $comment && $comment->comment_post_ID ) {
			self::$pending_tags[] = 'post-' . $comment->comment_post_ID;
			self::$warmup_urls[]  = get_permalink( $comment->comment_post_ID );
		}
	}

	/**
	 * Handle menu update.
	 *
	 * @param int $menu_id Menu ID.
	 */
	public static function on_menu_update( $menu_id ) {
		$locations = get_nav_menu_locations();
		foreach ( $locations as $location => $assigned_menu_id ) {
			if ( (int) $assigned_menu_id === (int) $menu_id ) {
				self::$pending_tags[] = 'menu-' . $location;
			}
		}
		// If we can't determine which location, purge everything with menus.
		if ( empty( self::$pending_tags ) ) {
			self::$pending_tags[] = 'front-page';
		}

		// Menus are global — warm the front page at least.
		self::$warmup_urls[] = home_url( '/' );
	}

	/**
	 * Handle widget update.
	 */
	public static function on_widget_update() {
		// Sidebars are global — purge all pages that contain them.
		global $wp_registered_sidebars;
		if ( ! empty( $wp_registered_sidebars ) ) {
			foreach ( array_keys( $wp_registered_sidebars ) as $sidebar_id ) {
				self::$pending_tags[] = 'sidebar-' . $sidebar_id;
			}
		}

		// Warm front page.
		self::$warmup_urls[] = home_url( '/' );
	}

	/**
	 * Handle theme switch — full purge.
	 */
	public static function on_theme_switch() {
		self::$full_purge_pending = true;

		// Unused CSS depends on the active theme — regenerate.
		if ( class_exists( __NAMESPACE__ . '\\TE_UnusedCSS' ) ) {
			TE_UnusedCSS::clear_cache();
		}
	}

	/**
	 * Handle plugin activation or deactivation — full purge.
	 *
	 * Plugins can affect any part of the frontend (shortcodes, widgets,
	 * scripts, styles, REST endpoints), so a full purge is the safest
	 * approach to ensure CDN content matches the origin.
	 *
	 * @param string $plugin Plugin basename (e.g., 'woocommerce/woocommerce.php').
	 * @param bool   $network_wide Whether this is a network-wide action.
	 */
	public static function on_plugin_change( $plugin, $network_wide = false ) {
		// Don't purge when our own plugin is being deactivated —
		// at that point the API credentials may already be unavailable.
		if ( FLAVOR_EDGE_BASENAME === $plugin ) {
			return;
		}

		self::$full_purge_pending = true;

		// Active plugins affect Used CSS — regenerate.
		if ( class_exists( __NAMESPACE__ . '\\TE_UnusedCSS' ) ) {
			TE_UnusedCSS::clear_cache();
		}
	}

	/**
	 * Handle term edit.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function on_term_edit( $term_id, $tt_id, $taxonomy ) {
		self::$pending_tags[] = 'term-' . $term_id;
		self::$pending_tags[] = 'tax-' . $taxonomy;

		// Warm the term archive page.
		$term_link = get_term_link( (int) $term_id, $taxonomy );
		if ( ! is_wp_error( $term_link ) ) {
			self::$warmup_urls[] = $term_link;
		}
	}

	/**
	 * Handle term deletion.
	 *
	 * @param int    $term_id      Term ID.
	 * @param int    $tt_id        Term taxonomy ID.
	 * @param string $taxonomy     Taxonomy slug.
	 * @param mixed  $deleted_term Deleted term object.
	 */
	public static function on_term_delete( $term_id, $tt_id, $taxonomy, $deleted_term ) {
		self::$pending_tags[] = 'term-' . $term_id;
		self::$pending_tags[] = 'tax-' . $taxonomy;
		self::$pending_tags[] = 'front-page';

		self::$warmup_urls[] = home_url( '/' );
	}

	/**
	 * Handle WooCommerce stock change.
	 *
	 * @param \WC_Product $product Product object.
	 */
	public static function on_woo_stock_change( $product ) {
		if ( method_exists( $product, 'get_id' ) ) {
			$product_id = $product->get_id();
			self::$pending_tags[] = 'post-' . $product_id;

			// Warm the product page.
			self::$warmup_urls[] = get_permalink( $product_id );

			// Warm the shop page.
			if ( function_exists( 'wc_get_page_id' ) ) {
				$shop_id = wc_get_page_id( 'shop' );
				if ( $shop_id > 0 ) {
					self::$warmup_urls[] = get_permalink( $shop_id );
				}
			}

			// Warm product category pages.
			$terms = get_the_terms( $product_id, 'product_cat' );
			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					self::$pending_tags[] = 'term-' . $term->term_id;
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						self::$warmup_urls[] = $link;
					}
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// Tag generation.
	// -------------------------------------------------------------------------

	/**
	 * Queue tags affected by a post change.
	 *
	 * @param int      $post_id   Post ID.
	 * @param \WP_Post $post      Post object.
	 * @param bool     $warm_self Whether to warm the post's OWN permalink. Pass false
	 *                            when the post is leaving 'publish' (T2.7) so we don't
	 *                            re-cache a 404 for a now-unviewable permalink.
	 */
	private static function queue_post_tags( $post_id, $post, $warm_self = true ) {
		// Debounce: editors often hit "Update" several times in a row. If this
		// exact post was already queued very recently, skip re-queuing its tags
		// and warm-up URLs to avoid repeated purge+warm cycles against origin.
		// The post's own tag is still ensured below so the edit is never lost.
		// The debounce is bypassed when the post is leaving 'publish' ($warm_self
		// false): pulling a story (trash/unpublish) must ALWAYS do the full fan-out
		// (home, sections, author) so a just-removed article stops being linked from
		// archives immediately — never collapsed to a post-only purge by a debounce
		// left over from the publish moments earlier.
		$debounce = (int) apply_filters( 'flavor_edge_purge_debounce_seconds', 5 );
		if ( $warm_self && $debounce > 0 ) {
			$key = 'flavor_edge_post_debounce_' . $post_id;
			if ( get_transient( $key ) ) {
				// Within the debounce window: ensure the post tag is present
				// (cheap, deduped at flush) but skip the full fan-out.
				self::$pending_tags[] = 'post-' . $post_id;
				return;
			}
			set_transient( $key, 1, $debounce );
		}

		// The post itself.
		self::$pending_tags[] = 'post-' . $post_id;

		// Post type archive.
		self::$pending_tags[] = 'type-' . $post->post_type;

		// Author page.
		self::$pending_tags[] = 'author-' . $post->post_author;

		// Front page / blog page.
		self::$pending_tags[] = 'front-page';

		// Feeds.
		self::$pending_tags[] = 'feed';

		// Taxonomy terms.
		$taxonomies = get_object_taxonomies( $post->post_type );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					self::$pending_tags[] = 'term-' . $term->term_id;

					// Collect term URL for warm-up.
					$term_link = get_term_link( $term );
					if ( ! is_wp_error( $term_link ) ) {
						self::$warmup_urls[] = $term_link;
					}
				}
				self::$pending_tags[] = 'tax-' . $taxonomy;
			}
		}

		// Collect warm-up URLs.
		// T2.7: only warm the post's own permalink when it is still publicly viewable.
		// On a publish → trash/draft/pending/private transition the permalink resolves
		// to a 404/non-public page, and warming it after the purge would cache a fresh
		// 404 at the edge. The home page and archives below are still warmed.
		if ( $warm_self ) {
			self::$warmup_urls[] = get_permalink( $post_id );
		}
		self::$warmup_urls[] = home_url( '/' );

		// Post type archive URL (for CPTs).
		$post_type_archive = get_post_type_archive_link( $post->post_type );
		if ( $post_type_archive ) {
			self::$warmup_urls[] = $post_type_archive;
		}

		// Blog page (if different from front page).
		$blog_page_id = (int) get_option( 'page_for_posts' );
		if ( $blog_page_id ) {
			self::$warmup_urls[] = get_permalink( $blog_page_id );
		}

		// Author archive.
		self::$warmup_urls[] = get_author_posts_url( $post->post_author );

		// Allow extensions to add more tags.
		self::$pending_tags = apply_filters( 'flavor_edge_post_purge_tags', self::$pending_tags, $post_id, $post );
		self::$warmup_urls  = apply_filters( 'flavor_edge_post_warmup_urls', self::$warmup_urls, $post_id, $post );
	}

	// -------------------------------------------------------------------------
	// Queue execution.
	// -------------------------------------------------------------------------

	/**
	 * Maximum URLs to warm per event (safety limit).
	 */
	const MAX_WARMUP_URLS = 20;

	/**
	 * Transient key and TTL for the warm-up concurrency lock.
	 * The TTL is a safety net: if a warm-up process dies mid-run, the lock
	 * auto-expires so warm-up isn't blocked forever.
	 */
	const WARMUP_LOCK     = 'flavor_edge_warmup_lock';
	const WARMUP_LOCK_TTL = 120; // seconds.

	/**
	 * Flush the pending invalidation queue.
	 * Called at shutdown to batch all purge requests.
	 */
	public static function flush_queue() {
		// Full purge takes precedence.
		if ( self::$full_purge_pending ) {
			$result = TE_Api::purge_all();
			self::$full_purge_pending = false;
			self::$pending_tags       = array();
			self::$pending_urls       = array();

			do_action( 'flavor_edge_after_purge_all', $result );

			// Warm key pages after full soft purge.
			if ( TE_Settings::get( 'refetch_enabled' ) ) {
				self::$warmup_urls[] = home_url( '/' );
				$blog_page_id = (int) get_option( 'page_for_posts' );
				if ( $blog_page_id ) {
					self::$warmup_urls[] = get_permalink( $blog_page_id );
				}
				self::do_warmup();
			}

			self::$warmup_urls = array();
			return;
		}

		// Tag-based purge (always soft — prevents thundering herd on origin).
		// sanitize_key each tag so it matches the Surrogate-Keys the headers module
		// emits (which are sanitize_key'd): a mixed-case menu/taxonomy id like
		// "menu-topBar" must purge the stored "menu-topbar" or the purge is a no-op.
		$tags = array_unique( array_filter( array_map( 'sanitize_key', self::$pending_tags ) ) );
		if ( ! empty( $tags ) ) {
			$result = TE_Api::purge_tags( $tags, true );

			do_action( 'flavor_edge_after_tag_purge', $result, $tags );

			// Warm-up: resolve purged tags back to URLs and warm Varnish.
			if ( TE_Settings::get( 'refetch_enabled' ) && ! empty( self::$warmup_urls ) ) {
				self::do_warmup();
			}
		}

		// URL-based purge (always soft — fallback or explicit).
		$urls = array_unique( array_filter( self::$pending_urls ) );
		if ( ! empty( $urls ) ) {
			$refetch = TE_Settings::get( 'refetch_enabled' );
			$result  = TE_Api::purge_urls( $urls, true, $refetch );

			do_action( 'flavor_edge_after_url_purge', $result, $urls );
		}

		// Reset queues.
		self::$pending_tags = array();
		self::$pending_urls = array();
		self::$warmup_urls  = array();
	}

	/**
	 * Warm up the cache for purged URLs.
	 *
	 * Preferred path — platform refetch (warmup_method = 'refetch', default):
	 * a single invalidate call with refetch:true. The invalidation message fans
	 * out over RabbitMQ to EVERY edge node, so all nodes re-warm; the actual
	 * origin fetches are delegated to the shared refetcher nodes, which aggregate
	 * them (origin sees ~1-2 instead of one per node). It is general and
	 * origin-safe at once, and it needs no midtier. This is strictly better than
	 * an origin-side GET, which only warms the single node that serves the
	 * WordPress server's egress and leaves the rest of the network cold. One
	 * light API call, so it runs inline at shutdown like the purge itself.
	 *
	 * Fallback path — origin GET (warmup_method = 'http', or when the platform
	 * API is unavailable / the circuit breaker is open, or the refetch call
	 * fails): the URLs are queued and warmed off the save request by
	 * process_warmup_queue(), with the origin protections documented there
	 * (max 20 URLs, paced batches, concurrency lock).
	 */
	private static function do_warmup() {
		// Canonicalise first: warm the clean page URL, never a query-string or
		// fragment variant. WPML and other plugins can decorate home_url() /
		// get_permalink() during a save request (e.g. ?translation_priority=...),
		// which would otherwise fragment the warm into several cache objects for
		// the same page. Drop the query and fragment, and normalise an empty path
		// to "/" so "https://site" and "https://site/" collapse into one.
		$urls = array_filter( array_map( array( __CLASS__, 'canonical_warmup_url' ), self::$warmup_urls ) );
		$urls = array_values( array_unique( $urls ) );

		if ( empty( $urls ) ) {
			return;
		}

		$max = apply_filters( 'flavor_edge_max_warmup_urls', self::MAX_WARMUP_URLS );
		$urls = array_slice( array_values( $urls ), 0, $max );

		// Preferred: platform refetch (general + origin-aggregated). Skip it only
		// when the admin pinned the HTTP method or the API is currently down.
		$method = TE_Settings::get( 'warmup_method' );
		if ( 'http' !== $method && ! TE_Api::is_circuit_open() ) {
			$result = TE_Api::purge_urls( $urls, true, true );
			if ( ! empty( $result['success'] ) ) {
				return;
			}
			// Refetch failed though the circuit was closed — fall through to the
			// origin GET so the content still gets warmed somewhere.
		}

		// Fallback: queue the origin-GET warm to run just after this request
		// completes, so it never adds latency to the editor's save/publish.
		$existing = get_option( 'flavor_edge_warmup_queue', array() );
		$merged   = array_unique( array_merge( $existing, $urls ) );
		$merged   = array_slice( $merged, 0, $max );
		update_option( 'flavor_edge_warmup_queue', $merged, false );

		if ( ! wp_next_scheduled( 'flavor_edge_warmup_process' ) ) {
			wp_schedule_single_event( time() + 1, 'flavor_edge_warmup_process' );
		}
	}

	/**
	 * Reduce a warm-up URL to the canonical page URL we actually want cached:
	 * same scheme/host/path, no query string, no fragment, and never an empty
	 * path. Returns '' for anything that is not a valid URL.
	 *
	 * @param string $url Raw warm-up URL.
	 * @return string Canonical URL, or '' to drop it.
	 */
	private static function canonical_warmup_url( $url ) {
		if ( ! is_string( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}
		$p = wp_parse_url( $url );
		if ( empty( $p['host'] ) ) {
			return '';
		}
		$scheme = ! empty( $p['scheme'] ) ? $p['scheme'] : 'https';
		$path   = isset( $p['path'] ) && '' !== $p['path'] ? $p['path'] : '/';
		$port   = ! empty( $p['port'] ) ? ':' . $p['port'] : '';
		return $scheme . '://' . $p['host'] . $port . $path;
	}

	/**
	 * Process the warm-up queue. Called via cron or directly.
	 */
	public static function process_warmup_queue() {
		// Concurrency lock: never let two warm-up runs hit the origin in
		// parallel. Under load WP-Cron can spawn overlapping processes; without
		// this, several runs would read the shared queue and stampede a weak
		// origin. If a run is already in progress, reschedule and bail.
		if ( get_transient( self::WARMUP_LOCK ) ) {
			if ( ! wp_next_scheduled( 'flavor_edge_warmup_process' ) ) {
				wp_schedule_single_event( time() + 10, 'flavor_edge_warmup_process' );
			}
			return;
		}

		$urls = get_option( 'flavor_edge_warmup_queue', array() );

		if ( empty( $urls ) ) {
			return;
		}

		// SSRF protection: only warm URLs from our own host.
		$own_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$urls     = array_filter( $urls, function ( $url ) use ( $own_host ) {
			return wp_parse_url( $url, PHP_URL_HOST ) === $own_host;
		} );

		if ( empty( $urls ) ) {
			delete_option( 'flavor_edge_warmup_queue' );
			return;
		}

		// Acquire the lock for the duration of this run (auto-expires as a
		// safety net if the process dies mid-way).
		set_transient( self::WARMUP_LOCK, 1, self::WARMUP_LOCK_TTL );

		// Pacing is filterable so a delicate origin can be warmed more gently
		// (smaller batches, longer pauses) without touching code.
		$batch_size = (int) apply_filters( 'flavor_edge_warmup_batch_size', 5 );
		$batch_size = max( 1, $batch_size );
		$batch_pause_us = (int) apply_filters( 'flavor_edge_warmup_batch_pause_us', 500000 ); // 0.5s default.
		$req_timeout    = (int) apply_filters( 'flavor_edge_warmup_request_timeout', 3 );

		$batches = array_chunk( $urls, $batch_size );

		try {
			foreach ( $batches as $batch ) {
				foreach ( $batch as $url ) {
					wp_remote_get( $url, array(
						'timeout'     => $req_timeout,
						'httpversion' => '1.1', // WP defaults to HTTP/1.0, which the edge can drop over IPv4.
						'blocking'    => true,
						'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
						'redirection' => 0,
						'headers'     => array(
							'Accept' => 'text/html,image/webp,*/*',
						),
					) );
				}

				// Pause between batches to avoid a request stampede on origin.
				if ( count( $batches ) > 1 && $batch_pause_us > 0 ) {
					usleep( $batch_pause_us );
				}
			}
		} finally {
			// Always clear the queue and release the lock, even on error.
			delete_option( 'flavor_edge_warmup_queue' );
			delete_transient( self::WARMUP_LOCK );
		}
	}

	// -------------------------------------------------------------------------
	// Public API for manual purge.
	// -------------------------------------------------------------------------

	/**
	 * Manually add tags to the purge queue.
	 *
	 * @param array $tags Tags to add.
	 */
	public static function queue_tags( $tags ) {
		self::$pending_tags = array_merge( self::$pending_tags, (array) $tags );
	}

	/**
	 * Manually add URLs to the purge queue.
	 *
	 * @param array $urls URLs to add.
	 */
	public static function queue_urls( $urls ) {
		self::$pending_urls = array_merge( self::$pending_urls, (array) $urls );
	}

	/**
	 * Request a full site purge.
	 */
	public static function request_full_purge() {
		self::$full_purge_pending = true;
	}
}
