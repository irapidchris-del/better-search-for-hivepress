<?php
/**
 * GitHub release updater.
 *
 * The plugin is distributed via GitHub releases rather than wp.org, so update checks go through the
 * native `update_plugins_{$hostname}` API introduced in WordPress 5.8, keyed off the Update URI
 * header in the main plugin file. The update package is the release asset named `*.zip`, which must
 * contain a single `better-search-for-hivepress` directory. No third-party library is involved.
 *
 * @package Extended_Search_For_HivePress
 */

namespace BetterSearch\Updater;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

const UPDATE_REPO = 'irapidchris-del/better-search-for-hivepress';

const UPDATE_SLUG = 'better-search-for-hivepress';

const UPDATE_CACHE_KEY = 'hpse_github_release';

/**
 * Gets the installed plugin version.
 *
 * @return string
 */
function get_version() {
	static $version = null;

	if ( null === $version ) {
		$data = get_file_data( HPSE_FILE, [ 'Version' => 'Version' ] );

		$version = $data['Version'];
	}

	return $version;
}

/**
 * Gets the latest GitHub release details, cached for 6 hours.
 *
 * @param bool $force Bypass the cache.
 * @return array<string, string>|null
 */
function get_latest_release( $force = false ) {
	$release = $force ? false : get_site_transient( UPDATE_CACHE_KEY );

	if ( ! is_array( $release ) ) {
		$release = fetch_latest_release();

		// Failures are cached briefly so the API is not queried repeatedly.
		set_site_transient( UPDATE_CACHE_KEY, $release, $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	}

	return $release ? $release : null;
}

/**
 * Fetches the latest release details from the GitHub API.
 *
 * Draft and pre-release entries are excluded by the endpoint itself, so publishing a pre-release
 * never triggers an update notice.
 *
 * @return array<string, string>
 */
function fetch_latest_release() {
	$response = wp_remote_get(
		'https://api.github.com/repos/' . UPDATE_REPO . '/releases/latest',
		[
			'timeout'    => 10,
			'headers'    => [ 'Accept' => 'application/vnd.github+json' ],

			// Our own User-Agent, because WordPress's default is "WordPress/{version}; {site url}"
			// (wp-includes/class-wp-http.php:211) and that puts the site's address and its exact
			// WordPress version into every release check. GitHub only requires that the header
			// identifies something, so this satisfies it while telling them nothing about the site.
			// The house rule for the updater is no site or user data in the request at all.
			'user-agent' => 'better-search-for-hivepress/' . HPSE_VERSION,
		]
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return [];
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $data ) ) {
		return [];
	}

	// The version is read from the release tag, with or without a "v" prefix.
	$version = ltrim( (string) ( isset( $data['tag_name'] ) ? $data['tag_name'] : '' ), 'vV' );

	if ( ! $version ) {
		return [];
	}

	// The update package is the first release asset named `*.zip`.
	$package = '';

	foreach ( (array) ( isset( $data['assets'] ) ? $data['assets'] : [] ) as $asset ) {
		$name = strtolower( (string) ( isset( $asset['name'] ) ? $asset['name'] : '' ) );

		if ( '.zip' === substr( $name, -4 ) && ! empty( $asset['browser_download_url'] ) ) {
			$package = (string) $asset['browser_download_url'];

			break;
		}
	}

	if ( ! $package ) {
		return [];
	}

	return [
		'version'   => $version,
		'package'   => $package,
		'url'       => (string) ( isset( $data['html_url'] ) ? $data['html_url'] : 'https://github.com/' . UPDATE_REPO ),
		'notes'     => (string) ( isset( $data['body'] ) ? $data['body'] : '' ),
		'published' => (string) ( isset( $data['published_at'] ) ? $data['published_at'] : '' ),
	];
}

/**
 * Provides the update details to the WordPress update system.
 *
 * WordPress matches the plugin to this filter via the Update URI header hostname and compares the
 * versions itself, filing the result under either the available updates or the up-to-date list.
 *
 * @param array<string, mixed>|false $update Update data.
 * @param array<string, string>      $plugin_data Plugin headers.
 * @param string                     $plugin_file Plugin basename.
 * @return array<string, mixed>|false
 */
function check_for_update( $update, $plugin_data, $plugin_file ) {
	if ( plugin_basename( HPSE_FILE ) !== $plugin_file ) {
		return $update;
	}

	$release = get_latest_release();

	if ( ! $release ) {
		return $update;
	}

	return [
		'id'      => 'https://github.com/' . UPDATE_REPO,
		'slug'    => UPDATE_SLUG,
		'plugin'  => $plugin_file,
		'version' => $release['version'],
		'url'     => $release['url'],
		'package' => $release['package'],
	];
}

add_filter( 'update_plugins_github.com', __NAMESPACE__ . '\\check_for_update', 10, 3 );

/**
 * Provides the plugin details for the update information popup.
 *
 * Without this the "View version x.x.x details" link on the Plugins screen would open an empty
 * modal, since the plugin is not on wp.org.
 *
 * @param object|array|false $result Result object.
 * @param string             $action API action.
 * @param object             $args API arguments.
 * @return object|array|false
 */
