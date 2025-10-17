<?php
/**
 * robots.txt integration and management.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Robots;

use CSH\AI_License\Contracts\Bootable;
use CSH\AI_License\Settings\Options_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Handles robots.txt modifications.
 */
class Manager implements Bootable {

	private const AI_MARKER = '# --- Copyright.sh AI crawler rules ---';

	/**
	 * Options.
	 *
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
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'robots_txt', [ $this, 'filter_robots_txt' ], 20, 2 );
	}

	/**
	 * Filter robots.txt output when managed.
	 *
	 * @param string $output Existing output.
	 * @param bool   $public Whether site is public.
	 * @return string
	 */
	public function filter_robots_txt( string $output, bool $public ): string {
		unset( $public );

		$settings = $this->options->get_settings();
		$robots   = $settings['robots'] ?? [];

		if ( empty( $robots['manage'] ) ) {
			// Respect manual settings but inject AI block snippet if requested.
			if ( ! empty( $robots['ai_rules'] ) ) {
				$output .= "\n\n" . $this->render_ai_block();
			}
			return $output;
		}

		$content = $robots['content'] ?? '';

		if ( '' === trim( $content ) ) {
			$content = $this->get_default_template();
		}

		$content = $this->replace_placeholders( $content );

		if ( ! empty( $robots['ai_rules'] ) ) {
			$content = trim( $content ) . "\n\n" . $this->render_ai_block();
		}

		return $content;
	}

	/**
	 * Default robots.txt template.
	 *
	 * @return string
	 */
	private function get_default_template(): string {
		$template  = "# robots.txt managed by Copyright.sh\n";
		$template .= "# Customise directives to fit your site.\n\n";
		$template .= "User-agent: *\n";
		$template .= "Allow: /\n\n";
		$template .= "# Sitemap (optional)\n";
		$template .= "Sitemap: {{sitemap_url}}\n";

		return $template;
	}

	/**
	 * Replace helper placeholders.
	 *
	 * @param string $content robots.txt content.
	 * @return string
	 */
	private function replace_placeholders( string $content ): string {
		$sitemap = function_exists( 'home_url' ) ? trailingslashit( home_url() ) . 'sitemap.xml' : '';
		return str_replace( '{{sitemap_url}}', $sitemap, $content );
	}

	/**
	 * Render curated AI rules block.
	 *
	 * @return string
	 */
	private function render_ai_block(): string {
		$lines = [
			self::AI_MARKER,
			'# Allow major search engines',
			'User-agent: Googlebot',
			'Allow: /',
			'',
			'User-agent: Bingbot',
			'Allow: /',
			'',
			'User-agent: DuckDuckBot',
			'Allow: /',
			'',
			'# Block AI/ML crawlers',
		];

		$agents = [
			'GPTBot',
			'ChatGPT-User',
			'anthropic-ai',
			'Claude-Web',
			'CCBot',
			'PerplexityBot',
			'PerplexityCrawler',
			'YouBot',
			'Bytespider',
			'Google-Extended',
			'ExaBot',
			'TavilyBot',
			'SerpBot',
			'SerpstatBot',
			'Scrapy',
		];

		$agents = apply_filters( 'csh_ai_robots_blocked_agents', $agents );

		foreach ( $agents as $agent ) {
			$agent = trim( (string) $agent );
			if ( '' === $agent ) {
				continue;
			}

			$lines[] = 'User-agent: ' . $agent;
			$lines[] = 'Disallow: /';
			$lines[] = '';
		}

		$lines[] = '# Default allow for other agents';
		$lines[] = 'User-agent: *';
		$lines[] = 'Allow: /';

		return implode( "\n", $lines );
	}
}
