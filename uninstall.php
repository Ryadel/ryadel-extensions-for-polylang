<?php
/**
 * Removes the plugin settings when the plugin is deleted.
 *
 * Language data (the `active` flag of a language) belongs to Polylang and is left alone.
 *
 * @package ExtensionsForPolylang
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $extensions_for_polylang_site_id ) {
		switch_to_blog( $extensions_for_polylang_site_id );
		delete_option( 'extensions_for_polylang' );
		restore_current_blog();
	}
} else {
	delete_option( 'extensions_for_polylang' );
}
