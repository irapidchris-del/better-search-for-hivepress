<?php
/**
 * Uninstalls the plugin.
 *
 * Unusually for one of ours, this file needs no "delete all data" setting and
 * deletes unconditionally, because nothing it removes is the owner's to lose.
 * Everything this plugin stores is a derived copy: the searchable copies are
 * rebuilt from each listing's and vendor's own fields, and the stored progress
 * marker only records how far that rebuild has got. Term matching reads the
 * taxonomies live and stores nothing at all. Reinstalling regenerates the lot on
 * the next few admin loads, so there is no configuration to preserve and no
 * reason to leave orphaned rows behind.
 *
 * @package Extended_Search_For_HivePress
 */

// Exit if uninstall is not called from WordPress.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Backfill progress marker, and the two options it replaced in 1.5.0.
delete_option( 'hpse_index_state' );
delete_option( 'hpse_backfill_done' );
delete_option( 'hpse_backfill_offset' );

// The updater's cached release lookup.
delete_site_transient( 'hpse_github_release' );

/*
 * The updater's other two site transients and its background job, which used to be left behind.
 *
 * All three are regenerable runtime state belonging to the update check, not the owner's
 * configuration, so they go unconditionally alongside the release cache above. Core's daily sweep
 * clears expired site transients within about a day on single-site, which is why this read as
 * harmless; on multisite they live in wp_sitemeta and are only purged when something asks for
 * them, so on a network they simply stay. The scheduled refresh is worse than debris: it is a job
 * whose callback no longer exists.
 *
 * Unscheduled from both places it can be, because the refresh is queued through HivePress's
 * scheduler (Action Scheduler) when HivePress is present and through WP-Cron when it is not.
 */
delete_site_transient( 'hpse_github_release_reason' );
delete_site_transient( 'hpse_github_release_rate_limit' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'hpse_github_release_refresh', [], 'hivepress' );
	as_unschedule_all_actions( 'hpse_github_release_refresh' );
}

wp_clear_scheduled_hook( 'hpse_github_release_refresh' );

/*
 * The searchable copies, including the tier-only key used up to 1.4.1.
 *
 * delete_metadata() with $delete_all removes the key across every post in one
 * query, which is what this needs - there is no per-listing decision to make.
 */
delete_metadata( 'post', 0, '_hpse_index', '', true );
delete_metadata( 'post', 0, '_hpse_vendor_index', '', true );
delete_metadata( 'post', 0, '_hpse_tier_index', '', true );
