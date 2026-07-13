<?php
/**
 * Database-installatie en -onderhoud.
 *
 * Drie tabellen (bewust compact gehouden):
 *   dpa_resources  — dimensietabel: één rij per unieke pagina/URL
 *   dpa_sessions   — één rij per bezoek (30-min venster), cookieless bezoeker-hash
 *   dpa_views      — één rij per paginaweergave, gekoppeld aan sessie + resource
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DPA_Install {

    const DB_VERSION = 1;

    public static function resources_table() {
        global $wpdb;
        return $wpdb->prefix . 'dpa_resources';
    }

    public static function sessions_table() {
        global $wpdb;
        return $wpdb->prefix . 'dpa_sessions';
    }

    public static function views_table() {
        global $wpdb;
        return $wpdb->prefix . 'dpa_views';
    }

    /**
     * Tabellen aanmaken/bijwerken via dbDelta (idempotent).
     */
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $res     = self::resources_table();
        $ses     = self::sessions_table();
        $views   = self::views_table();

        // Resources: unieke pagina's. url_hash = md5 van de genormaliseerde URL.
        dbDelta( "CREATE TABLE {$res} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            url_hash CHAR(32) NOT NULL,
            url VARCHAR(2083) NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            post_type VARCHAR(50) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY url_hash (url_hash),
            KEY post_id (post_id)
        ) {$charset};" );

        // Sessions: één bezoek. visitor_hash is per dag roterend (cookieless).
        dbDelta( "CREATE TABLE {$ses} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_hash CHAR(32) NOT NULL,
            created_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            referrer_host VARCHAR(255) NOT NULL DEFAULT '',
            referrer_type VARCHAR(20) NOT NULL DEFAULT 'direct',
            landing_resource_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            views INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY visitor_hash (visitor_hash),
            KEY created_at (created_at),
            KEY last_seen_at (last_seen_at)
        ) {$charset};" );

        // Views: elke paginaweergave.
        dbDelta( "CREATE TABLE {$views} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            resource_id BIGINT UNSIGNED NOT NULL,
            viewed_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY session_id (session_id),
            KEY resource_id (resource_id),
            KEY viewed_at (viewed_at)
        ) {$charset};" );

        update_option( 'dpa_db_version', self::DB_VERSION, false );
    }

    /**
     * Verwijder data ouder dan $days dagen. 0 = onbeperkt bewaren.
     */
    public static function prune( $days ) {
        if ( $days <= 0 ) {
            return;
        }
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

        $views = self::views_table();
        $ses   = self::sessions_table();

        // Eerst de views van verlopen sessies, dan de sessies zelf.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare(
            "DELETE v FROM {$views} v INNER JOIN {$ses} s ON v.session_id = s.id WHERE s.created_at < %s",
            $cutoff
        ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$ses} WHERE created_at < %s", $cutoff ) );
        // phpcs:enable
    }

    /**
     * Alles verwijderen (uninstall).
     */
    public static function drop() {
        global $wpdb;
        foreach ( [ self::views_table(), self::sessions_table(), self::resources_table() ] as $t ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$t}" ); // phpcs:ignore
        }
    }
}
