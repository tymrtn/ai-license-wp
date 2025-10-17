<?php
/**
 * Enforcement profile definitions and helpers.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Blocking;

use function __;

defined( 'ABSPATH' ) || exit;

/**
 * Provides curated enforcement presets.
 */
class Profiles {

	/**
	 * Retrieve all available profiles.
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		$profiles = [
			'default' => [
				'label'       => __( 'Demand Licence (recommended)', 'copyright-sh-ai-license' ),
				'description' => __( 'Allow search engines, challenge commercial AI crawlers with 402, and block known bad actors.', 'copyright-sh-ai-license' ),
				'threshold'   => 60,
				'rate_limit'  => [ 'requests' => 120, 'window' => 300 ],
				'allow'       => self::search_bots(),
				'challenge'   => self::licensable_bots(),
				'block'       => self::bad_actors(),
			],
			'strict'  => [
				'label'       => __( 'Strict – Block Everything New', 'copyright-sh-ai-license' ),
				'description' => __( 'Block all AI crawlers except verified search engines. Intended for high-sensitivity publishers.', 'copyright-sh-ai-license' ),
				'threshold'   => 40,
				'rate_limit'  => [ 'requests' => 80, 'window' => 300 ],
				'allow'       => self::search_bots(),
				'challenge'   => [],
				'block'       => array_merge( self::licensable_bots(), self::bad_actors(), self::long_tail_bots() ),
			],
			'audit'   => [
				'label'       => __( 'Audit Only – Observe Traffic', 'copyright-sh-ai-license' ),
				'description' => __( 'No blocking. Log everything so you can review activity before enforcement.', 'copyright-sh-ai-license' ),
				'threshold'   => 90,
				'rate_limit'  => [ 'requests' => 200, 'window' => 300 ],
				'allow'       => array_merge( self::search_bots(), self::licensable_bots(), self::long_tail_bots() ),
				'challenge'   => [],
				'block'       => [],
			],
		];

		/**
		 * Filter the enforcement profile definitions.
		 *
		 * @param array $profiles Profiles.
		 */
		return apply_filters( 'csh_ai_enforcement_profiles', $profiles );
	}

	/**
	 * Fetch a single profile definition.
	 *
	 * @param string $slug Profile slug.
	 * @return array|null
	 */
	public static function get( string $slug ): ?array {
		$all = self::all();
		return $all[ $slug ] ?? null;
	}

	/**
	 * Provide curated allow-list of search bots.
	 *
	 * @return array
	 */
	private static function search_bots(): array {
		return [
			'Googlebot',
			'Google-InspectionTool',
			'Google-Extended',
			'Applebot',
			'Bingbot',
			'BingPreview',
			'DuckDuckBot',
			'YandexBot',
			'PetalBot',
			'facebot',
			'LinkedInBot',
			'Slackbot',
			'Twitterbot',
			'WhatsApp',
			'archive.org_bot',
		];
	}

	/**
	 * Provide list of licensable AI crawlers that should see a 402 challenge.
	 *
	 * @return array
	 */
	private static function licensable_bots(): array {
		return [
			'GPTBot',
			'ChatGPT-User',
			'ChatGPT-Server',
			'OpenAI-ImageProxy',
			'OAI-SearchBot',
			'ClaudeBot',
			'Claude-Web',
			'Claude-WebAgent',
			'Claude-User',
			'Claude-SearchBot',
			'anthropic-ai',
			'PerplexityBot',
			'Perplexity-User',
			'PerplexityCrawler',
			'bytespider',
			'YouBot',
			'SerpBot',
			'SerpstatBot',
			'SemrushBot-BA',
			'MistralAI-User',
			'GigaBot',
			'Google-CloudVertexBot',
			'Amazonbot',
			'Amazon CloudFront',
			'ProRataInc',
			'CCBot',
			'Novellum AI Crawl',
			'Timpibot',
			'TavilyBot',
			'Diffbot',
		];
	}

	/**
	 * Known bad actors and scrapers to hard block.
	 *
	 * @return array
	 */
	private static function bad_actors(): array {
		return [
			'Bytespider-Privacy', // disguised clones.
			'SenutoBot',
			'AhrefsBot',
			'MJ12bot',
			'DataForSeoBot',
			'GenericBot',
			'DotBot',
			'SemrushBot',
			'SMTBot',
			'Zeus',
			'Scrapy',
			'libwww-perl',
			'python-requests',
			'curl',
			'Wget',
		];
	}

	/**
	 * Additional long-tail crawlers for strict profile.
	 *
	 * @return array
	 */
	private static function long_tail_bots(): array {
		return [
			'EvidencityBot',
			'DiffusionBot',
			'DataCollector',
			'GenericDownloader',
			'WebCopier',
			'HTTrack',
			'NetcraftSurveyAgent',
			'curl',
			'Mozilla/5.0 (compatible;)',
		];
	}
}
