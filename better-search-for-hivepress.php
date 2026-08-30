<?php
/**
 * Plugin Name: Extended Search for HivePress
 * Plugin URI:  https://github.com/irapidchris-del/better-search-for-hivepress
 * Description: Extends the HivePress keyword search to also match custom attribute values, vendor profile text, pricing tier text, tags and listing category descriptions, including parent categories cascading to their sub-categories.
 * Version:     1.5.5
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
 *   - Every text attribute a vendor can fill in on the listing form, discovered
 *     from the site's own attributes rather than named in code, so it fits any
 *     site without configuration.
 *   - The vendor's profile text: the built-in description and every text
 *     attribute on the vendor form, reached through the listing's vendor.
 *   - Pricing tier names and descriptions (via a hidden plaintext copy, because
 *     the raw hp_price_tiers meta is serialized).
 *   - Listing category names and descriptions, plus tags and any option-based
 *     attribute, read live from the taxonomies.
 *   - Category hierarchy: a keyword matching a parent category also surfaces
 *     listings filed under its sub-categories.
 *
 * @package Extended_Search_For_HivePress
 */

defined( 'ABSPATH' ) || exit;

const HPSE_VERSION = '1.5.5';

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
			. esc_html__( 'Extended Search for HivePress needs the HivePress plugin to be installed and activated. Until then it does nothing.', 'better-search-for-hivepress' )
			. '</p></div>';
	}
);

/**
 * Meta key holding the searchable copy of a listing's own text.
 */
const HPSE_LISTING_INDEX_META = '_hpse_index';

/**
 * Meta key holding the searchable copy of a vendor's profile text.
 *
 * Kept on the vendor rather than folded into each listing so that editing a
 * profile is one write, not one write per listing the vendor owns.
 */
const HPSE_VENDOR_INDEX_META = '_hpse_vendor_index';

/**
 * The meta key used up to 1.4.1, when only tier text was indexed.
 *
 * Retained so the upgrade pass can clear it; nothing reads it.
 */
const HPSE_LEGACY_TIER_META = '_hpse_tier_index';

/**
 * The listing category taxonomy (HivePress prefixes taxonomies with hp_).
 */
const HPSE_CATEGORY_TAX = 'hp_listing_category';

/**
 * Bumped whenever the shape of the stored copies changes, or whenever a fix
 * means copies already stored may be wrong.
 *
 * A change here restarts the backfill, so existing sites pick the new shape up
 * without anyone having to re-save every listing. Version 3 changed no shape:
 * it re-runs the backfill because versions up to 1.5.3 stored an empty copy
 * for any record created in a single save (CSV imports, programmatic
 * creation), and those copies need filling in.
 */
const HPSE_INDEX_VERSION = 3;

/*
--------------------------------------------------------------------------
PART 1 - Work out which fields on THIS site carry searchable words.

Nothing below names a field. Every site defines its own attributes, so the
plugin asks HivePress what they are and picks the ones a vendor types words
into. That is what makes it portable: "Training & Experience" on one site and
"Ingredients" on another are handled by the same code.
--------------------------------------------------------------------------
*/

/**
 * The attribute field types whose values are worth searching.
 *
 * Text and textarea are the types people write sentences into. Numbers, dates,
 * times, currencies, coordinates, checkboxes and file uploads carry no keywords,
 * and emails, URLs and phone numbers are contact details rather than search
 * terms, so none of them are indexed by default. Option-based attributes are
 * handled separately, as taxonomies, further down.
 *
 * @return string[]
 */
function hpse_index_field_types() {
	/**
	 * Filters the attribute field types added to the keyword index.
	 *
	 * Add 'email', 'url', 'phone' or 'location' here to make those searchable too.
	 *
	 * @param string[] $types Field type names.
	 */
	return (array) apply_filters( 'hivepress/v1/extended_search/index_field_types', [ 'text', 'textarea' ] );
}

/**
 * Returns the meta keys holding text a vendor typed, for one model.
 *
 * Only editable attributes are considered: an attribute the vendor cannot fill
 * in is either derived (a region, a rating) or internal (a payment account ID),
 * and indexing those adds noise without adding a single real search result.
 *
 * @param string $model Model name, 'listing' or 'vendor'.
 * @return string[]
 */
