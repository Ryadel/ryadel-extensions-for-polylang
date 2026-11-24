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
	$ryadel_extensions_for_polylang_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $ryadel_extensions_for_polylang_sites as $ryadel_extensions_for_polylang_site_id ) {
		switch_to_blog( $ryadel_extensions_for_polylang_site_id );
		delete_option( 'ryadel_extensions_for_polylang' );
		restore_current_blog();
	}
} else {
	delete_option( 'ryadel_extensions_for_polylang' );
}
