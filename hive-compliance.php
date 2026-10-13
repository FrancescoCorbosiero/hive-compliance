<?php
/**
 * Plugin Name:          Hive Compliance
 * Plugin URI:           https://github.com/FrancescoCorbosiero/hive-compliance
 * Description:          Manages every legal and compliance document of a WooCommerce shop as Markdown, with an LLM export/import round-trip and a per-document diff review.
 * Version:              0.1.0
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Author:               Hive
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          hive-compliance
 * Domain Path:          /languages
 * WC requires at least: 8.0
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance;

defined( 'ABSPATH' ) || exit;

define( 'HIVE_COMPLIANCE_VERSION', '0.1.0' );
define( 'HIVE_COMPLIANCE_FILE', __FILE__ );
define( 'HIVE_COMPLIANCE_DIR', plugin_dir_path( __FILE__ ) );
define( 'HIVE_COMPLIANCE_URL', plugin_dir_url( __FILE__ ) );

require_once HIVE_COMPLIANCE_DIR . 'src/Autoloader.php';
Autoloader::register();

require_once HIVE_COMPLIANCE_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );

add_action( 'before_woocommerce_init', array( Plugin::class, 'declare_woocommerce_compatibility' ) );
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
