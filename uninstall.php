<?php
/**
 * Opruimen bij verwijderen van de plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

wp_clear_scheduled_hook( 'dpa_prune' );
wp_clear_scheduled_hook( 'dpa_report_tick' );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-dpa-install.php';
DPA_Install::drop();

delete_option( 'dpa_settings' );
delete_option( 'dpa_salt' );
delete_option( 'dpa_db_version' );
delete_option( 'dpa_version' );
delete_option( 'dpa_report_last_sent' );