function hpse_get_indexed_meta_keys( $model ) {
	static $cache = [];

	if ( isset( $cache[ $model ] ) ) {
		return $cache[ $model ];
	}

	$keys      = [];
	$component = function_exists( 'hivepress' ) ? hivepress()->attribute : null;

	if ( is_object( $component ) ) {
		$types = hpse_index_field_types();

		foreach ( $component->get_attributes( $model ) as $name => $attribute ) {

			// Option-based attributes are taxonomies, matched live instead.
			if ( empty( $attribute['editable'] ) || ! empty( $attribute['edit_field']['options'] ) ) {
				continue;
			}

			$type = isset( $attribute['edit_field']['type'] ) ? $attribute['edit_field']['type'] : '';

			if ( in_array( $type, $types, true ) ) {
				$keys[] = 'hp_' . $name;
			}
		}
	}

	$cache[ $model ] = $keys;

	return $keys;
}

/**
 * Returns the taxonomies whose term names and descriptions should match.
 *
 * The category taxonomy always counts. Beyond that, an option-based attribute
 * the vendor can edit becomes a taxonomy named hp_{model}_{attribute}, which
 * covers tags and every custom drop-down or checkbox list a site has added.
 * Attributes the vendor cannot edit are left out on purpose: the region tree is
 * the obvious one, where a term like "England" would match every listing on the
 * site and drown out the useful results.
 *
 * @return string[]
 */
function hpse_get_searchable_taxonomies() {
	static $taxonomies = null;

	if ( null !== $taxonomies ) {
		return $taxonomies;
	}

	$taxonomies = taxonomy_exists( HPSE_CATEGORY_TAX ) ? [ HPSE_CATEGORY_TAX ] : [];
	$component  = function_exists( 'hivepress' ) ? hivepress()->attribute : null;

	if ( is_object( $component ) ) {
		foreach ( $component->get_attributes( 'listing' ) as $name => $attribute ) {
			if ( empty( $attribute['editable'] ) || empty( $attribute['edit_field']['options'] ) ) {
				continue;
			}

			$taxonomy = 'hp_listing_' . $name;

			if ( taxonomy_exists( $taxonomy ) && ! in_array( $taxonomy, $taxonomies, true ) ) {
				$taxonomies[] = $taxonomy;
			}
		}
	}

	/**
	 * Filters the taxonomies matched by the keyword search.
	 *
	 * @param string[] $taxonomies Taxonomy names.
	 */
	$taxonomies = (array) apply_filters( 'hivepress/v1/extended_search/taxonomies', $taxonomies );

	return $taxonomies;
}

/*
--------------------------------------------------------------------------
PART 2 - Keep the searchable copies up to date.

Attribute values are ordinary post meta, so they could in principle be matched
with one EXISTS per attribute. Concatenating them into a single hidden copy
instead keeps the search to one extra condition per keyword however many
attributes a site defines, and lets tier text - which is serialized and cannot
be matched in SQL at all - ride along in the same place.
--------------------------------------------------------------------------
*/

/**
 * Is this string worth keeping in the index?
 *
 * A named callback rather than 'strlen' so the return type is a boolean, which
 * is what array_filter() is documented to expect.
 *
 * @param string $text Candidate text.
 * @return bool
 */
function hpse_is_not_empty( $text ) {
	return '' !== (string) $text;
}

/**
 * Flattens one stored value into plain searchable text.
 *
 * Entities are decoded because the stored copy is only ever compared against
 * what a visitor typed: someone searching for "Curls & Coils" types an
 * ampersand, and the raw meta holds "&amp;".
 *
 * @param mixed $value Stored value.
 * @return string
 */
function hpse_flatten_value( $value ) {
	if ( is_array( $value ) ) {
		$value = implode( ' ', array_filter( array_map( 'hpse_flatten_value', $value ), 'hpse_is_not_empty' ) );
	}

	if ( ! is_scalar( $value ) ) {
		return '';
	}

	$text = wp_strip_all_tags( (string) $value );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	return trim( preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Builds a plaintext string of names and descriptions from a tiers array.
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
		if ( ! is_array( $tier ) ) {
			continue;
		}

		if ( ! empty( $tier['name'] ) ) {
			$parts[] = $tier['name'];
		}

		if ( ! empty( $tier['description'] ) ) {
			$parts[] = $tier['description'];
		}
	}

	return hpse_flatten_value( implode( ' ', $parts ) );
}

/**
 * Writes, or clears, one searchable copy.
 *
 * @param int    $post_id  Post ID.
 * @param string $meta_key Meta key to store it under.
 * @param string $text     Text to store.
 */
