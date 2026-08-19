<?php
/**
 * Plugin Name: Better Search for HivePress
 * Plugin URI:  https://github.com/irapidchris-del/better-search-for-hivepress
 * Description: Extends the HivePress keyword search to also match pricing tier names/descriptions and listing category names/descriptions, including parent categories cascading to their sub-categories. Tier matching additionally needs HivePress Marketplace with "Allow sellers to set pricing tiers" switched on.
 * Version:     1.4.0
 * Author:      ChrisB @ HivePress Community
 * Author URI:  https://community.hivepress.io/u/chrisb/summary
 * Text Domain: better-search-for-hivepress
 * Domain Path: /languages/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: hivepress
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:  https://github.com/irapidchris-del/better-search-for-hivepress
 *
 * What it adds to the keyword search, alongside the default title / excerpt / content:
 *   - Pricing tier names and descriptions (via a hidden plaintext copy, because
 *     the raw hp_price_tiers meta is serialized).
 *   - Listing category names and descriptions, read live from the taxonomy.
 *   - Category hierarchy: a keyword matching a parent category also surfaces
 *     listings filed under its sub-categories.
 *
 * @package Better_Search_For_HivePress
 */

defined( 'ABSPATH' ) || exit;

const HPSE_VERSION = '1.4.0';

/**
 * Main plugin file, for the updater and for plugin_basename() checks.
 */
define( 'HPSE_FILE', __FILE__ );

/**
 * The author's support page.
 *
 * One place, so the Plugins row and the View details popup can never drift apart.
 */
const HPSE_SUPPORT_URL = 'https://ko-fi.com/chrisbathivepresscommunity';

require_once __DIR__ . '/updater.php';

/**
 * Says so when HivePress is missing.
 *
 * Every hook this plugin uses is a HivePress one, so without HivePress it does
 * nothing at all - and does it silently, which looks like a broken plugin rather
 * than a missing dependency. WordPress 6.5 and above blocks activation outright
 * via the Requires Plugins header; this notice covers older sites.
 */
