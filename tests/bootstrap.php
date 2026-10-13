<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and the plugin.
 *
 * WP_TESTS_DIR points to the WordPress PHPUnit library (wp-env sets it to /wordpress-phpunit).
 *
 * @package Hive\Compliance
 */

$hive_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $hive_tests_dir ) {
	$hive_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

if ( ! file_exists( $hive_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$hive_tests_dir}/includes/functions.php. Set WP_TESTS_DIR." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once $hive_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		// Optional: load WooCommerce when it is installed next to the plugin (wp-env).
		if ( getenv( 'HIVE_TESTS_WITH_WOOCOMMERCE' ) && file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ) ) {
			require WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		}
		require dirname( __DIR__ ) . '/hive-compliance.php';
	}
);

require $hive_tests_dir . '/includes/bootstrap.php';

\Hive\Compliance\Installer::install();

require_once __DIR__ . '/TestCase.php';
