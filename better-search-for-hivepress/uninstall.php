<?php
/**
 * Uninstalls the plugin.
 *
 * Unusually for one of ours, this file needs no "delete all data" setting and
 * deletes unconditionally, because nothing it removes is the owner's to lose.
 * Everything this plugin stores is a derived copy: the searchable tier index is
 * rebuilt from each listing's own hp_price_tiers value, and the two backfill
 * options only record how far that rebuild has got. Category matching reads the
 * taxonomy live and stores nothing at all. Reinstalling regenerates the lot on
 * the next few admin loads, so there is no configuration to preserve and no
 * reason to leave orphaned rows behind.
 *
 * @package Better_Search_For_HivePress
 */

// Exit if uninstall is not called from WordPress.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Backfill progress markers.
delete_option( 'hpse_backfill_done' );
delete_option( 'hpse_backfill_offset' );

// The updater's cached release lookup.
delete_site_transient( 'hpse_github_release' );

/*
 * The searchable copy of each listing's tier text.
 *
 * delete_metadata() with $delete_all removes the key across every post in one
 * query, which is what this needs - there is no per-listing decision to make.
 */
delete_metadata( 'post', 0, '_hpse_tier_index', '', true );
