<?php
/**
 * Service wiring and hook registration.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance;

use Hive\Compliance\Business\BusinessData;
use Hive\Compliance\Catalog\Applicability;
use Hive\Compliance\Catalog\Catalog;
use Hive\Compliance\Catalog\RuleEngine;
use Hive\Compliance\Document\PostType;
use Hive\Compliance\Document\Repository;
use Hive\Compliance\I18n\Languages;
use Hive\Compliance\Integrations\Detector;
use Hive\Compliance\Placeholders\BusinessProvider;
use Hive\Compliance\Placeholders\CustomProvider;
use Hive\Compliance\Placeholders\LinksProvider;
use Hive\Compliance\Placeholders\Registry;
use Hive\Compliance\Placeholders\Resolver;
use Hive\Compliance\Placeholders\StoreProvider;
use Hive\Compliance\Profile\Profile;
use Hive\Compliance\Profile\StoreFacts;
use Hive\Compliance\Render\PageBindings;

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
	 * Business data.
	 */
	public function business(): BusinessData {
		return $this->service( BusinessData::class, static fn() => new BusinessData() );
	}

	/**
	 * Auto-detected store facts.
	 */
	public function store_facts(): StoreFacts {
		return $this->service( StoreFacts::class, static fn() => new StoreFacts() );
	}

	/**
	 * Page bindings.
	 */
	public function bindings(): PageBindings {
		return $this->service( PageBindings::class, static fn() => new PageBindings() );
	}

	/**
	 * Placeholder namespaces.
	 */
	public function placeholders(): Registry {
		return $this->service(
			Registry::class,
			fn() => new Registry(
				array(
					'business' => new BusinessProvider( $this->business() ),
					'store'    => new StoreProvider( $this->store_facts() ),
					'links'    => new LinksProvider( $this->bindings(), $this->catalog(), $this->languages() ),
					'custom'   => new CustomProvider( $this->business() ),
				)
			)
		);
	}

	/**
	 * Placeholder resolver.
	 */
	public function resolver(): Resolver {
		return $this->service( Resolver::class, fn() => new Resolver( $this->placeholders() ) );
	}

	/**
	 * Seller country from the profile (used to validate business data).
	 */
	public function seller_country(): string {
		$business = $this->business()->value( 'country' );
		return '' !== $business ? $business : (string) ( $this->profile()->get()['seller_country'] ?? '' );
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
