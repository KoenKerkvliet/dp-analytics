<?php
/**
 * Querylaag: aggregaties voor het dashboard. Alle tijden in GMT; de vergelijking
 * gebeurt op basis van een [from, to]-venster (mysql-datetime strings, GMT).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DPA_Stats {

    /**
     * Kern-KPI's binnen het venster.
     *
     * @return array{views:int,visitors:int,sessions:int,bounce_rate:float}
     */
    public static function totals( $from, $to ) {
        global $wpdb;
        $views = DPA_Install::views_table();
        $ses   = DPA_Install::sessions_table();

        $view_count = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
            "SELECT COUNT(*) FROM {$views} WHERE viewed_at >= %s AND viewed_at < %s", $from, $to
        ) );

        $row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
            "SELECT COUNT(*) AS sessions, COUNT(DISTINCT visitor_hash) AS visitors,
                    SUM(CASE WHEN views <= 1 THEN 1 ELSE 0 END) AS bounces
             FROM {$ses} WHERE created_at >= %s AND created_at < %s", $from, $to
        ) );

        $sessions = $row ? (int) $row->sessions : 0;
        $bounces  = $row ? (int) $row->bounces : 0;

        return [
            'views'       => $view_count,
            'visitors'    => $row ? (int) $row->visitors : 0,
            'sessions'    => $sessions,
            'bounce_rate' => $sessions > 0 ? round( $bounces / $sessions * 100, 1 ) : 0.0,
        ];
    }

    /**
     * Weergaven per tijdseenheid voor de grafiek.
     *
     * @param string $unit 'hour' of 'day'
     * @return array [ label => count ] met een doorlopende reeks (ook nullen)
     */
    public static function timeseries( $from, $to, $unit = 'day', $offset_hours = 0 ) {
        global $wpdb;
        $views  = DPA_Install::views_table();
        $format = 'hour' === $unit ? '%Y-%m-%d %H:00:00' : '%Y-%m-%d';

        // De opgeslagen tijden zijn GMT; met de site-offset schuiven we de
        // dag-/uurgrenzen naar lokale tijd zodat "vandaag" ook echt vandaag is.
        $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT DATE_FORMAT(viewed_at + INTERVAL %d MINUTE, %s) AS bucket, COUNT(*) AS c
             FROM {$views} WHERE viewed_at >= %s AND viewed_at < %s
             GROUP BY bucket", (int) round( $offset_hours * 60 ), $format, $from, $to
        ), OBJECT_K );

        // Doorlopende reeks opbouwen (lege buckets = 0), met lokale labels.
        $series = [];
        $step   = 'hour' === $unit ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
        $shift  = (int) round( $offset_hours * 3600 );
        $start  = strtotime( $from . ' UTC' ) + $shift;
        $end    = strtotime( $to . ' UTC' ) + $shift;
        for ( $t = $start; $t < $end; $t += $step ) {
            $key   = 'hour' === $unit ? gmdate( 'Y-m-d H:00:00', $t ) : gmdate( 'Y-m-d', $t );
            $label = 'hour' === $unit ? gmdate( 'H:i', $t ) : gmdate( 'j M', $t );
            $series[ $label ] = isset( $rows[ $key ] ) ? (int) $rows[ $key ]->c : 0;
        }
        return $series;
    }

    /**
     * Best bekeken pagina's binnen het venster.
     */
    public static function top_pages( $from, $to, $limit = 15 ) {
        global $wpdb;
        $views = DPA_Install::views_table();
        $res   = DPA_Install::resources_table();

        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT r.id, r.url, r.post_id, r.title, COUNT(*) AS views
             FROM {$views} v INNER JOIN {$res} r ON v.resource_id = r.id
             WHERE v.viewed_at >= %s AND v.viewed_at < %s
             GROUP BY r.id ORDER BY views DESC LIMIT %d", $from, $to, $limit
        ) );
    }

    /**
     * Verkeersbronnen, gegroepeerd per host (met het type erbij).
     */
    public static function top_referrers( $from, $to, $limit = 15 ) {
        global $wpdb;
        $ses = DPA_Install::sessions_table();

        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT referrer_host, referrer_type, COUNT(*) AS sessions
             FROM {$ses}
             WHERE created_at >= %s AND created_at < %s AND referrer_type != 'internal'
             GROUP BY referrer_host, referrer_type ORDER BY sessions DESC LIMIT %d", $from, $to, $limit
        ) );
    }

    /**
     * Verdeling per bron-type (direct/search/social/referral) voor het venster.
     */
    public static function referrer_types( $from, $to ) {
        global $wpdb;
        $ses = DPA_Install::sessions_table();

        $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT referrer_type, COUNT(*) AS sessions
             FROM {$ses} WHERE created_at >= %s AND created_at < %s
             GROUP BY referrer_type ORDER BY sessions DESC", $from, $to
        ) );
        $out = [];
        foreach ( $rows as $r ) {
            $out[ $r->referrer_type ] = (int) $r->sessions;
        }
        return $out;
    }

    /**
     * Totaal aantal weergaven voor een specifieke post (alle tijd) — voor de
     * kolom in de berichten-/pagina-lijst.
     */
    public static function views_for_post( $post_id ) {
        global $wpdb;
        $views = DPA_Install::views_table();
        $res   = DPA_Install::resources_table();

        return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
            "SELECT COUNT(*) FROM {$views} v INNER JOIN {$res} r ON v.resource_id = r.id WHERE r.post_id = %d",
            $post_id
        ) );
    }

    /**
     * Totale weergaven per post_id in één query (voor de lijstweergave).
     *
     * @return array [ post_id => views ]
     */
    public static function views_for_posts( array $post_ids ) {
        if ( empty( $post_ids ) ) {
            return [];
        }
        global $wpdb;
        $views = DPA_Install::views_table();
        $res   = DPA_Install::resources_table();

        $ids     = implode( ',', array_map( 'intval', $post_ids ) );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT r.post_id, COUNT(*) AS views
             FROM {$views} v INNER JOIN {$res} r ON v.resource_id = r.id
             WHERE r.post_id IN ({$ids}) GROUP BY r.post_id"
        );
        // phpcs:enable
        $out = [];
        foreach ( $rows as $r ) {
            $out[ (int) $r->post_id ] = (int) $r->views;
        }
        return $out;
    }
}
