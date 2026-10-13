<?php
/**
 * Activation and upgrades.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance;

defined( 'ABSPATH' ) || exit;

/**
 * Grants the capability, seeds options and schedules the first skeleton generation.
 */
final class Installer {

	const DB_VERSION = 1;

	const OPTION_DB_VERSION        = 'hive_compliance_db_version';
	const OPTION_PENDING_SKELETONS = 'hive_compliance_pending_skeletons';

	/**
	 * Activation hook. On a network activation each site installs lazily on its first admin request.
	 */
	public static function activate(): void {
		self::install();
	}

	/**
	 * Runs the install routine when the stored schema version is older than the code.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::OPTION_DB_VERSION, 0 ) < self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Install routine for the current site. Safe to run more than once.
	 */
	public static function install(): void {
		self::grant_capability();

		$first_run = false === get_option( self::OPTION_DB_VERSION, false );
		if ( $first_run ) {
			$settings = new Settings();
			add_option( Settings::OPTION, $settings->sanitize( $settings->defaults() ), '', false );
			// Skeletons need the catalog and the post type, which load on init: generate on the next admin request.
			add_option( self::OPTION_PENDING_SKELETONS, 1, '', false );
		}

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	/**
	 * Grants manage_hive_compliance to administrators.
	 */
	public static function grant_capability(): void {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( Plugin::CAPABILITY ) ) {
			$role->add_cap( Plugin::CAPABILITY );
		}
	}
}
