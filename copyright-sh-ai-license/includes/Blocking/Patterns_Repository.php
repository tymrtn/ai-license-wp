<?php
/**
 * Provides bot pattern signatures from local defaults + remote feed.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Blocking;

use CSH\AI_License\Http\Remote_Fetcher;
use CSH\AI_License\Utilities\Transient_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Retrieves bot detection patterns.
 */
class Patterns_Repository {

	private const TRANSIENT_KEY = 'bot_patterns';
	private const CACHE_TTL     = DAY_IN_SECONDS;

	/**
	 * @var Remote_Fetcher
	 */
	private $fetcher;

	/**
	 * @var Transient_Helper
	 */
	private $transients;

	/**
	 * Constructor.
	 *
	 * @param Remote_Fetcher   $fetcher Remote fetcher.
	 * @param Transient_Helper $transients Transient helper.
	 */
	public function __construct( Remote_Fetcher $fetcher, Transient_Helper $transients ) {
		$this->fetcher    = $fetcher;
		$this->transients = $transients;
	}

	/**
	 * Retrieve consolidated pattern list.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function get_patterns(): array {
		$cached = $this->transients->get( self::TRANSIENT_KEY, null );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$patterns = $this->default_patterns();

		$url = apply_filters( 'csh_ai_bot_pattern_feed_url', 'https://ledger.copyright.sh/v1/bot-patterns.json' );

		$remote = $this->fetcher->get_json( $url, self::CACHE_TTL );

		if ( is_array( $remote ) && ! empty( $remote['patterns'] ) && is_array( $remote['patterns'] ) ) {
			foreach ( $remote['patterns'] as $pattern ) {
				if ( ! isset( $pattern['regex'] ) ) {
					continue;
				}

				$patterns[] = [
					'regex'     => (string) $pattern['regex'],
					'score'     => isset( $pattern['score'] ) ? (int) $pattern['score'] : 50,
					'label'     => isset( $pattern['label'] ) ? (string) $pattern['label'] : 'remote-pattern',
					'severity'  => isset( $pattern['severity'] ) ? (string) $pattern['severity'] : 'medium',
				];
			}
		}

		$patterns = apply_filters( 'csh_ai_bot_patterns', $patterns );

		$this->transients->set( self::TRANSIENT_KEY, $patterns, self::CACHE_TTL );

		return $patterns;
	}

	/**
	 * Default emergency pattern set.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function default_patterns(): array {
		return [
			[
				'regex'    => '/gptbot/i',
				'score'    => 90,
				'label'    => 'openai-gptbot',
				'severity' => 'high',
			],
			[
				'regex'    => '/chatgpt-user/i',
				'score'    => 80,
				'label'    => 'openai-chatgpt',
				'severity' => 'high',
			],
			[
				'regex'    => '/anthropic-ai|claude-web/i',
				'score'    => 85,
				'label'    => 'anthropic',
				'severity' => 'high',
			],
			[
				'regex'    => '/perplexity(bot|crawler)/i',
				'score'    => 85,
				'label'    => 'perplexity',
				'severity' => 'high',
			],
			[
				'regex'    => '/you(bot|search)/i',
				'score'    => 70,
				'label'    => 'youcom',
				'severity' => 'medium',
			],
			[
				'regex'    => '/bytespider/i',
				'score'    => 80,
				'label'    => 'bytedance',
				'severity' => 'high',
			],
			[
				'regex'    => '/google-extended|google ai studio/i',
				'score'    => 65,
				'label'    => 'google-extended',
				'severity' => 'medium',
			],
			[
				'regex'    => '/exa(bot|search)/i',
				'score'    => 75,
				'label'    => 'exa',
				'severity' => 'medium',
			],
			[
				'regex'    => '/tavily(bot)?/i',
				'score'    => 75,
				'label'    => 'tavily',
				'severity' => 'medium',
			],
			[
				'regex'    => '/(semantic|serp|scrapy)/i',
				'score'    => 60,
				'label'    => 'generic-ai',
				'severity' => 'medium',
			],
		];
	}
}
