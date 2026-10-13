<?php
/**
 * REST routes (namespace hive-compliance/v1).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Rest;

use Hive\Compliance\I18n\Languages;
use Hive\Compliance\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /hive-compliance/v1/preview: live preview and lint findings for the editor.
 * Cookie authentication with the X-WP-Nonce header; manage_hive_compliance required.
 */
final class Controller {

	const NAMESPACE = 'hive-compliance/v1';

	/**
	 * Largest Markdown accepted for a preview, in bytes.
	 */
	const MAX_MARKDOWN = 1048576;

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
	 * Registers the routes (on rest_api_init).
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'doc_id'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'lang'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( Languages::class, 'normalize_code' ),
					),
					'markdown' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission callback.
	 */
	public function can_manage(): bool {
		return current_user_can( Plugin::CAPABILITY );
	}

	/**
	 * Renders a preview through the same pipeline as the front end (markers shown).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview( WP_REST_Request $request ) {
		$entry = $this->plugin->catalog()->get( (string) $request->get_param( 'doc_id' ) );
		if ( null === $entry ) {
			return new WP_Error( 'hive_unknown_document', __( 'Unknown document.', 'hive-compliance' ), array( 'status' => 404 ) );
		}
		$lang     = (string) $request->get_param( 'lang' );
		$markdown = (string) $request->get_param( 'markdown' );
		if ( '' === $lang ) {
			return new WP_Error( 'hive_invalid_language', __( 'Invalid language.', 'hive-compliance' ), array( 'status' => 400 ) );
		}
		if ( strlen( $markdown ) > self::MAX_MARKDOWN ) {
			return new WP_Error( 'hive_too_large', __( 'The document is too large to preview.', 'hive-compliance' ), array( 'status' => 413 ) );
		}

		$result   = $this->plugin->renderer()->render_markdown( $markdown, $entry, $lang, true );
		$findings = $this->plugin->linter()->lint( $markdown, $entry, $lang );

		return new WP_REST_Response(
			array(
				'html'     => $result['html'],
				'missing'  => $result['missing'],
				'findings' => array_map( static fn( $finding ) => $finding->jsonSerialize(), $findings ),
			)
		);
	}
}
