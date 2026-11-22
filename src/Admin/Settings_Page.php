<?php
/**
 * Admin page: Languages > Extensions.
 *
 * @package ExtensionsForPolylang
 */

namespace Ryadel\ExtensionsForPolylang\Admin;

use Ryadel\ExtensionsForPolylang\Plugin;
use Ryadel\ExtensionsForPolylang\Services\Status;
use Ryadel\ExtensionsForPolylang\Services\Translations;
use Ryadel\ExtensionsForPolylang\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Module switches (Settings API) and translation coverage, in a submenu of the Polylang "Languages" menu.
 */
final class Settings_Page {

	const SLUG   = 'extensions-for-polylang';
	const PARENT = 'mlang';

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Adds the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 11 ); // After Polylang, which adds its menu at 10.
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EXTENSIONS_FOR_POLYLANG_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds the submenu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			self::PARENT,
			__( 'Extensions for Polylang', 'extensions-for-polylang' ),
			__( 'Extensions', 'extensions-for-polylang' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Registers the option with the Settings API.
	 *
	 * @return void
	 */
	public function register_setting() {
		register_setting(
			self::SLUG,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitizes the option.
	 *
	 * The callback runs on every `update_option()`, not only on form submissions: only input that comes from the form
	 * (with its hidden `form` field) is read as checkboxes, where a missing module means "off". Modules that cannot
	 * run here (provided by Polylang Pro) keep their stored state.
	 *
	 * @param mixed $input Submitted or updated value.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$modules = isset( $input['modules'] ) && is_array( $input['modules'] ) ? $input['modules'] : array();

		if ( empty( $input['form'] ) ) {
			return array( 'modules' => array_map( 'boolval', $modules ) );
		}

		$option = Settings::get();
		foreach ( $this->plugin->modules() as $id => $module ) {
			if ( $module->is_available() ) {
				$option['modules'][ $id ] = ! empty( $modules[ $id ] );
			}
		}
		return $option;
	}

	/**
	 * "Settings" link in the plugins list.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
				esc_html__( 'Settings', 'extensions-for-polylang' )
			)
		);
		return $links;
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Extensions for Polylang', 'extensions-for-polylang' ); ?></h1>
			<?php settings_errors(); ?>

			<h2><?php esc_html_e( 'Modules', 'extensions-for-polylang' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( self::SLUG ); ?>
				<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[form]" value="1" />
				<table class="form-table" role="presentation">
					<tbody>
						<?php $this->render_modules(); ?>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Translation coverage', 'extensions-for-polylang' ); ?></h2>
			<?php $this->render_coverage(); ?>
		</div>
		<?php
	}

	/**
	 * One row per module.
	 *
	 * @return void
	 */
	private function render_modules() {
		foreach ( $this->plugin->modules() as $id => $module ) {
			$available = $module->is_available();
			$field     = 'efpl-module-' . $id;
			?>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $module->title() ); ?></label></th>
				<td>
					<input type="checkbox" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[modules][' . $id . ']' ); ?>" value="1"
						<?php checked( $available && $this->plugin->is_active( $module ) ); ?> <?php disabled( ! $available ); ?> />
					<p class="description">
						<?php
						echo esc_html( $module->description() );
						if ( ! $available ) {
							echo ' <strong>' . esc_html__( 'Provided by Polylang Pro.', 'extensions-for-polylang' ) . '</strong>';
						}
						?>
					</p>
				</td>
			</tr>
			<?php
		}
	}

	/**
	 * Table of the translation coverage: for each post type and language, the number of posts, and how many posts of
	 * the default language still wait for a translation, linked to the filtered admin list. Media have no link: their
	 * list (`upload.php`) does not take the Polylang filters, and `edit.php` redirects there without them.
	 *
	 * @return void
	 */
	private function render_coverage() {
		$post_types = array_values(
			array_filter(
				Status::post_types(),
				static function ( $post_type ) {
					$object = get_post_type_object( $post_type );
					return $object && $object->show_ui;
				}
			)
		);

		$languages = Translations::language_slugs();
		$default   = pll_default_language( 'slug' );
		$counts    = Status::counts( $post_types );

		if ( ! $post_types || ! $languages ) {
			echo '<p>' . esc_html__( 'No translated content type.', 'extensions-for-polylang' ) . '</p>';
			return;
		}
		?>
		<p class="description"><?php esc_html_e( 'All statuses except trash. Below each count, the posts in the default language that are not translated yet.', 'extensions-for-polylang' ); ?></p>
		<div style="overflow-x: auto;">
		<table class="widefat striped efpl-coverage">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Content type', 'extensions-for-polylang' ); ?></th>
					<?php foreach ( $languages as $slug ) : ?>
						<th scope="col"><?php echo esc_html( $this->language_name( $slug ) ); ?></th>
					<?php endforeach; ?>
					<th scope="col"><?php esc_html_e( 'Without language', 'extensions-for-polylang' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $post_types as $post_type ) : ?>
					<?php $data = $counts[ $post_type ]; ?>
					<tr>
						<th scope="row"><?php echo esc_html( get_post_type_object( $post_type )->labels->name ); ?></th>
						<?php foreach ( $languages as $slug ) : ?>
							<td data-lang="<?php echo esc_attr( $slug ); ?>">
								<?php echo esc_html( number_format_i18n( $data['languages'][ $slug ]['count'] ) ); ?>
								<?php
								$missing = $slug !== $default && isset( $data['languages'][ $default ]['untranslated'][ $slug ] ) ? $data['languages'][ $default ]['untranslated'][ $slug ] : 0;
								$this->render_missing( $post_type, $slug, $default, $missing );
								?>
							</td>
						<?php endforeach; ?>
						<td data-lang=""><?php echo esc_html( number_format_i18n( $data['no_language'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Prints the number of posts still to translate in a coverage cell, linked to the filtered post list.
	 *
	 * The media library does not keep the list filters, so attachments get the number without a link.
	 *
	 * @param string $post_type    Post type.
	 * @param string $slug         Language of the column.
	 * @param string $default_lang Default language.
	 * @param int    $missing      Posts in the default language without a translation in `$slug`.
	 * @return void
	 */
	private function render_missing( $post_type, $slug, $default_lang, $missing ) {
		if ( $missing <= 0 ) {
			return;
		}

		/* translators: %s: number of posts. */
		$label = sprintf( _n( '%s to translate', '%s to translate', $missing, 'extensions-for-polylang' ), number_format_i18n( $missing ) );

		if ( 'attachment' === $post_type ) {
			echo '<br />' . esc_html( $label );
			return;
		}

		$url = add_query_arg(
			array(
				'post_type'       => $post_type,
				'lang'            => $default_lang,
				'untranslated_in' => $slug,
			),
			admin_url( 'edit.php' )
		);
		printf( '<br /><a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
	}

	/**
	 * Name of a language.
	 *
	 * @param string $slug Language slug.
	 * @return string
	 */
	private function language_name( $slug ) {
		$names = array_combine( (array) pll_languages_list(), (array) pll_languages_list( array( 'fields' => 'name' ) ) );
		return isset( $names[ $slug ] ) ? $names[ $slug ] : $slug;
	}
}
