<?php
/**
 * Eenmalige import van historische data uit Independent Analytics (IA).
 *
 * IA slaat zijn cijfers op in eigen, genormaliseerde tabellen. Deze importer
 * leest de views/sessies/pagina's van vóór de eerste eigen meting en zet ze om
 * naar de DP Analytics-tabellen, zodat de historie behouden blijft bij het
 * overstappen. Tijden zijn in beide plugins GMT — geen conversie nodig.
 *
 * WooCommerce-omzet wordt NIET gemigreerd: DP Analytics leest die live uit
 * WooCommerce, dus historische omzet is sowieso al beschikbaar.
 *
 * Alleen data van vóór de eerste DP-Analytics-meting wordt geïmporteerd, zodat
 * er geen dubbeltellingen ontstaan in de overlap-periode. De import kan maar
 * één keer draaien (guard-optie).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DPA_Import_IA {

    const DONE_OPTION = 'dpa_ia_import';

    private static function t( $name ) {
        global $wpdb;
        return $wpdb->prefix . 'independent_analytics_' . $name;
    }

    public static function available() {
        global $wpdb;
        $t = self::t( 'views' );
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
    }

    public static function already_done() {
        return (bool) get_option( self::DONE_OPTION );
    }

    public static function result() {
        return get_option( self::DONE_OPTION );
    }

    /**
     * Grens: de vroegste eigen meting (GMT). Alles in IA vóór dit moment wordt
     * geïmporteerd. Heeft DP Analytics nog geen data, dan is de grens "nu" en
     * komt de volledige IA-historie mee.
     */
    private static function cutoff() {
        global $wpdb;
        $c = $wpdb->get_var( 'SELECT MIN(viewed_at) FROM ' . DPA_Install::views_table() ); // phpcs:ignore
        return $c ? $c : current_time( 'mysql', true );
    }

    /**
     * Voorbeeld: hoeveel er geïmporteerd zou worden.
     */
    public static function preview() {
        if ( ! self::available() ) {
            return null;
        }
        global $wpdb;
        $cutoff = self::cutoff();
        $iv     = self::t( 'views' );
        $is     = self::t( 'sessions' );

        return [
            'cutoff'   => $cutoff,
            'views'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$iv} WHERE viewed_at < %s", $cutoff ) ), // phpcs:ignore
            'sessions' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$is} WHERE created_at < %s", $cutoff ) ), // phpcs:ignore
        ];
    }

    /**
     * Voer de import uit.
     *
     * @return array|WP_Error
     */
    public static function run() {
        if ( ! self::available() ) {
            return new WP_Error( 'no_ia', 'Independent Analytics is niet gevonden op deze site.' );
        }
        if ( self::already_done() ) {
            return new WP_Error( 'done', 'De import is al eerder uitgevoerd.' );
        }

        global $wpdb;
        $cutoff = self::cutoff();

        $iv   = self::t( 'views' );
        $is   = self::t( 'sessions' );
        $ir   = self::t( 'resources' );
        $iref = self::t( 'referrers' );
        $ivis = self::t( 'visitors' );

        $dv = DPA_Install::views_table();
        $ds = DPA_Install::sessions_table();

        // 1. Resources: IA-id -> DPA-id.
        $rmap      = [];
        $resources = $wpdb->get_results( "SELECT id, cached_url, cached_title, post_type, singular_id FROM {$ir}" ); // phpcs:ignore
        foreach ( $resources as $r ) {
            if ( empty( $r->cached_url ) ) {
                continue;
            }
            $rmap[ (int) $r->id ] = self::resource_id( $r->cached_url, $r->cached_title, (int) $r->singular_id, (string) $r->post_type );
        }

        // 2. Sessies vóór de grens: IA-session_id -> DPA-id.
        $smap     = [];
        $scount   = 0;
        $sessions = $wpdb->get_results( $wpdb->prepare( "
            SELECT s.session_id, s.created_at, s.total_views,
                   vis.hash AS vhash, ref.domain AS rdomain
            FROM {$is} s
            LEFT JOIN {$ivis} vis ON s.visitor_id = vis.visitor_id
            LEFT JOIN {$iref} ref ON s.referrer_id = ref.id
            WHERE s.created_at < %s", $cutoff ) ); // phpcs:ignore

        foreach ( $sessions as $s ) {
            $host = strtolower( preg_replace( '/^www\./', '', (string) $s->rdomain ) );
            $wpdb->insert( $ds, [ // phpcs:ignore
                'visitor_hash'        => $s->vhash ? substr( $s->vhash, 0, 32 ) : substr( md5( 'ia-' . $s->session_id ), 0, 32 ),
                'created_at'          => $s->created_at,
                'last_seen_at'        => $s->created_at,
                'referrer_host'       => substr( $host, 0, 255 ),
                'referrer_type'       => self::classify( $host ),
                'landing_resource_id' => 0,
                'views'               => (int) $s->total_views,
            ] );
            $smap[ (int) $s->session_id ] = (int) $wpdb->insert_id;
            $scount++;
        }

        // 3. Views vóór de grens (batch-insert voor snelheid).
        $vcount = 0;
        $batch  = [];
        $rows   = $wpdb->get_results( $wpdb->prepare( "SELECT resource_id, session_id, viewed_at FROM {$iv} WHERE viewed_at < %s", $cutoff ) ); // phpcs:ignore
        foreach ( $rows as $v ) {
            if ( ! isset( $smap[ (int) $v->session_id ] ) || ! isset( $rmap[ (int) $v->resource_id ] ) ) {
                continue;
            }
            $batch[] = $wpdb->prepare( '(%d,%d,%s)', $smap[ (int) $v->session_id ], $rmap[ (int) $v->resource_id ], $v->viewed_at );
            $vcount++;
            if ( count( $batch ) >= 500 ) {
                $wpdb->query( "INSERT INTO {$dv} (session_id,resource_id,viewed_at) VALUES " . implode( ',', $batch ) ); // phpcs:ignore
                $batch = [];
            }
        }
        if ( $batch ) {
            $wpdb->query( "INSERT INTO {$dv} (session_id,resource_id,viewed_at) VALUES " . implode( ',', $batch ) ); // phpcs:ignore
        }

        $result = [
            'time'      => time(),
            'cutoff'    => $cutoff,
            'views'     => $vcount,
            'sessions'  => $scount,
            'resources' => count( $rmap ),
        ];
        update_option( self::DONE_OPTION, $result, false );

        // Maand-buckets opnieuw laten opbouwen zodat de historie in het rapport komt.
        delete_option( 'dpa_mainwp_buckets' );
        delete_transient( 'dpa_mainwp_payload' );

        return $result;
    }

    /* ------------------------------------------------------------------ */

    private static function resource_id( $url, $title, $post_id, $post_type ) {
        global $wpdb;
        $parts = wp_parse_url( $url );
        $path  = isset( $parts['path'] ) ? $parts['path'] : '/';
        $norm  = home_url( $path );
        $hash  = md5( $norm );
        $t     = DPA_Install::resources_table();

        $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE url_hash = %s", $hash ) ); // phpcs:ignore
        if ( $id ) {
            return (int) $id;
        }
        $wpdb->insert( $t, [ // phpcs:ignore
            'url_hash'  => $hash,
            'url'       => $norm,
            'post_id'   => $post_id,
            'post_type' => substr( $post_type, 0, 50 ),
            'title'     => substr( (string) $title, 0, 255 ),
        ] );
        return (int) $wpdb->insert_id;
    }

    /** Referrer-type afleiden uit de host (zelfde categorieën als de tracker). */
    private static function classify( $host ) {
        if ( '' === $host ) {
            return 'direct';
        }
        $site = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
        if ( $host === $site ) {
            return 'internal';
        }
        $search = [ 'google.', 'bing.', 'duckduckgo.', 'yahoo.', 'ecosia.', 'startpage.', 'yandex.', 'baidu.', 'brave.' ];
        $social = [ 'facebook.', 'instagram.', 'linkedin.', 'lnkd.in', 't.co', 'twitter.', 'x.com', 'youtube.', 'pinterest.', 'reddit.', 'tiktok.', 'wa.me', 'whatsapp.' ];
        foreach ( $search as $n ) {
            if ( false !== strpos( $host, $n ) ) {
                return 'search';
            }
        }
        foreach ( $social as $n ) {
            if ( false !== strpos( $host, $n ) ) {
                return 'social';
            }
        }
        return 'referral';
    }
}
