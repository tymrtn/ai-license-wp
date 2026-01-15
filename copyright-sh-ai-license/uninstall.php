<?php
/**
 * Uninstall script for Copyright.sh AI License plugin.
 *
 * @package CSH_AI_Licensing
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Option keys used by the plugin.
$options = [
	'csh_ai_license_global_settings',
	'csh_ai_license_account_status',
	'csh_ai_license_robots_signature',
	'csh_ai_license_robots_confirmation',
];

foreach ( $options as $key ) {
	delete_option( $key );
}

// Only delete robots.txt if it still matches our signature to avoid clobbering manual edits.
$signature = get_option( 'csh_ai_license_robots_signature' );
if ( $signature ) {
	$path = trailingslashit( ABSPATH ) . 'robots.txt';
	if ( file_exists( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_exists
		$contents = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false !== $contents && hash_equals( $signature, md5( $contents ) ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
}
