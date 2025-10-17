<?php
/**
 * JWT token verification for AI licence tokens.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Auth;

use CSH\AI_License\Blocking\Request_Context;
use CSH\AI_License\Utilities\Clock;
use CSH\AI_License\Utilities\Transient_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Validates RS256 JWTs issued by the Copyright.sh ledger.
 */
class Token_Verifier {

	private const JTI_PREFIX = 'jti_';
	private const CACHE_TTL  = 15 * MINUTE_IN_SECONDS;

	/**
	 * @var Jwks_Cache
	 */
	private $jwks;

	/**
	 * @var Transient_Helper
	 */
	private $transients;

	/**
	 * @var Clock
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param Jwks_Cache       $jwks       JWKS cache.
	 * @param Transient_Helper $transients Transient helper.
	 * @param Clock            $clock      Clock helper.
	 */
	public function __construct( Jwks_Cache $jwks, Transient_Helper $transients, Clock $clock ) {
		$this->jwks       = $jwks;
		$this->transients = $transients;
		$this->clock      = $clock;
	}

	/**
	 * Verify JWT token.
	 *
	 * @param string          $token   Raw bearer token.
	 * @param Request_Context $context Request context.
	 * @return array {
	 *   @type bool   $valid  Whether valid.
	 *   @type string $error  Error code when invalid.
	 *   @type array  $claims Decoded JWT payload when valid.
	 * }
	 */
	public function verify( string $token, Request_Context $context ): array {
		$token = trim( $token );
		$parts = explode( '.', $token );
		if ( count( $parts ) !== 3 ) {
			return $this->invalid( 'malformed_token' );
		}

		list( $header_b64, $payload_b64, $signature_b64 ) = $parts;

		$header_json  = $this->base64url_decode( $header_b64 );
		$payload_json = $this->base64url_decode( $payload_b64 );
		$signature    = $this->base64url_decode( $signature_b64 );

		if ( ! $header_json || ! $payload_json || ! $signature ) {
			return $this->invalid( 'decode_error' );
		}

		$header = json_decode( $header_json, true );
		$claims = json_decode( $payload_json, true );

		if ( ! is_array( $header ) || ! is_array( $claims ) ) {
			return $this->invalid( 'invalid_json' );
		}

		if ( ( $header['alg'] ?? '' ) !== 'RS256' ) {
			return $this->invalid( 'unsupported_alg' );
		}

		$kid = $header['kid'] ?? '';
		if ( '' === $kid ) {
			return $this->invalid( 'missing_kid' );
		}

		$key = $this->jwks->get_key( $kid );
		if ( ! $key ) {
			return $this->invalid( 'unknown_kid' );
		}

		$pem = $this->jwk_to_pem( $key );
		if ( ! $pem ) {
			return $this->invalid( 'jwk_convert_error' );
		}

		if ( ! $this->verify_signature( $header_b64, $payload_b64, $signature, $pem ) ) {
			// Retry once with forced refresh to handle key rotation.
			$this->jwks->refresh();
			$key = $this->jwks->get_key( $kid );
			if ( ! $key ) {
				return $this->invalid( 'signature_invalid' );
			}
			$pem = $this->jwk_to_pem( $key );
			if ( ! $pem || ! $this->verify_signature( $header_b64, $payload_b64, $signature, $pem ) ) {
				return $this->invalid( 'signature_invalid' );
			}
		}

		$now      = $this->clock->now();
		$leeway   = 60;
		$exp      = isset( $claims['exp'] ) ? (int) $claims['exp'] : 0;
		$iat      = isset( $claims['iat'] ) ? (int) $claims['iat'] : 0;
		$issuer   = (string) ( $claims['iss'] ?? '' );
		$audience = (string) ( $claims['aud'] ?? '' );
		$type     = (string) ( $claims['typ'] ?? '' );
		$scope    = (string) ( $claims['scope'] ?? '' );
		$jti      = (string) ( $claims['jti'] ?? '' );

		if ( $exp <= $now - $leeway ) {
			return $this->invalid( 'token_expired' );
		}

		if ( $iat > $now + $leeway ) {
			return $this->invalid( 'token_not_yet_valid' );
		}

		$allowed_types = [ 'lt-single', 'lt-bulk' ];
		if ( ! in_array( $type, $allowed_types, true ) ) {
			return $this->invalid( 'unsupported_type' );
		}

		if ( '' === $issuer ) {
			return $this->invalid( 'missing_issuer' );
		}

		$allowed_issuers = apply_filters( 'csh_ai_license_token_issuers', [ 'https://ledger.copyright.sh' ] );
		if ( ! in_array( $issuer, $allowed_issuers, true ) ) {
			return $this->invalid( 'issuer_mismatch' );
		}

		$expected_audiences = $this->get_expected_audiences( $context );
		if ( ! in_array( $audience, $expected_audiences, true ) ) {
			return $this->invalid( 'audience_mismatch' );
		}

		if ( '' === $scope ) {
			return $this->invalid( 'missing_scope' );
		}

		$allowed_scopes = [ 'infer', 'train' ];
		$scope_parts    = array_map( 'trim', explode( ' ', $scope ) );
		$valid_scope    = false;
		foreach ( $scope_parts as $part ) {
			if ( in_array( $part, $allowed_scopes, true ) ) {
				$valid_scope = true;
				break;
			}
		}
		if ( ! $valid_scope ) {
			return $this->invalid( 'scope_mismatch' );
		}

		if ( '' === $jti ) {
			return $this->invalid( 'missing_jti' );
		}

		$lock_key = self::JTI_PREFIX . hash( 'sha256', $jti );
		if ( $this->transients->get( $lock_key, null ) ) {
			return $this->invalid( 'replay_detected' );
		}
		$this->transients->set( $lock_key, 1, max( 60, $exp - $now ) );

		// URL binding check for single-use tokens.
		if ( isset( $claims['url'] ) && is_string( $claims['url'] ) ) {
			$expected_url = trailingslashit( home_url() );
			$claim_url    = (string) $claims['url'];
			if ( 0 !== strpos( $claim_url, $expected_url ) ) {
				return $this->invalid( 'url_mismatch' );
			}
		}

		return [
			'valid'  => true,
			'error'  => '',
			'claims' => $claims,
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
			'valid'  => false,
			'error'  => $code,
			'claims' => [],
		];
	}