function hpse_store_index( $post_id, $meta_key, $text ) {
	if ( '' === $text ) {
		delete_post_meta( $post_id, $meta_key );
	} else {
		update_post_meta( $post_id, $meta_key, $text );
	}
}

/**
 * Builds the searchable copy of one listing's own text.
 *
 * @param int $listing_id Listing ID.
 * @return string
 */
function hpse_build_listing_index( $listing_id ) {
	$parts = [];

	foreach ( hpse_get_indexed_meta_keys( 'listing' ) as $meta_key ) {
		$parts[] = hpse_flatten_value( get_post_meta( $listing_id, $meta_key, true ) );
	}

	// Pricing tiers are a Marketplace feature and only exist while it is switched on.
	if ( get_option( 'hp_listing_allow_price_tiers' ) ) {
		$parts[] = hpse_tier_index_from_tiers( get_post_meta( $listing_id, 'hp_price_tiers', true ) );
	}

	return trim( implode( ' ', array_filter( $parts, 'hpse_is_not_empty' ) ) );
}

/**
 * Builds the searchable copy of one vendor's profile text.
 *
 * The vendor name is deliberately left out: HivePress already appends it to
 * every listing's own excerpt, so indexing it again would only widen the row
 * without widening the results.
 *
 * @param int $vendor_id Vendor ID.
 * @return string
 */
function hpse_build_vendor_index( $vendor_id ) {
	$parts = [];

	$vendor = get_post( $vendor_id );

	// The built-in profile description, which some sites hide in favour of an attribute.
	if ( $vendor && 'hp_vendor' === $vendor->post_type ) {
		$parts[] = hpse_flatten_value( $vendor->post_content );
	}

	foreach ( hpse_get_indexed_meta_keys( 'vendor' ) as $meta_key ) {
		$parts[] = hpse_flatten_value( get_post_meta( $vendor_id, $meta_key, true ) );
	}

	return trim( implode( ' ', array_filter( $parts, 'hpse_is_not_empty' ) ) );
}

/**
 * Rebuilds one record's searchable copy from what is stored right now.
 *
 * @param string $model   Model name, 'listing' or 'vendor'.
 * @param int    $post_id Post ID.
 */
function hpse_write_index( $model, $post_id ) {
	if ( 'vendor' === $model ) {
		hpse_store_index( $post_id, HPSE_VENDOR_INDEX_META, hpse_build_vendor_index( $post_id ) );
	} else {
		hpse_store_index( $post_id, HPSE_LISTING_INDEX_META, hpse_build_listing_index( $post_id ) );
	}
}

/**
 * Queues one record so its searchable copy is rebuilt at the end of the request.
 *
 * The create and update actions cannot be acted on the moment they fire,
 * because HivePress fires them from save_post - that is, from INSIDE
 * wp_insert_post(), BEFORE its model writes the terms and the attribute meta.
 * A record created in a single save (a row of the HivePress CSV importer, any
 * programmatic wp_insert_post()) has no attribute meta at all at that instant,
 * and on the create path no update action ever follows, so reading the meta
 * straight away would store an empty copy that nothing later corrects - which
 * is exactly what versions up to 1.5.3 did. Waiting until shutdown reads the
 * meta after every write of the save has landed, whichever path saved the
 * record. Queueing by ID also de-duplicates: if anything saves the same record
 * more than once within one request, the copy is still built only once. (The
 * front-end submit flow is not that case - it creates the auto-draft in one
 * request and sends the attribute values in a later one, so each request
 * builds its own copy exactly as before.)
 *
 * @param string $model   Model name, 'listing' or 'vendor'.
 * @param int    $post_id Post ID.
 */
function hpse_queue_index_refresh( $model, $post_id ) {
	static $queue  = [];
	static $hooked = false;

	$post_id = (int) $post_id;

	if ( $post_id <= 0 ) {
		return;
	}

	// A save landing while shutdown is already running would arrive after the
	// queue has been flushed, so past that point the copy is written directly.
	if ( did_action( 'shutdown' ) ) {
		hpse_write_index( $model, $post_id );

		return;
	}

	// Keyed by ID, so a record saved twice in one request is indexed once.
	$queue[ $model ][ $post_id ] = true;

	if ( ! $hooked ) {
		$hooked = true;

		add_action(
			'shutdown',
			function () use ( &$queue ) {
				foreach ( $queue as $queued_model => $ids ) {
					foreach ( array_keys( $ids ) as $queued_id ) {
						hpse_write_index( $queued_model, $queued_id );
					}
				}
			}
		);
	}
}

