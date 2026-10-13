<?php
/**
 * Service wiring and hook registration.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance;

use Hive\Compliance\Catalog\Applicability;
use Hive\Compliance\Catalog\Catalog;
use Hive\Compliance\Catalog\RuleEngine;
use Hive\Compliance\Document\PostType;
use Hive\Compliance\Document\Repository;
use Hive\Compliance\I18n\Languages;
use Hive\Compliance\Integrations\Detector;
use Hive\Compliance\Profile\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the plugin services (lazily built) and registers hooks.
 */
final class Plugin {

	const CAPABILITY = 'manage_hive_compliance';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Built services, keyed by class name.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * The plugin instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Entry point, runs on plugins_loaded.
	 */
	public static function boot(): void {
		self::instance()->register_hooks();
	}

	/**
	 * Declares HPOS (custom order tables) and cart/checkout blocks compatibility.
	 */
	public static function declare_woocommerce_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', HIVE_COMPLIANCE_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', HIVE_COMPLIANCE_FILE, true );
		}
	}

	/**
	 * Registers the hooks of every component.
	 */
	private function register_hooks(): void {
		add_action( 'init', array( $this, 'on_init' ), 5 );
		add_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ), 5 );
		$this->repository()->register();
	}

	/**
	 * Init: translations, post type.
	 */
	public function on_init(): void {
		load_plugin_textdomain( 'hive-compliance', false, dirname( plugin_basename( HIVE_COMPLIANCE_FILE ) ) . '/languages' );
		$this->post_type()->register();
	}

	/**
	 * Settings service.
	 */
	public function settings(): Settings {
		return $this->service( Settings::class, static fn() => new Settings() );
	}

	/**
	 * Languages service.
	 */
	public function languages(): Languages {
		return $this->service( Languages::class, fn() => new Languages( $this->settings() ) );
	}

	/**
	 * Post type service.
	 */
	public function post_type(): PostType {
		return $this->service( PostType::class, static fn() => new PostType() );
	}

	/**
	 * Document repository.
	 */
	public function repository(): Repository {
		return $this->service( Repository::class, static fn() => new Repository() );
	}

	/**
	 * Document catalog.
	 */
	public function catalog(): Catalog {
		return $this->service(
			Catalog::class,
			static fn() => new Catalog( HIVE_COMPLIANCE_DIR . 'catalog/catalog.json', HIVE_COMPLIANCE_DIR . 'catalog/catalog.schema.json' )
		);
	}

	/**
	 * Applicability rule engine.
	 */
	public function rules(): RuleEngine {
		return $this->service( RuleEngine::class, static fn() => new RuleEngine() );
	}

	/**
	 * Integration detector.
	 */
	public function detector(): Detector {
		return $this->service( Detector::class, static fn() => new Detector() );
	}

	/**
	 * Store profile.
	 */
	public function profile(): Profile {
		return $this->service( Profile::class, fn() => new Profile( $this->detector() ) );
	}

	/**
	 * Which documents apply to this shop.
	 */
	public function applicability(): Applicability {
		return $this->service( Applicability::class, fn() => new Applicability( $this->catalog(), $this->rules(), $this->profile() ) );
	}

	/**
	 * Returns a service, building it on first use.
	 *
	 * @param string   $id      Service ID (class name).
	 * @param callable $factory Builds the service.
	 * @return mixed
	 */
	private function service( string $id, callable $factory ) {
		if ( ! isset( $this->services[ $id ] ) ) {
			$this->services[ $id ] = $factory();
		}
		return $this->services[ $id ];
	}
}
