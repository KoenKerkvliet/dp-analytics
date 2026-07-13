<?php
/**
 * Tracker: laadt de JavaScript-beacon op de frontend en verwerkt de binnenkomende
 * hits via een REST-endpoint. Cookieless en cache-safe:
 *
 *  - De beacon draait client-side, dus page-caches (LiteSpeed e.d.) zitten niet
 *    in de weg en bots die geen JS uitvoeren tellen niet mee.
 *  - De bezoeker wordt geïdentificeerd via een per-dag roterende hash van
 *    IP + user-agent + sitesleutel. Het IP wordt nooit opgeslagen, alleen de
 *    hash — dus geen persoonsgegevens en geen cookie-toestemming nodig.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DPA_Tracker {

    private static $instance = null;
    const SESSION_WINDOW = 1800; // 30 minuten

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest' ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Frontend: beacon laden                                             */
    /* ------------------------------------------------------------------ */

    private function should_track_request() {
        if ( ! DPA_Settings::val( 'enabled' ) ) {
            return false;
        }
        if ( is_admin() || is_preview() || is_customize_preview() ) {
            return false;
        }
        // Ingelogde bewerkers uitsluiten (schone bezoekerscijfers). Dit gebeurt
        // bij het laden van de pagina — caches serveren ingelogde gebruikers
        // normaal gesproken niet uit cache, dus dit is betrouwbaar.
        if ( DPA_Settings::val( 'exclude_editors' ) && current_user_can( 'edit_posts' ) ) {
            return false;
        }
        return apply_filters( 'dpa_should_track', true );
    }

    public function enqueue() {
        if ( ! $this->should_track_request() ) {
            return;
        }
        wp_enqueue_script( 'dpa', DPA_URL . 'assets/js/dpa.js', [], DPA_VERSION, true );
        wp_localize_script( 'dpa', 'dpaConfig', [
            'endpoint' => esc_url_raw( rest_url( 'dpa/v1/hit' ) ),
        ] );
    }

    /* ------------------------------------------------------------------ */
    /*  REST: hit verwerken                                                */
    /* ------------------------------------------------------------------ */

    public function register_rest() {
        register_rest_route( 'dpa/v1', '/hit', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_hit' ],
            'permission_callback' => '__return_true', // publiek: elke bezoeker meldt zijn eigen weergave
        ] );
    }

    public function handle_hit( WP_REST_Request $request ) {
        if ( ! DPA_Settings::val( 'enabled' ) ) {
            return new WP_REST_Response( [ 'ok' => false ], 200 );
        }

        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 512 ) : '';
        if ( $this->is_bot( $ua ) ) {
            return new WP_REST_Response( [ 'ok' => false ], 200 );
        }

        $p       = $request->get_json_params();
        $url     = isset( $p['url'] ) ? esc_url_raw( (string) $p['url'] ) : '';
        $referer = isset( $p['referrer'] ) ? esc_url_raw( (string) $p['referrer'] ) : '';
        $title   = isset( $p['title'] ) ? sanitize_text_field( (string) $p['title'] ) : '';

        // De URL moet van deze site zijn (anti-spam / cross-site vervuiling).
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $url_host  = $url ? wp_parse_url( $url, PHP_URL_HOST ) : '';
        if ( ! $url || strcasecmp( (string) $url_host, (string) $site_host ) !== 0 ) {
            return new WP_REST_Response( [ 'ok' => false ], 200 );
        }

        $now         = current_time( 'mysql', true ); // GMT
        $resource_id = $this->resolve_resource( $url, $title );
        $visitor     = $this->visitor_hash( $ua );

        $session_id = $this->attach_session( $visitor, $referer, $site_host, $resource_id, $now );
        $this->insert_view( $session_id, $resource_id, $now );

        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }

    /* ------------------------------------------------------------------ */
    /*  Bezoeker-identificatie (cookieless)                                */
    /* ------------------------------------------------------------------ */

    private function client_ip() {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = explode( ',', (string) $_SERVER[ $key ] )[0];
                return trim( $ip );
            }
        }
        return '';
    }

    /**
     * Per-dag roterende hash. Doordat de datum meedraait, is een bezoeker niet
     * over meerdere dagen te volgen — bewust, voor privacy. "Unieke bezoekers"
     * betekent dus feitelijk "unieke bezoekers per dag".
     */
    private function visitor_hash( $ua ) {
        $salt = (string) get_option( 'dpa_salt' );
        $day  = gmdate( 'Y-m-d' );
        return md5( $salt . '|' . $day . '|' . $this->client_ip() . '|' . $ua );
    }

    /* ------------------------------------------------------------------ */
    /*  Resource (pagina) opzoeken of aanmaken                             */
    /* ------------------------------------------------------------------ */

    private function normalize_url( $url ) {
        $parts = wp_parse_url( $url );
        $path  = isset( $parts['path'] ) ? $parts['path'] : '/';
        // Query en fragment weglaten zodat weergaven per pagina groeperen.
        return home_url( $path );
    }

    private function resolve_resource( $url, $title ) {
        global $wpdb;
        $table  = DPA_Install::resources_table();
        $norm   = $this->normalize_url( $url );
        $hash   = md5( $norm );

        $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s", $hash ) ); // phpcs:ignore
        if ( $id ) {
            return (int) $id;
        }

        $post_id   = url_to_postid( $norm );
        $post_type = $post_id ? (string) get_post_type( $post_id ) : '';
        if ( '' === $title ) {
            $title = $post_id ? get_the_title( $post_id ) : trailingslashit( (string) wp_parse_url( $norm, PHP_URL_PATH ) );
        }

        $wpdb->insert( $table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'url_hash'  => $hash,
            'url'       => $norm,
            'post_id'   => $post_id,
            'post_type' => substr( $post_type, 0, 50 ),
            'title'     => substr( (string) $title, 0, 255 ),
        ] );
        return (int) $wpdb->insert_id;
    }

    /* ------------------------------------------------------------------ */
    /*  Sessie: bestaande openen of nieuwe starten                         */
    /* ------------------------------------------------------------------ */

    private function attach_session( $visitor, $referer, $site_host, $resource_id, $now ) {
        global $wpdb;
        $table  = DPA_Install::sessions_table();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - self::SESSION_WINDOW );

        $existing = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
            "SELECT id FROM {$table} WHERE visitor_hash = %s AND last_seen_at >= %s ORDER BY last_seen_at DESC LIMIT 1",
            $visitor, $cutoff
        ) );

        if ( $existing ) {
            $wpdb->query( $wpdb->prepare( // phpcs:ignore
                "UPDATE {$table} SET last_seen_at = %s, views = views + 1 WHERE id = %d",
                $now, $existing->id
            ) );
            return (int) $existing->id;
        }

        list( $ref_host, $ref_type ) = $this->classify_referrer( $referer, $site_host );

        $wpdb->insert( $table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'visitor_hash'        => $visitor,
            'created_at'          => $now,
            'last_seen_at'        => $now,
            'referrer_host'       => substr( $ref_host, 0, 255 ),
            'referrer_type'       => $ref_type,
            'landing_resource_id' => $resource_id,
            'views'               => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insert_view( $session_id, $resource_id, $now ) {
        global $wpdb;
        $wpdb->insert( DPA_Install::views_table(), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'session_id'  => $session_id,
            'resource_id' => $resource_id,
            'viewed_at'   => $now,
        ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Referrer-classificatie                                             */
    /* ------------------------------------------------------------------ */

    private function classify_referrer( $referer, $site_host ) {
        if ( '' === $referer ) {
            return [ '', 'direct' ];
        }
        $host = strtolower( (string) wp_parse_url( $referer, PHP_URL_HOST ) );
        $host = preg_replace( '/^www\./', '', $host );
        if ( '' === $host ) {
            return [ '', 'direct' ];
        }
        if ( strcasecmp( $host, preg_replace( '/^www\./', '', (string) $site_host ) ) === 0 ) {
            return [ $host, 'internal' ];
        }

        $search = [ 'google.', 'bing.com', 'duckduckgo.com', 'yahoo.', 'ecosia.org', 'startpage.com', 'yandex.', 'baidu.com', 'search.brave.com' ];
        $social = [ 'facebook.com', 'instagram.com', 'l.instagram.com', 'lm.facebook.com', 'linkedin.com', 'lnkd.in', 't.co', 'twitter.com', 'x.com', 'youtube.com', 'pinterest.', 'reddit.com', 'tiktok.com', 'wa.me', 'whatsapp.com' ];

        foreach ( $search as $needle ) {
            if ( false !== strpos( $host, $needle ) ) {
                return [ $host, 'search' ];
            }
        }
        foreach ( $social as $needle ) {
            if ( false !== strpos( $host, $needle ) ) {
                return [ $host, 'social' ];
            }
        }
        return [ $host, 'referral' ];
    }

    private function is_bot( $ua ) {
        if ( '' === $ua ) {
            return true; // geen user-agent = vrijwel zeker geen echte bezoeker
        }
        return (bool) preg_match( '/bot|crawl|spider|slurp|curl|wget|headless|python|monitor|preview|facebookexternalhit|embedly|lighthouse|pingdom|gtmetrix|uptime/i', $ua );
    }
}
