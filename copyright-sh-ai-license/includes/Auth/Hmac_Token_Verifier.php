<?php
/**
 * HMAC license token verification for URL-based licensing.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Auth;

use CSH\AI_License\Blocking\Request_Context;
use CSH\AI_License\Settings\Options_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Validates HMAC-SHA256 license tokens from URL parameters.
 *
 * Format: ?ai-license=12345-abc123def456
 * Parts: license_version_id-license_sig
 */
class Hmac_Token_Verifier {

	/**
	 * @var Options_Repository
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param Options_Repository $options Options repository.
	 */
	public function __construct( Options_Repository $options ) {
		$this->options = $options;
	}

	/**
	 * Verify HMAC license token from URL parameter.
	 *
	 * @param string          $token   Token value from ai-license parameter.
	 * @param Request_Context $context Request context.
	 * @return array {
	 *   @type bool   $valid  Whether valid.
	 *   @type string $error  Error code when invalid.
	 *   @type array  $data   Token data when valid.
	 * }
	 */
	public function verify( string $token, Request_Context $context ): array {
		$token = trim( $token );

		if ( '' === $token ) {
			return $this->invalid( 'empty_token' );
		}

		// Expected format: license_version_id-license_sig
		if ( false === strpos( $token, '-' ) ) {
			return $this->invalid( 'malformed_token' );
		}

		$parts = explode( '-', $token, 2 );
		if ( count( $parts ) !== 2 ) {
			return $this->invalid( 'malformed_token' );
		}

		list( $license_version_id, $license_sig ) = $parts;

		// Validate license_version_id is numeric
		if ( ! ctype_digit( $license_version_id ) ) {
			return $this->invalid( 'invalid_version_id' );
		}

		// Validate signature is hex (64 chars for SHA256)
		if ( ! ctype_xdigit( $license_sig ) ) {
			return $this->invalid( 'invalid_signature_format' );
		}

		// Get HMAC secret from settings
		$settings = $this->options->get_settings();
		$hmac_secret = $settings['hmac_secret'] ?? '';

		if ( '' === $hmac_secret ) {
			return $this->invalid( 'hmac_secret_not_configured' );
		}

		// Compute expected signature
		$expected_sig = hash_hmac( 'sha256', $license_version_id, $hmac_secret );

		// Timing-safe comparison
		if ( ! hash_equals( $expected_sig, strtolower( $license_sig ) ) ) {
			return $this->invalid( 'signature_invalid' );
		}

		// Token is valid
		return [
			'valid' => true,
			'error' => '',
			'data'  => [
				'license_version_id' => (int) $license_version_id,
				'license_sig'        => $license_sig,
				'token_type'         => 'hmac',
			],
		];
	}

	/**
	 * Helper to return invalid result.
	 *
	 * @param string $code Error code.
	 * @return array
	 */
	private function invalid( string $code ): array {
		return [
			'valid' => false,
			'error' => $code,
			'data'  => [],
		];
	}
}