/**
 * Rebuilds a listing's searchable copy whenever it is created or updated.
 *
 * @param int $listing_id Listing ID.
 */
function hpse_refresh_listing_index( $listing_id ) {
	hpse_queue_index_refresh( 'listing', $listing_id );
}
add_action( 'hivepress/v1/models/listing/create', 'hpse_refresh_listing_index', 100 );
add_action( 'hivepress/v1/models/listing/update', 'hpse_refresh_listing_index', 100 );

/**
 * Rebuilds a vendor's searchable copy whenever the profile is created or updated.
 *
 * @param int $vendor_id Vendor ID.
 */
function hpse_refresh_vendor_index( $vendor_id ) {
	hpse_queue_index_refresh( 'vendor', $vendor_id );
}
add_action( 'hivepress/v1/models/vendor/create', 'hpse_refresh_vendor_index', 100 );
add_action( 'hivepress/v1/models/vendor/update', 'hpse_refresh_vendor_index', 100 );

/*
--------------------------------------------------------------------------
PART 3 - Fill in the copies for listings and vendors that already exist.

A batch per admin page load, so a large site catches up over a few clicks
rather than timing out on one. The stored stage doubles as the upgrade path:
raising HPSE_INDEX_VERSION restarts it, which is how sites updating from an
earlier version gain the new fields without re-saving anything by hand.
--------------------------------------------------------------------------
*/

/**
 * Advances the backfill by one batch.
 */
function hpse_run_backfill() {
	$batch = 100;
	$state = get_option( 'hpse_index_state' );

	$version = is_array( $state ) && isset( $state['version'] ) ? (int) $state['version'] : 0;

	if ( ! is_array( $state ) || HPSE_INDEX_VERSION !== $version ) {
		$state = [
			'version' => HPSE_INDEX_VERSION,
			'stage'   => 'legacy',
			'offset'  => 0,
		];
	}

	if ( 'done' === $state['stage'] ) {
		return;
	}

	if ( 'legacy' === $state['stage'] ) {

		// Clear the 1.4.1 tier-only copies in one query; the new key replaces them.
		delete_metadata( 'post', 0, HPSE_LEGACY_TIER_META, '', true );

		$state['stage']  = 'listing';
		$state['offset'] = 0;

		update_option( 'hpse_index_state', $state, false );

		return;
	}

	$post_type = 'listing' === $state['stage'] ? 'hp_listing' : 'hp_vendor';

	$ids = get_posts(
		[
			'post_type'        => $post_type,
			'post_status'      => 'any',
			'posts_per_page'   => $batch,
			'offset'           => (int) $state['offset'],
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
			'no_found_rows'    => true,
		]
	);

	if ( empty( $ids ) ) {
		$state['stage']  = 'listing' === $state['stage'] ? 'vendor' : 'done';
		$state['offset'] = 0;

		update_option( 'hpse_index_state', $state, false );

		return;
	}

	foreach ( $ids as $id ) {
		if ( 'hp_listing' === $post_type ) {
			hpse_store_index( $id, HPSE_LISTING_INDEX_META, hpse_build_listing_index( $id ) );
		} else {
			hpse_store_index( $id, HPSE_VENDOR_INDEX_META, hpse_build_vendor_index( $id ) );
		}
	}

	$state['offset'] = (int) $state['offset'] + $batch;

	update_option( 'hpse_index_state', $state, false );
}
add_action( 'admin_init', 'hpse_run_backfill' );

/*
--------------------------------------------------------------------------
PART 4 - Match terms live, so a rename takes effect immediately.

Terms are matched with two term queries per keyword rather than by loading
every term into memory, because a busy site can carry thousands of tags.
--------------------------------------------------------------------------
*/

/**
 * For one keyword, returns the term_taxonomy_ids of every matching term.
 *
 * A hierarchical match is expanded downwards, so searching for a parent
 * category also finds the listings filed under its children.
 *
 * @param string $keyword Raw search keyword.
 * @return int[]
 */
