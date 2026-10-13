<?php
/**
 * "Generate skeleton" actions (one document, or every empty applicable document).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

use Hive\Compliance\Catalog\Entry;
use Hive\Compliance\Document\Document;
use Hive\Compliance\Hooks;
use Hive\Compliance\I18n\Languages;
use Hive\Compliance\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Handlers plus the button shown above the editor of an empty document.
 */
final class SkeletonActions {

	const ACTION_ONE = 'hive_compliance_generate_skeleton';
	const ACTION_ALL = 'hive_compliance_generate_skeletons';

	/**
	 * Plugin services.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin services.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks the handlers and the editor button.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_ONE, array( $this, 'handle_one' ) );
		add_action( 'admin_post_' . self::ACTION_ALL, array( $this, 'handle_all' ) );
		add_action( Hooks::EDITOR_BEFORE_FORM, array( $this, 'editor_button' ), 10, 2 );
	}

	/**
	 * Button above the editor when the document is empty.
	 *
	 * @param Entry    $entry    Catalog entry.
	 * @param Document $document Document.
	 */
	public function editor_button( Entry $entry, Document $document ): void {
		if ( ! $document->is_empty() ) {
			return;
		}
		printf( '<form method="post" action="%s" class="hive-skeleton-form">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::ACTION_ONE ) );
		wp_nonce_field( self::ACTION_ONE );
		printf( '<input type="hidden" name="doc" value="%1$s"><input type="hidden" name="lang" value="%2$s">', esc_attr( $entry->id ), esc_attr( $document->lang ) );
		echo '<p>' . esc_html__( 'This document is empty.', 'hive-compliance' ) . ' ';
		submit_button( __( 'Generate skeleton', 'hive-compliance' ), 'secondary', 'submit', false );
		echo ' <span class="hive-meta">' . esc_html__( 'Headings from the required elements and their placeholders, no prose. Complete it here or through an LLM export.', 'hive-compliance' ) . '</span></p></form>';
	}

	/**
	 * Generates one skeleton.
	 */
	public function handle_one(): void {
		Request::require_capability();
		check_admin_referer( self::ACTION_ONE );

		$entry = $this->plugin->catalog()->get( sanitize_key( wp_unslash( $_POST['doc'] ?? '' ) ) );
		$lang  = Languages::normalize_code( sanitize_text_field( wp_unslash( $_POST['lang'] ?? '' ) ) );
		if ( null === $entry || ! $this->plugin->languages()->is_enabled( $lang ) ) {
			Notices::add( 'error', __( 'Unknown document or language.', 'hive-compliance' ) );
			Request::redirect( Request::page_url( 'documents' ) );
		}

		$result = $this->plugin->skeletons()->generate( $entry, $lang );
		if ( is_wp_error( $result ) ) {
			Notices::add( 'error', $result->get_error_message() );
		} elseif ( null === $result ) {
			Notices::add( 'info', __( 'The document already has content: nothing was generated.', 'hive-compliance' ) );
		} else {
			Notices::add( 'success', __( 'Skeleton generated as a draft.', 'hive-compliance' ) );
		}
		Request::redirect( Request::editor_url( $entry->id, $lang ) );
	}

	/**
	 * Generates the skeletons of every empty applicable document.
	 */
	public function handle_all(): void {
		Request::require_capability();
		check_admin_referer( self::ACTION_ALL );

		$scope     = sanitize_key( wp_unslash( $_POST['scope'] ?? 'default' ) );
		$languages = 'all' === $scope ? $this->plugin->languages()->enabled() : array( $this->plugin->languages()->default_language() );
		$result    = $this->plugin->skeletons()->generate_applicable( $languages );

		/* translators: 1: created skeletons, 2: documents left untouched. */
		Notices::add( 'success', sprintf( __( '%1$d skeletons generated as drafts; %2$d documents already had content and were left untouched.', 'hive-compliance' ), $result['created'], $result['skipped'] ) );
		Request::redirect( Request::page_url() );
	}
}
