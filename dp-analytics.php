<?php
/**
 * Plugin Name: DP Analytics
 * Description: Privacy-vriendelijke, cookieless website-statistieken voor WordPress. Telt weergaven, bezoekers, sessies en verkeersbronnen zonder cookies en zonder persoonsgegevens op te slaan — dus geen cookiebanner-toestemming nodig. Cache-safe via een lichte JavaScript-beacon.
 * Version: 1.3.1
 * Author: Design Pixels
 * Author URI: https://designpixels.nl
 * Text Domain: dp-analytics
 * GitHub Plugin URI: KoenKerkvliet/dp-analytics
 * Primary Branch: main
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DPA_VERSION', '1.3.1' );
define( 'DPA_PATH', plugin_dir_path( __FILE__ ) );
define( 'DPA_URL', plugin_dir_url( __FILE__ ) );

require_once DPA_PATH . 'includes/class-dpa-install.php';
require_once DPA_PATH . 'includes/class-dpa-settings.php';
require_once DPA_PATH . 'includes/class-dpa-tracker.php';
require_once DPA_PATH . 'includes/class-dpa-stats.php';
require_once DPA_PATH . 'includes/class-dpa-woo.php';
require_once DPA_PATH . 'includes/class-dpa-report.php';
require_once DPA_PATH . 'includes/class-dpa-mainwp.php';
require_once DPA_PATH . 'includes/class-dpa-admin.php';

/**
 * Activatie: databasetabellen aanmaken + salt genereren.
 */
register_activation_hook( __FILE__, function () {
    DPA_Install::install();

    if ( ! get_option( 'dpa_settings' ) ) {
        add_option( 'dpa_settings', DPA_Settings::defaults() );
    }
    if ( ! get_option( 'dpa_salt' ) ) {
        add_option( 'dpa_salt', wp_generate_password( 32, false, false ) );
    }
    if ( ! wp_next_scheduled( 'dpa_prune' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dpa_prune' );
    }
    if ( ! wp_next_scheduled( 'dpa_report_tick' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dpa_report_tick' );
    }
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'dpa_prune' );
    wp_clear_scheduled_hook( 'dpa_report_tick' );
} );

add_action( 'plugins_loaded', function () {
    DPA_Tracker::instance()->init();
    DPA_Report::instance()->init();
    DPA_Mainwp::instance()->init();

    if ( is_admin() ) {
        DPA_Admin::instance()->init();
    }
} );

/**
 * Lichte upgrade-routine: draait éénmalig na een versiewijziging (bijv. na een
 * Git Updater-update, waarbij de activatie-hook niet fired). Zorgt dat de
 * tabellen bijgewerkt zijn en de cron bestaat.
 */
add_action( 'admin_init', function () {
    if ( get_option( 'dpa_version' ) === DPA_VERSION ) {
        return;
    }
    DPA_Install::install();
    if ( ! wp_next_scheduled( 'dpa_prune' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dpa_prune' );
    }
    if ( ! wp_next_scheduled( 'dpa_report_tick' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dpa_report_tick' );
    }
    update_option( 'dpa_version', DPA_VERSION, false );
} );

/**
 * Dagelijkse opruiming volgens de ingestelde bewaartermijn.
 */
add_action( 'dpa_prune', function () {
    DPA_Install::prune( (int) DPA_Settings::val( 'retention_days' ) );
} );