function hpse_taxonomy_tt_ids_for_keyword( $keyword ) {
	static $cache = [];

	$keyword = trim( (string) $keyword );
	$key     = strtolower( $keyword );

	if ( '' === $key ) {
		return [];
	}

	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$taxonomies = hpse_get_searchable_taxonomies();

	if ( empty( $taxonomies ) ) {
		$cache[ $key ] = [];

		return [];
	}

	/*
	 * Capped so that one very broad keyword cannot build an IN() list thousands
	 * of terms long. Two hundred matching terms is already far more than any
	 * real keyword produces, and the listing still matches on its own text.
	 */
	$args = [
		'taxonomy'   => $taxonomies,
		'hide_empty' => false,
		'number'     => 200,
	];

	// name__like and description__like are ANDed together, so they need a query each.
	$matched = array_merge(
		(array) get_terms( array_merge( $args, [ 'name__like' => $keyword ] ) ),
		(array) get_terms( array_merge( $args, [ 'description__like' => $keyword ] ) )
	);

	$tt_ids = [];

	foreach ( $matched as $term ) {
		if ( ! $term instanceof WP_Term ) {
			continue;
		}

		$tt_ids[ (int) $term->term_taxonomy_id ] = true;

		if ( ! is_taxonomy_hierarchical( $term->taxonomy ) ) {
			continue;
		}

		$children = get_term_children( $term->term_id, $term->taxonomy );

		if ( ! is_array( $children ) ) {
			continue;
		}

		foreach ( $children as $child_id ) {
			$child = get_term( $child_id, $term->taxonomy );

			if ( $child instanceof WP_Term ) {
				$tt_ids[ (int) $child->term_taxonomy_id ] = true;
			}
		}
	}

	$cache[ $key ] = array_map( 'intval', array_keys( $tt_ids ) );

	return $cache[ $key ];
}

/*
--------------------------------------------------------------------------
PART 5 - Teach the keyword search to read all of the above.
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
 * Adds the extra matches to each keyword's search group.
 *
 * WordPress builds the search as one OR group per inclusion keyword, each
 * containing a post_content clause (used here as the injection anchor):
 *   ((post_title LIKE '%x%') OR (post_excerpt LIKE '%x%') OR (post_content LIKE '%x%'))
 * Note: if a plugin removes post_content via the 'post_search_columns' filter
 * (WP 6.2+), the anchor is absent and these additions silently do not apply.
 * We append, to each group:
 *   - the listing's own searchable copy, reusing the exact term literal
 *     WordPress already escaped;
 *   - the vendor's searchable copy, reached through post_parent, which is where
 *     HivePress stores a listing's vendor (models/class-listing.php:139);
 *   - a term check, where the matching term IDs were resolved in PHP.
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

		$terms        = hpse_inclusion_terms( $query );
		$listing_meta = esc_sql( HPSE_LISTING_INDEX_META );
		$vendor_meta  = esc_sql( HPSE_VENDOR_INDEX_META );
		$posts        = preg_quote( $wpdb->posts, '/' );
		$pattern      = '/\(\s*' . $posts . '\.post_content\s+LIKE\s+(\'(?:[^\'\\\\]|\\\\.)*\')\s*\)/';

		$index = 0;

		$search = preg_replace_callback(
			$pattern,
			function ( $m ) use ( &$index, $terms, $wpdb, $listing_meta, $vendor_meta ) {

				// $m[1] is the exact quoted term literal WordPress already built, e.g. '%hair%'.
				$literal = $m[1];

				// The listing's own attribute and pricing tier text.
				$extra = " OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} hpse_m"
					. " WHERE hpse_m.post_id = {$wpdb->posts}.ID"
					. " AND hpse_m.meta_key = '{$listing_meta}'"
					. " AND hpse_m.meta_value LIKE {$literal})";

				// The vendor's profile text, via the listing's vendor.
				$extra .= " OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} hpse_v"
					. " WHERE hpse_v.post_id = {$wpdb->posts}.post_parent"
					. " AND hpse_v.meta_key = '{$vendor_meta}'"
					. " AND hpse_v.meta_value LIKE {$literal})";

				// Categories, tags and option-based attributes (resolved in PHP).
				$keyword = isset( $terms[ $index ] ) ? $terms[ $index ] : null;
				$index++;

				if ( null !== $keyword ) {
					$tt_ids = hpse_taxonomy_tt_ids_for_keyword( $keyword );

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

/*
--------------------------------------------------------------------------
PART 6 - Let an admin start the indexing again from the Plugins screen.

Needed because the stored copies only rebuild when a listing is saved or when
HPSE_INDEX_VERSION changes. A site that adds field types through the
index_field_types filter would otherwise have no way of applying that to
listings that already exist, short of re-saving every one of them.
--------------------------------------------------------------------------
*/

