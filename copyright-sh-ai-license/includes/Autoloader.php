<?php
/**
 * Simple PSR-4 autoloader for the plugin.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin autoloader.
 */
final class Autoloader {

	/**
	 * Base namespace for the plugin.
	 *
	 * @var string
	 */
	private const BASE_NAMESPACE = 'CSH\\AI_License\\';

	/**
	 * Register the autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( [ self::class, 'autoload' ] );
	}

	/**
	 * Autoload callback.
	 *
	 * @param string $class FQCN.
	 */
	private static function autoload( string $class ): void {
		if ( strpos( $class, self::BASE_NAMESPACE ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( self::BASE_NAMESPACE ) );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$path     = CSH_AI_LICENSE_DIR . 'includes/' . $relative . '.php';

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}
