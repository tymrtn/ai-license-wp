<?php
/**
 * Core plugin orchestrator.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License;

use CSH\AI_License\Admin\Meta_Box;
use CSH\AI_License\Admin\Notices;
use CSH\AI_License\Admin\Settings_Page;
use CSH\AI_License\Auth\Jwks_Cache;
use CSH\AI_License\Auth\Token_Verifier;
use CSH\AI_License\Auth\Hmac_Token_Verifier;
use CSH\AI_License\Blocking\Bot_Detector;
use CSH\AI_License\Blocking\Enforcement_Manager;
use CSH\AI_License\Blocking\Patterns_Repository;
use CSH\AI_License\Blocking\Rate_Limiter;
use CSH\AI_License\Blocking\Response_Builder;
use CSH\AI_License\Blocking\Search_Verifier;
use CSH\AI_License\Contracts\Bootable;
use CSH\AI_License\Http\Remote_Fetcher;
use CSH\AI_License\Logging\Usage_Queue;
use CSH\AI_License\Robots\Manager as Robots_Manager;
use CSH\AI_License\Settings\Options_Repository;
use CSH\AI_License\Settings\Defaults;
use CSH\AI_License\Utilities\Clock;
use CSH\AI_License\Utilities\Transient_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class responsible for service wiring and bootstrapping.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Service container.
	 *
	 * @var Service_Provider
	 */
	private $services;

	/**
	 * Boot flag to prevent duplicate bootstrapping.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Create a new plugin instance.
	 */
	private function __construct() {
		$this->services = new Service_Provider();
	}

	/**
	 * Retrieve singleton.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Access the service container.
	 *
	 * @return Service_Provider
	 */
	public function services(): Service_Provider {
		return $this->services;
	}

	/**
	 * Plugin activation routine.
	 */
	public function activate(): void {
		$this->boot_services();

		if ( $this->services->has( Usage_Queue::class ) ) {
			$this->services->get( Usage_Queue::class )->install();
		}

		if ( $this->services->has( Enforcement_Manager::class ) ) {
			$enforcement = $this->services->get( Enforcement_Manager::class );
			if ( method_exists( $enforcement, 'register_rewrite' ) ) {
				$enforcement->register_rewrite();
			}
		}

		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation routine.
	 */
	public function deactivate(): void {
		if ( $this->services->has( Usage_Queue::class ) ) {
			$this->services->get( Usage_Queue::class )->deactivate();
		}
	}

	/**
	 * Bootstrap the plugin once plugins_loaded fires.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->boot_services();
		$this->register_modules();
	}

	/**
	 * Build service definitions.
	 */
	private function boot_services(): void {
		if ( $this->services->has( Options_Repository::class ) ) {
			return;
		}

		$services = $this->services;

		$services->set(
			Transient_Helper::class,
			static function () {
				return new Transient_Helper();
			}
		);

		$services->set(
			Clock::class,
			static function () {
				return new Clock();
			}
		);

		$services->set(
			Defaults::class,
			static function () {
				return new Defaults();
			}
		);

		$services->set(
			Options_Repository::class,
			static function ( Service_Provider $container ) {
				return new Options_Repository( $container->get( Defaults::class ) );
			}
		);

		$services->set(
			Jwks_Cache::class,
			static function ( Service_Provider $container ) {
				return new Jwks_Cache(
					$container->get( Remote_Fetcher::class ),
					$container->get( Transient_Helper::class )
				);
			}
		);

		$services->set(
			Token_Verifier::class,
			static function ( Service_Provider $container ) {
				return new Token_Verifier(
					$container->get( Jwks_Cache::class ),
					$container->get( Transient_Helper::class ),
					$container->get( Clock::class )
				);
			}
		);

		$services->set(
			Hmac_Token_Verifier::class,
			static function ( Service_Provider $container ) {
				return new Hmac_Token_Verifier(
					$container->get( Options_Repository::class )
				);
			}
		);

		$services->set(
			Remote_Fetcher::class,
			static function ( Service_Provider $container ) {
				return new Remote_Fetcher( $container->get( Transient_Helper::class ) );
			}
		);

		$services->set(
			Search_Verifier::class,
			static function ( Service_Provider $container ) {
				return new Search_Verifier( $container->get( Transient_Helper::class ) );
			}
		);

		$services->set(
			Patterns_Repository::class,
			static function ( Service_Provider $container ) {
				return new Patterns_Repository(
					$container->get( Remote_Fetcher::class ),
					$container->get( Transient_Helper::class )
				);
			}
		);

		$services->set(
			Bot_Detector::class,
			static function ( Service_Provider $container ) {
				return new Bot_Detector(
					$container->get( Patterns_Repository::class ),
					$container->get( Search_Verifier::class ),
					$container->get( Transient_Helper::class ),
					$container->get( Clock::class )
				);
			}
		);

		$services->set(
			Rate_Limiter::class,
			static function ( Service_Provider $container ) {
				return new Rate_Limiter(
					$container->get( Transient_Helper::class ),
					$container->get( Clock::class )
				);
			}
		);

		$services->set(
			Response_Builder::class,
			static function ( Service_Provider $container ) {
				return new Response_Builder(
					$container->get( Options_Repository::class ),
					$container->get( Clock::class )
				);
			}
		);

		$services->set(
			Usage_Queue::class,
			static function ( Service_Provider $container ) {
				return new Usage_Queue( $container->get( Options_Repository::class ) );
			}
		);

		$services->set(
			Robots_Manager::class,
			static function ( Service_Provider $container ) {
				return new Robots_Manager( $container->get( Options_Repository::class ) );
			}
		);

		$services->set(
			Settings_Page::class,
			static function ( Service_Provider $container ) {
				return new Settings_Page(
					$container->get( Options_Repository::class ),
					$container->get( Defaults::class ),
					$container->get( Robots_Manager::class ),
					$container->get( Usage_Queue::class )
				);
			}
		);

		$services->set(
			Meta_Box::class,
			static function ( Service_Provider $container ) {
				return new Meta_Box( $container->get( Options_Repository::class ) );
			}
		);

		$services->set(
			Notices::class,
			static function ( Service_Provider $container ) {
				return new Notices( $container->get( Options_Repository::class ) );
			}
		);

		$services->set(
			Enforcement_Manager::class,
			static function ( Service_Provider $container ) {
				return new Enforcement_Manager( $container );
			}
		);
	}

	/**
	 * Register and boot plugin modules.
	 */
	private function register_modules(): void {
		$modules = [
			Settings_Page::class,
			Meta_Box::class,
			Robots_Manager::class,
			Notices::class,
			Usage_Queue::class,
			Enforcement_Manager::class,
		];

		foreach ( $modules as $module_class ) {
			if ( ! $this->services->has( $module_class ) ) {
				continue;
			}

			$module = $this->services->get( $module_class );

			if ( $module instanceof Bootable ) {
				$module->boot();
			} elseif ( method_exists( $module, 'boot' ) ) {
				$module->boot();
			}
		}
	}
}
