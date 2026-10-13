<?php
/**
 * Base test case.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Plugin;

/**
 * Shared helpers for the plugin's integration tests.
 */
abstract class Hive_TestCase extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_cache_flush();
		$this->plugin()->repository()->flush();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	protected function plugin(): Plugin {
		return Plugin::instance();
	}

	/**
	 * Logs in a fresh user with the given role and returns its ID.
	 */
	protected function login_as( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Reads a fixture file.
	 */
	protected function fixture( string $path ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/tests/fixtures/' . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
