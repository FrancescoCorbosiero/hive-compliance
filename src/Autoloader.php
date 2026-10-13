<?php
/**
 * PSR-4 autoloader for the Hive\Compliance namespace.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance;

defined( 'ABSPATH' ) || exit;

/**
 * Maps Hive\Compliance\Foo\Bar to src/Foo/Bar.php and Hive\Compliance\Lib\* to lib/*.php.
 */
final class Autoloader {

	/**
	 * Registers the autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Loads a class of this plugin.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		if ( 0 === strpos( $relative, 'Lib\\' ) ) {
			$file = dirname( __DIR__ ) . '/lib/' . substr( $relative, 4 ) . '.php';
		} else {
			$file = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		}

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
