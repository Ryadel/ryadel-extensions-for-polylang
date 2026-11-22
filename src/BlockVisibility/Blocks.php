<?php
/**
 * Per-language block visibility.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang\BlockVisibility;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the `efplLanguages` attribute to every block and hides a block when the current language is not in it.
 *
 * The attribute lives in the block comment delimiter (no HTML source), so it never causes block validation errors.
 * An empty list means every language.
 */
final class Blocks {

	const ATTRIBUTE = 'efplLanguages';
	const SCRIPT    = 'extensions-for-polylang-editor';

	/**
	 * Whether blocks are hidden by language and the editor shows the panel. When false, only the attribute is kept.
	 *
	 * @var bool
	 */
	private $enabled;

	/**
	 * Constructor.
	 *
	 * @param bool $enabled False when the module is switched off: the attribute stays registered, on the server and in
	 *                      the editor, so that saved languages are not dropped, but nothing is hidden and no panel shows.
	 */
	public function __construct( $enabled = true ) {
		$this->enabled = (bool) $enabled;
	}

	/**
	 * Adds the hooks. Called on `pll_model_init`, before blocks are registered on `init`.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'register_block_type_args', array( $this, 'add_attribute' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor' ) );
		if ( $this->enabled ) {
			add_filter( 'render_block', array( $this, 'render' ), 10, 2 );
		}
	}

	/**
	 * Declares the attribute on every server-side block type, so that dynamic blocks and the block renderer accept it.
	 *
	 * @param array $args Block type arguments.
	 * @return array
	 */
	public function add_attribute( $args ) {
		if ( ! isset( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
			$args['attributes'] = array();
		}
		if ( ! isset( $args['attributes'][ self::ATTRIBUTE ] ) ) {
			$args['attributes'][ self::ATTRIBUTE ] = array(
				'type'    => 'array',
				'items'   => array( 'type' => 'string' ),
				'default' => array(),
			);
		}
		return $args;
	}

	/**
	 * Empties a block whose languages do not include the current one.
	 *
	 * Nothing is hidden in the admin, nor when no current language is known (for example a REST request without
	 * `lang`, like the block previews of the editor).
	 *
	 * @param string $content Rendered block.
	 * @param array  $block   Parsed block.
	 * @return string
	 */
	public function render( $content, $block ) {
		if ( empty( $block['attrs'][ self::ATTRIBUTE ] ) || ! is_array( $block['attrs'][ self::ATTRIBUTE ] ) ) {
			return $content;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $content;
		}

		$current = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : false;
		if ( ! is_string( $current ) || '' === $current ) {
			return $content;
		}

		return in_array( $current, $block['attrs'][ self::ATTRIBUTE ], true ) ? $content : '';
	}

	/**
	 * Loads the "Languages" panel in the block editors (posts, site, widgets). When the module is off, the script only
	 * adds the attribute to the blocks registered in JavaScript.
	 *
	 * @return void
	 */
	public function enqueue_editor() {
		$languages = array();
		foreach ( $this->enabled ? (array) pll_languages_list( array( 'fields' => '' ) ) : array() as $language ) {
			if ( is_object( $language ) ) {
				$languages[] = array(
					'slug' => $language->slug,
					'name' => $language->name,
				);
			}
		}

		wp_enqueue_script(
			self::SCRIPT,
			plugins_url( 'js/editor.js', EXTENSIONS_FOR_POLYLANG_FILE ),
			array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-compose', 'wp-element', 'wp-hooks', 'wp-i18n' ),
			EXTENSIONS_FOR_POLYLANG_VERSION,
			true
		);
		wp_add_inline_script(
			self::SCRIPT,
			'window.extensionsForPolylangBlocks = ' . wp_json_encode(
				array(
					'panel'     => $this->enabled,
					'languages' => $languages,
				)
			) . ';',
			'before'
		);
		wp_set_script_translations( self::SCRIPT, 'extensions-for-polylang' );
	}
}
