<?php
/**
 * Form handlers: create, assign and remove page bindings.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

use Hive\Compliance\I18n\Languages;
use Hive\Compliance\Render\PageManager;
use Hive\Compliance\Render\ShellGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Handles admin-post.php?action=hive_compliance_bind_page|hive_compliance_unbind_page.
 */
final class BindingActions {

	const ACTION_BIND   = 'hive_compliance_bind_page';
	const ACTION_UNBIND = 'hive_compliance_unbind_page';

	/**
	 * Page manager.
	 *
	 * @var PageManager
	 */
	private PageManager $pages;

	/**
	 * Constructor.
	 *
	 * @param PageManager $pages Page manager.
	 */
	public function __construct( PageManager $pages ) {
		$this->pages = $pages;
	}

	/**
	 * Hooks the handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_BIND, array( $this, 'bind' ) );
		add_action( 'admin_post_' . self::ACTION_UNBIND, array( $this, 'unbind' ) );
	}

	/**
	 * Creates a new page or assigns an existing one.
	 */
	public function bind(): void {
		Request::require_capability();
		check_admin_referer( self::ACTION_BIND );

		$doc_id  = sanitize_key( wp_unslash( $_POST['doc'] ?? '' ) );
		$lang    = Languages::normalize_code( sanitize_text_field( wp_unslash( $_POST['lang'] ?? '' ) ) );
		$mode    = sanitize_key( wp_unslash( $_POST['mode'] ?? 'create' ) );
		$page_id = absint( $_POST['page_id'] ?? 0 );

		if ( 'assign' === $mode ) {
			$result = $this->pages->assign( $doc_id, $lang, $page_id );
			if ( is_wp_error( $result ) ) {
				Notices::add( 'error', $result->get_error_message() );
			} elseif ( $result ) {
				Notices::add( 'warning', $this->problems_message( $result ) );
			} else {
				Notices::add( 'success', __( 'Page assigned.', 'hive-compliance' ) );
			}
		} else {
			$result = $this->pages->create( $doc_id, $lang );
			if ( is_wp_error( $result ) ) {
				Notices::add( 'error', $result->get_error_message() );
			} else {
				Notices::add( 'success', __( 'Draft page created and bound. Publish it from the Pages screen when the document is ready.', 'hive-compliance' ) );
			}
		}

		Request::redirect( Request::editor_url( $doc_id, $lang ) );
	}

	/**
	 * Removes a binding.
	 */
	public function unbind(): void {
		Request::require_capability();
		check_admin_referer( self::ACTION_UNBIND );

		$doc_id = sanitize_key( wp_unslash( $_POST['doc'] ?? '' ) );
		$lang   = Languages::normalize_code( sanitize_text_field( wp_unslash( $_POST['lang'] ?? '' ) ) );
		$this->pages->unbind( $doc_id, $lang );
		Notices::add( 'success', __( 'Binding removed. The page itself was not changed.', 'hive-compliance' ) );

		Request::redirect( Request::editor_url( $doc_id, $lang ) );
	}

	/**
	 * Human message for shell problems.
	 *
	 * @param string[] $problems Problem codes.
	 */
	private function problems_message( array $problems ): string {
		$labels = array(
			ShellGuard::PROBLEM_EXTRA_CONTENT  => __( 'the page contains other content besides the document block', 'hive-compliance' ),
			ShellGuard::PROBLEM_MISSING_BLOCK  => __( 'the page does not contain the Compliance document block yet', 'hive-compliance' ),
			ShellGuard::PROBLEM_OTHER_DOCUMENT => __( 'the page shows a different document', 'hive-compliance' ),
		);
		$parts  = array_map( static fn( $code ) => $labels[ $code ] ?? $code, $problems );
		/* translators: %s: list of problems. */
		return sprintf( __( 'Page assigned, but %s. Keep only the Compliance document block on bound pages.', 'hive-compliance' ), implode( '; ', $parts ) );
	}
}