add_action(
	'admin_notices',
	function () {
		if ( function_exists( 'hivepress' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error is-dismissible"><p>'
			. esc_html__( 'Better Search for HivePress needs the HivePress plugin to be installed and activated. Until then it does nothing.', 'better-search-for-hivepress' )
			. '</p></div>';
	}
);

/**
 * Meta key holding the searchable copy of the tier text.
 */
const HPSE_TIER_META = '_hpse_tier_index';

/**
 * The listing category taxonomy (HivePress prefixes taxonomies with hp_).
 */
const HPSE_CATEGORY_TAX = 'hp_listing_category';

/*
--------------------------------------------------------------------------
PART 1 - Keep the searchable copy of tier text up to date.
Tier data lives inside the listing (serialized), so it can only change when
the listing is saved. A precomputed plaintext copy is therefore never stale.
--------------------------------------------------------------------------
*/

/**
 * Builds a plaintext string of names + descriptions from a tiers array.
 *
 * @param mixed $tiers The hp_price_tiers value (array of tier rows).
 * @return string
 */
function hpse_tier_index_from_tiers( $tiers ) {
	if ( ! is_array( $tiers ) ) {
		return '';
	}

	$parts = [];

	foreach ( $tiers as $tier ) {
		if ( ! empty( $tier['name'] ) ) {
			$parts[] = $tier['name'];
		}

		if ( ! empty( $tier['description'] ) ) {
			$parts[] = $tier['description'];
		}
	}

	return trim( wp_strip_all_tags( implode( ' ', $parts ) ) );
}

/**
 * Writes (or clears) the searchable copy for a single listing ID.
 *
 * @param int   $listing_id Listing post ID.
 * @param mixed $tiers      The hp_price_tiers value.
 */
function hpse_store_tier_index( $listing_id, $tiers ) {
	$index = hpse_tier_index_from_tiers( $tiers );

	if ( '' === $index ) {
		delete_post_meta( $listing_id, HPSE_TIER_META );
	} else {
		update_post_meta( $listing_id, HPSE_TIER_META, $index );
	}
}

/**
 * Rebuilds the searchable copy whenever a listing is created or updated.
 *
 * @param int    $listing_id Listing ID.
 * @param object $listing    Listing model object.
 */
function hpse_refresh_tier_index( $listing_id, $listing ) {
	if ( ! get_option( 'hp_listing_allow_price_tiers' ) ) {
		return;
	}

	$tiers = null;

	if ( is_object( $listing ) && method_exists( $listing, 'get_price_tiers' ) ) {
		$tiers = $listing->get_price_tiers();
	}

	if ( ! is_array( $tiers ) ) {
		$tiers = get_post_meta( $listing_id, 'hp_price_tiers', true );
	}

	hpse_store_tier_index( $listing_id, $tiers );
}
add_action( 'hivepress/v1/models/listing/create', 'hpse_refresh_tier_index', 100, 2 );
add_action( 'hivepress/v1/models/listing/update', 'hpse_refresh_tier_index', 100, 2 );

/*
--------------------------------------------------------------------------
PART 2 - One-time backfill for existing listings (100 per admin load).
--------------------------------------------------------------------------
*/

add_action(
	'admin_init',
	function () {
		if ( get_option( 'hpse_backfill_done' ) ) {
			return;
		}

		$batch  = 100;
		$offset = (int) get_option( 'hpse_backfill_offset', 0 );

		$ids = get_posts(
			[
				'post_type'        => 'hp_listing',
				'post_status'      => 'any',
				'posts_per_page'   => $batch,
				'offset'           => $offset,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			]
		);

		if ( empty( $ids ) ) {
			update_option( 'hpse_backfill_done', 1 );
			delete_option( 'hpse_backfill_offset' );
			return;
		}

		foreach ( $ids as $id ) {
			hpse_store_tier_index( $id, get_post_meta( $id, 'hp_price_tiers', true ) );
		}

		update_option( 'hpse_backfill_offset', $offset + $batch );
	}
);

/*
--------------------------------------------------------------------------
PART 3 - Category helpers (read live, so never stale).
The category tree is small, so this is cheap and cached per request.
--------------------------------------------------------------------------
*/

/**
 * Loads all listing category terms, keyed by term_id.
 *
 * @return array<int,array{name:string,description:string,tt_id:int,parent:int}>
 */
function hpse_get_category_terms() {
	static $terms = null;

	if ( null !== $terms ) {
		return $terms;
	}

	$terms = [];

	$raw = get_terms(
		[
			'taxonomy'   => HPSE_CATEGORY_TAX,
			'hide_empty' => false,
		]
	);

	if ( is_wp_error( $raw ) || empty( $raw ) ) {
		return $terms;
	}

	foreach ( $raw as $term ) {
		$terms[ (int) $term->term_id ] = [
			'name'        => (string) $term->name,
			'description' => (string) $term->description,
			'tt_id'       => (int) $term->term_taxonomy_id,
			'parent'      => (int) $term->parent,
		];
	}

	return $terms;
}

/**
 * Builds a parent term_id => [child term_ids] map.
 *
 * @return array<int,int[]>
 */
function hpse_get_children_map() {
	static $map = null;

	if ( null !== $map ) {
		return $map;
	}

	$map = [];

	foreach ( hpse_get_category_terms() as $id => $term ) {
		$map[ $term['parent'] ][] = $id;
	}

	return $map;
}

/**
 * Returns a term ID plus all of its descendant term IDs.
 *
 * @param int $term_id Starting term ID.
 * @return int[]
 */
function hpse_term_with_descendants( $term_id ) {
	$map   = hpse_get_children_map();
	$found = [];
	$stack = [ (int) $term_id ];

	while ( $stack ) {
		$id = array_pop( $stack );

		if ( isset( $found[ $id ] ) ) {
			continue;
		}

		$found[ $id ] = true;

		if ( ! empty( $map[ $id ] ) ) {
			foreach ( $map[ $id ] as $child ) {
				$stack[] = $child;
			}
		}
	}

	return array_keys( $found );
}

/**
 * For a single keyword, returns the term_taxonomy_ids of every category whose
 * name or description contains it, expanded to include their sub-categories.
 *
 * @param string $keyword Raw search keyword.
 * @return int[]
 */
function hpse_category_tt_ids_for_keyword( $keyword ) {
	static $cache = [];

	$keyword = (string) $keyword;
	$key     = strtolower( $keyword );

	if ( '' === $key ) {
		return [];
	}

	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$terms  = hpse_get_category_terms();
	$tt_ids = [];

	foreach ( $terms as $id => $term ) {
		$name_hit = ( '' !== $term['name'] && false !== stripos( $term['name'], $keyword ) );
		$desc_hit = ( '' !== $term['description'] && false !== stripos( $term['description'], $keyword ) );

		if ( $name_hit || $desc_hit ) {
			foreach ( hpse_term_with_descendants( $id ) as $descendant_id ) {
				if ( isset( $terms[ $descendant_id ] ) ) {
					$tt_ids[ $terms[ $descendant_id ]['tt_id'] ] = true;
				}
			}
		}
	}

	$cache[ $key ] = array_map( 'intval', array_keys( $tt_ids ) );

	return $cache[ $key ];
}

/*
--------------------------------------------------------------------------
PART 4 - Teach the keyword search to read tier text and category data.
--------------------------------------------------------------------------
*/

/**
 * Is this the front-end listing keyword search we want to extend?
 *
 * @param \WP_Query $query The query passed to the search filter.
 * @return bool
 */
function hpse_is_listing_keyword_search( $query ) {
	// Allow front-end page loads and front-end AJAX; skip the wp-admin list table.
	if ( is_admin() && ! wp_doing_ajax() ) {
		return false;
	}

	if ( '' === (string) $query->get( 's' ) ) {
		return false;
	}

	return in_array( 'hp_listing', (array) $query->get( 'post_type' ), true );
}

/**
 * Returns the inclusion keywords WordPress used, in the same order it built the
 * per-keyword search groups. Exclusion terms ("-word") are dropped so the index
 * lines up with the LIKE groups (WordPress writes exclusions as NOT LIKE).
 *
 * @param \WP_Query $query Query object.
 * @return string[]
 */
function hpse_inclusion_terms( $query ) {
	$raw = isset( $query->query_vars['search_terms'] ) ? (array) $query->query_vars['search_terms'] : [];

	if ( empty( $raw ) ) {
		$s = trim( (string) $query->get( 's' ) );
		return '' === $s ? [] : [ $s ];
	}

	/** This mirrors WordPress core's own exclusion-prefix handling. */
	$prefix = apply_filters( 'wp_query_search_exclusion_prefix', '-' );

	$terms = [];

	foreach ( $raw as $term ) {
		// Match core's check exactly: the prefix may be multi-character if filtered.
		if ( '' !== (string) $prefix && is_string( $term ) && 0 === strpos( $term, $prefix ) ) {
			continue;
		}

		$terms[] = $term;
	}

	return $terms;
}

/**
 * Add tier text and category matches to each keyword's search group.
 *
 * WordPress builds the search as one OR group per inclusion keyword, each
 * containing a post_content clause (used here as the injection anchor):
 *   ((post_title LIKE '%x%') OR (post_excerpt LIKE '%x%') OR (post_content LIKE '%x%'))
 * Note: if a plugin removes post_content via the 'post_search_columns' filter
 * (WP 6.2+), the anchor is absent and these additions silently do not apply.
 * We append, to each group:
 *   - a tier check, reusing the exact term literal WordPress already escaped;
 *   - a category check, where the matching category IDs (including sub-categories)
 *     were resolved in PHP for that keyword.
 * Correlated EXISTS is used instead of table joins so a listing in several
 * categories never produces duplicate result rows. Only inclusion groups (LIKE)
 * are targeted, never exclusion groups (NOT LIKE), so negative search still works.
 *
 * @param string    $search Search SQL.
 * @param \WP_Query $query  Query object.
 * @return string
 */
add_filter(
	'posts_search',
	function ( $search, $query ) {
		global $wpdb;

		if ( '' === $search || ! hpse_is_listing_keyword_search( $query ) ) {
			return $search;
		}

		$terms     = hpse_inclusion_terms( $query );
		$tier_meta = esc_sql( HPSE_TIER_META );
		$posts     = preg_quote( $wpdb->posts, '/' );
		$pattern   = '/\(\s*' . $posts . '\.post_content\s+LIKE\s+(\'(?:[^\'\\\\]|\\\\.)*\')\s*\)/';

		$index = 0;

		$search = preg_replace_callback(
			$pattern,
			function ( $m ) use ( &$index, $terms, $wpdb, $tier_meta ) {

				// $m[1] is the exact quoted term literal WordPress already built, e.g. '%hair%'.
				$literal = $m[1];

				// Pricing tier names + descriptions (from the searchable copy).
				$extra = " OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} hpse_m"
					. " WHERE hpse_m.post_id = {$wpdb->posts}.ID"
					. " AND hpse_m.meta_key = '{$tier_meta}'"
					. " AND hpse_m.meta_value LIKE {$literal})";

				// Category names + descriptions, expanded to sub-categories (resolved in PHP).
				$keyword = isset( $terms[ $index ] ) ? $terms[ $index ] : null;
				$index++;

				if ( null !== $keyword ) {
					$tt_ids = hpse_category_tt_ids_for_keyword( $keyword );

					if ( $tt_ids ) {
						$in     = implode( ',', array_map( 'intval', $tt_ids ) );
						$extra .= " OR EXISTS (SELECT 1 FROM {$wpdb->term_relationships} hpse_tr"
							. " WHERE hpse_tr.object_id = {$wpdb->posts}.ID"
							. " AND hpse_tr.term_taxonomy_id IN ({$in}))";
					}
				}

				return $m[0] . $extra;
			},
			$search
		);

		return $search;
	},
	10,
	2
);

/**
 * Adds the house "Donate" link to this plugin's row on the Plugins screen.
 *
 * WordPress fires plugin_row_meta for EVERY plugin on the screen, so without the basename
 * test the link would appear on every row on the site. The markup is copied verbatim from
 * the house spec in `releasing.md` rather than composed here: every plugin's row has to look
 * identical and sessions have drifted before. The label is exactly "Donate", matching the
 * wording WordPress itself uses in the details popup, and the icon is a Dashicon rather than
 * Font Awesome because Dashicons is the admin's own font and is always loaded there.
 * WordPress joins row-meta items with " | " itself, so this returns a bare anchor.
 *
 * @param array<string> $meta        Row meta links.
 * @param string        $plugin_file Plugin file the row belongs to.
 * @return array<string>
 */
function hpse_add_row_meta( $meta, $plugin_file ) {
	if ( plugin_basename( __FILE__ ) === $plugin_file ) {
		$meta[] = '<a href="' . esc_url( HPSE_SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">'
			. '<span class="dashicons dashicons-star-filled" style="font-size:14px;line-height:1.3;"></span> '
			. esc_html__( 'Donate', 'better-search-for-hivepress' )
			. '</a>';
	}

	return $meta;
}

add_filter( 'plugin_row_meta', 'hpse_add_row_meta', 10, 2 );
