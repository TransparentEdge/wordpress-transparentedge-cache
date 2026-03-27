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
			self::queue_post_tags( $post->ID, $post );
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

		self::queue_post_tags( $post_id, $post );
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
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	private static function queue_post_tags( $post_id, $post ) {
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
		self::$warmup_urls[] = get_permalink( $post_id );
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
			self::$warmup_urls        = array();

			do_action( 'flavor_edge_after_purge_all', $result );
			return;
		}

		// Tag-based purge.
		$tags = array_unique( array_filter( self::$pending_tags ) );
		if ( ! empty( $tags ) ) {
			$soft   = 'soft' === TE_Settings::get( 'invalidation_method' );
			$result = TE_Api::purge_tags( $tags, $soft );

			do_action( 'flavor_edge_after_tag_purge', $result, $tags );

			// Warm-up: resolve purged tags back to URLs and warm Varnish.
			if ( TE_Settings::get( 'refetch_enabled' ) && ! empty( self::$warmup_urls ) ) {
				self::do_warmup();
			}
		}

		// URL-based purge (fallback or explicit).
		$urls = array_unique( array_filter( self::$pending_urls ) );
		if ( ! empty( $urls ) ) {
			$soft    = 'soft' === TE_Settings::get( 'invalidation_method' );
			$refetch = TE_Settings::get( 'refetch_enabled' );
			$result  = TE_Api::purge_urls( $urls, $soft, $refetch );

			do_action( 'flavor_edge_after_url_purge', $result, $urls );
		}

		// Reset queues.
		self::$pending_tags = array();
		self::$pending_urls = array();
		self::$warmup_urls  = array();
	}

	/**
	 * Warm up Varnish cache for purged URLs.
	 *
	 * Sends GET requests to the resolved URLs so Varnish fetches a fresh copy
	 * from origin and caches it. Uses non-blocking requests for speed, with a
	 * safety limit on the number of URLs.
	 *
	 * Rate limiting:
	 * - Max 20 URLs per event (configurable via filter).
	 * - Non-blocking requests (fire-and-forget via WP cron).
	 * - 1 second delay between batches of 5 URLs.
	 */
	private static function do_warmup() {
		$urls = array_unique( array_filter( self::$warmup_urls, function ( $url ) {
			return filter_var( $url, FILTER_VALIDATE_URL );
		} ) );

		if ( empty( $urls ) ) {
			return;
		}

		$max = apply_filters( 'flavor_edge_max_warmup_urls', self::MAX_WARMUP_URLS );
		$urls = array_slice( array_values( $urls ), 0, $max );

		// Schedule the warm-up to run just after this request completes.
		// This avoids adding latency to the user's save/publish action.
		$existing = get_option( 'flavor_edge_warmup_queue', array() );
		$merged   = array_unique( array_merge( $existing, $urls ) );
		$merged   = array_slice( $merged, 0, $max );
		update_option( 'flavor_edge_warmup_queue', $merged, false );

		if ( ! wp_next_scheduled( 'flavor_edge_warmup_process' ) ) {
			wp_schedule_single_event( time() + 1, 'flavor_edge_warmup_process' );
		}
	}

	/**
	 * Process the warm-up queue. Called via cron or directly.
	 */
	public static function process_warmup_queue() {
		$urls = get_option( 'flavor_edge_warmup_queue', array() );

		if ( empty( $urls ) ) {
			return;
		}

		$batch_size = 5;
		$batches    = array_chunk( $urls, $batch_size );

		foreach ( $batches as $batch ) {
			foreach ( $batch as $url ) {
				wp_remote_get( $url, array(
					'timeout'     => 3,
					'blocking'    => true,
					'user-agent'  => 'Flavor-Edge-Warmup/1.0',
					'redirection' => 0,
					'headers'     => array(
						'Accept' => 'text/html,image/webp,*/*',
					),
				) );
			}

			// Small delay between batches to avoid stampede.
			if ( count( $batches ) > 1 ) {
				usleep( 500000 ); // 0.5 seconds.
			}
		}

		// Clear the queue.
		delete_option( 'flavor_edge_warmup_queue' );
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