function get_plugin_information( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || ! is_object( $args ) || UPDATE_SLUG !== ( isset( $args->slug ) ? $args->slug : '' ) ) {
		return $result;
	}

	$release = get_latest_release();

	if ( ! $release ) {
		return $result;
	}

	$plugin_data = get_file_data(
		HPSE_FILE,
		[
			'Name'        => 'Plugin Name',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'RequiresWP'  => 'Requires at least',
			'RequiresPHP' => 'Requires PHP',
		]
	);

	return (object) [
		'name'          => $plugin_data['Name'],
		'slug'          => UPDATE_SLUG,
		'version'       => $release['version'],
		'author'        => '<a href="' . esc_url( $plugin_data['AuthorURI'] ) . '">' . esc_html( $plugin_data['Author'] ) . '</a>',
		'homepage'      => 'https://github.com/' . UPDATE_REPO,

		// The "View details" popup is the second of the exactly two Donate placements the house
		// spec allows (releasing.md); WordPress renders this as its own "Donate to this plugin"
		// link, so the wording is core's, not ours.
		'donate_link'   => 'https://ko-fi.com/chrisbathivepresscommunity',
		'requires'      => $plugin_data['RequiresWP'],
		'requires_php'  => $plugin_data['RequiresPHP'],
		'last_updated'  => $release['published'],
		'download_link' => $release['package'],
		'sections'      => [
			'description' => wpautop( esc_html( $plugin_data['Description'] ) ),
			'changelog'   => $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : '<p>' . esc_html__( 'See the GitHub releases page for the changelog.', 'better-search-for-hivepress' ) . '</p>',
		],
	];
}

add_filter( 'plugins_api', __NAMESPACE__ . '\\get_plugin_information', 10, 3 );

/**
 * Adds the manual update check link to the plugin row.
 *
 * @param array<string> $links Plugin action links.
 * @return array<string>
 */
function add_update_check_link( $links ) {
	if ( current_user_can( 'update_plugins' ) ) {
		$links[] = '<a href="' . esc_url( wp_nonce_url( self_admin_url( 'plugins.php?hpse_check_updates=1' ), 'hpse_check_updates' ) ) . '">' . esc_html__( 'Check for updates', 'better-search-for-hivepress' ) . '</a>';
	}

	return $links;
}

add_filter( 'plugin_action_links_' . plugin_basename( HPSE_FILE ), __NAMESPACE__ . '\\add_update_check_link' );
add_filter( 'network_admin_plugin_action_links_' . plugin_basename( HPSE_FILE ), __NAMESPACE__ . '\\add_update_check_link' );

/**
 * Handles the manual update check.
 *
 * Refreshes the cached release, re-runs the update check and redirects back to the Plugins screen
 * with the result.
 *
 * @return void
 */
function handle_update_check() {
	if ( ! isset( $_GET['hpse_check_updates'] ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	check_admin_referer( 'hpse_check_updates' );

	$release = get_latest_release( true );

	wp_clean_plugins_cache();
	wp_update_plugins();

	$status = 'none';

	if ( ! $release ) {
		$status = 'error';
	} elseif ( version_compare( $release['version'], get_version(), '>' ) ) {
		$status = 'available';
	}

	wp_safe_redirect( add_query_arg( 'hpse_checked', $status, self_admin_url( 'plugins.php' ) ) );

	exit;
}

add_action( 'admin_init', __NAMESPACE__ . '\\handle_update_check' );

/**
 * Shows the manual update check result.
 *
 * @return void
 */
function show_update_check_notice() {

	// This only decides which of three fixed sentences to print after the nonce-verified check
	// above redirected here. Nothing is processed and nothing changes state.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['hpse_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$status = sanitize_key( wp_unslash( $_GET['hpse_checked'] ) );

	if ( 'available' === $status ) {
		$release = get_latest_release();

		/* translators: %s: new version number. */
		$message = sprintf( __( 'A new version of Extended Search for HivePress (%s) is available.', 'better-search-for-hivepress' ), $release ? $release['version'] : '' );
		$class   = 'notice-success';
	} elseif ( 'none' === $status ) {
		$message = __( 'Extended Search for HivePress is up to date.', 'better-search-for-hivepress' );
		$class   = 'notice-success';
	} elseif ( 'error' === $status ) {
		$message = __( 'Could not reach GitHub to check for updates. Please try again later.', 'better-search-for-hivepress' );
		$class   = 'notice-error';
	} else {
		return;
	}

	echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}

add_action( 'admin_notices', __NAMESPACE__ . '\\show_update_check_notice' );
add_action( 'network_admin_notices', __NAMESPACE__ . '\\show_update_check_notice' );

/**
 * Keeps updates installing into the current plugin directory.
 *
 * The extracted release folder is renamed to match the directory the plugin is installed in, so an
 * update can never end up in a differently named folder even if the release zip is packaged
 * unexpectedly.
 *
 * @param string               $source Extracted update source.
 * @param string               $remote_source Remote source directory.
 * @param object               $upgrader Upgrader instance.
 * @param array<string, mixed> $hook_extra Extra hook arguments.
 * @return string|\WP_Error
 */
function fix_update_directory( $source, $remote_source, $upgrader, $hook_extra = [] ) {
	global $wp_filesystem;

	if ( plugin_basename( HPSE_FILE ) !== ( isset( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : '' ) || ! $wp_filesystem ) {
		return $source;
	}

	$directory = dirname( plugin_basename( HPSE_FILE ) );

	if ( '.' === $directory ) {
		return $source;
	}

	$target = trailingslashit( $remote_source ) . $directory . '/';

	if ( trailingslashit( $source ) === $target ) {
		return $source;
	}

	if ( ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $target ) ) ) {
		return new \WP_Error( 'hpse_rename_failed', __( 'Could not rename the update directory.', 'better-search-for-hivepress' ) );
	}

	return $target;
}

add_filter( 'upgrader_source_selection', __NAMESPACE__ . '\\fix_update_directory', 10, 4 );