/**
 * Adds the "Rebuild search index" link to this plugin's row on the Plugins screen.
 *
 * Sits alongside the updater's own "Check for updates" link, because both are
 * maintenance actions for this plugin rather than settings.
 *
 * @param array<string> $links Plugin action links.
 * @return array<string>
 */
function hpse_add_rebuild_link( $links ) {
	if ( current_user_can( 'activate_plugins' ) ) {
		$links[] = '<a href="' . esc_url( wp_nonce_url( self_admin_url( 'plugins.php?hpse_rebuild_index=1' ), 'hpse_rebuild_index' ) ) . '">'
			. esc_html__( 'Rebuild search index', 'better-search-for-hivepress' )
			. '</a>';
	}

	return $links;
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'hpse_add_rebuild_link' );
add_filter( 'network_admin_plugin_action_links_' . plugin_basename( __FILE__ ), 'hpse_add_rebuild_link' );

/**
 * Handles the rebuild request.
 *
 * Clearing the stored progress is all that is needed: the backfill on the next
 * admin page load sees no state, starts again from the beginning, and works
 * through everything a batch at a time exactly as it does on a fresh install.
 *
 * @return void
 */
function hpse_handle_rebuild() {
	if ( ! isset( $_GET['hpse_rebuild_index'] ) ) {
		return;
	}

	$network = is_multisite() && is_network_admin();

	if ( ! current_user_can( $network ? 'manage_network_plugins' : 'activate_plugins' ) ) {
		return;
	}

	check_admin_referer( 'hpse_rebuild_index' );

	$sites = 1;

	if ( $network ) {
		/*
		 * The index state is a per-site option, so from the network screen a plain
		 * delete_option() cleared the MAIN site and nothing else, while the success notice said
		 * the whole network was rebuilding. Worse, the link is only reachable from here when the
		 * plugin is network-activated, in which case it does not appear on any individual site's
		 * Plugins screen at all - so on those installs every site but one had no way to rebuild
		 * and no way to find that out.
		 *
		 * Every site is reset, one cheap option delete each. It is a deliberate, rare click, and
		 * the notice reports the number so a large network can see what it did.
		 */
		$sites = 0;

		foreach ( get_sites(
			[
				'fields' => 'ids',
				'number' => 0,
			]
		) as $site_id ) {
			switch_to_blog( (int) $site_id );

			delete_option( 'hpse_index_state' );

			restore_current_blog();

			++$sites;
		}
	} else {
		delete_option( 'hpse_index_state' );
	}

	wp_safe_redirect(
		add_query_arg(
			[
				'hpse_rebuilt' => '1',
				'hpse_sites'   => (int) $sites,
			],
			self_admin_url( 'plugins.php' )
		)
	);

	exit;
}

add_action( 'admin_init', 'hpse_handle_rebuild' );

/**
 * Says that the rebuild has started.
 *
 * @return void
 */
function hpse_show_rebuild_notice() {

	// This only decides which of two fixed sentences to print after the nonce-verified handler
	// above redirected here. Nothing is processed and nothing changes state.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['hpse_rebuilt'] ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$sites = isset( $_GET['hpse_sites'] ) ? absint( $_GET['hpse_sites'] ) : 1;

	// The count is reported rather than implied, because the network version of this used to claim
	// something it had not done.
	// Escaped once, on output below. Translated strings are not pre-escaped here: escaping twice
	// happens to be harmless because esc_html() does not double-encode, but it hides where the
	// escaping decision was made.
	$message = $sites > 1
		? sprintf(
			/* translators: %s: number of sites. */
			__( 'Extended Search for HivePress is rebuilding its search index on %s sites. Each site works through 100 listings and profiles per admin page load, so it finishes over the next few page loads. Searching keeps working while it runs.', 'better-search-for-hivepress' ),
			number_format_i18n( $sites )
		)
		: __( 'Extended Search for HivePress is rebuilding its search index. It works through 100 listings and profiles per admin page load, so it finishes over the next few page loads. Searching keeps working while it runs.', 'better-search-for-hivepress' );

	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}

add_action( 'admin_notices', 'hpse_show_rebuild_notice' );
add_action( 'network_admin_notices', 'hpse_show_rebuild_notice' );

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