	/**
	 * Verify signature using OpenSSL.
	 *
	 * @param string $header_b64 Encoded header.
	 * @param string $payload_b64 Encoded payload.
	 * @param string $signature Binary signature.
	 * @param string $public_key PEM public key.
	 * @return bool
	 */
	private function verify_signature( string $header_b64, string $payload_b64, string $signature, string $public_key ): bool {
		$data = $header_b64 . '.' . $payload_b64;
		$verified = openssl_verify( $data, $signature, $public_key, OPENSSL_ALGO_SHA256 );
		return 1 === $verified;
	}

	/**
	 * Convert RSA JWK to PEM formatted key.
	 *
	 * @param array $jwk JWK array.
	 * @return string|null
	 */
	private function jwk_to_pem( array $jwk ): ?string {
		if ( ( $jwk['kty'] ?? '' ) !== 'RSA' || empty( $jwk['n'] ) || empty( $jwk['e'] ) ) {
			return null;
		}

		$modulus  = $this->base64url_decode( $jwk['n'] );
		$exponent = $this->base64url_decode( $jwk['e'] );

		if ( false === $modulus || false === $exponent ) {
			return null;
		}

		$modulus_enc  = $this->encode_length_prefixed( $modulus );
		$exponent_enc = $this->encode_length_prefixed( $exponent );

		$rsa_sequence = "\x30" . $this->encode_length( strlen( $modulus_enc . $exponent_enc ) ) . $modulus_enc . $exponent_enc;

		$bitstring = "\x00" . $rsa_sequence;
		$pubkey_sequence = "\x30" . $this->encode_length( strlen( "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00" . "\x03" . $this->encode_length( strlen( $bitstring ) ) . $bitstring ) )
			. "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01"
			. "\x05\x00"
			. "\x03" . $this->encode_length( strlen( $bitstring ) ) . $bitstring;

		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $pubkey_sequence ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		return $pem;
	}

	/**
	 * Encode value with ASN.1 length prefix.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function encode_length_prefixed( string $value ): string {
		if ( ord( $value[0] ) > 0x7f ) {
			$value = "\x00" . $value;
		}
		return "\x02" . $this->encode_length( strlen( $value ) ) . $value;
	}

	/**
	 * ASN.1 length encoding.
	 *
	 * @param int $length Length.
	 * @return string
	 */
	private function encode_length( int $length ): string {
		if ( $length <= 0x7f ) {
			return chr( $length );
		}

		$temp = '';
		while ( $length > 0 ) {
			$temp   = chr( $length & 0xff ) . $temp;
			$length >>= 8;
		}

		return chr( 0x80 | strlen( $temp ) ) . $temp;
	}

	/**
	 * Base64 URL decoding helper.
	 *
	 * @param string $input Input.
	 * @return string|false
	 */
	private function base64url_decode( string $input ) {
		$remainder = strlen( $input ) % 4;
		if ( $remainder > 0 ) {
			$input .= str_repeat( '=', 4 - $remainder );
		}
		return base64_decode( strtr( $input, '-_', '+/' ) );
	}

	/**
	 * Build expected audiences (domain + origin).
	 *
	 * @param Request_Context $context Request context.
	 * @return array
	 */
	private function get_expected_audiences( Request_Context $context ): array {
		$home_url = home_url();
		$host     = wp_parse_url( $home_url, PHP_URL_HOST );

		$audiences = [ $home_url ];
		if ( $host ) {
			$audiences[] = $host;
		}

		$audiences[] = trailingslashit( $home_url ) . ltrim( $context->path(), '/' );

		return array_values( array_unique( $audiences ) );
	}
}
