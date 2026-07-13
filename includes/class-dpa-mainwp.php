<?php
/**
 * MainWP-koppeling.
 *
 * Op sites die door een MainWP-dashboard beheerd worden (MainWP Child actief),
 * hangt DP Analytics bij elke sync een compact statistiekblok aan. Het dashboard
 * vangt dat op en toont het in het maandrapport. Zo hoeft er geen aparte API-key
 * of losse verbinding ingesteld te worden — het rijdt mee op de bestaande,
 * geauthenticeerde MainWP-sync.
 *
 * Er stromen alleen geaggregeerde, cookieloze cijfers over; geen bezoekersdata.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DPA_Mainwp {

    private static $instance = null;
    const MONTHS = 13; // laatste 12 volledige maanden + lopende maand

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        // Vuurt tijdens de MainWP Child-sync (stats-opbouw). $information is het
        // sync-antwoord dat naar het dashboard teruggaat; $others is de
        // (optionele) request-data van het dashboard.
        add_filter( 'mainwp_site_sync_others_data', [ $this, 'attach' ], 10, 2 );
    }

    /**
     * Hang het statistiekblok aan het MainWP-sync-antwoord.
     */
    public function attach( $information, $others = [] ) {
        if ( is_array( $information ) ) {
            $information['dpa'] = $this->payload();
        }
        return $information;
    }

    /* ------------------------------------------------------------------ */
    /*  Payload opbouwen (gecached)                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Volledig blok: metadata + maand-buckets. Afgelopen maanden worden
     * persistent gecachet (die veranderen niet meer); alleen de lopende maand
     * wordt telkens opnieuw berekend. Een korte transient voorkomt dat meerdere
     * syncs kort na elkaar alles herberekenen.
     */
    public function payload() {
        $cached = get_transient( 'dpa_mainwp_payload' );
        if ( false !== $cached ) {
            return $cached;
        }

        $buckets = get_option( 'dpa_mainwp_buckets', [] );
        if ( ! is_array( $buckets ) ) {
            $buckets = [];
        }

        $tz      = wp_timezone();
        $cursor  = new DateTime( 'now', $tz );
        $cursor->modify( 'first day of this month' )->setTime( 0, 0, 0 );
        $current = $cursor->format( 'Y-m' );

        $keep = [];
        for ( $i = 0; $i < self::MONTHS; $i++ ) {
            $ym     = $cursor->format( 'Y-m' );
            $keep[] = $ym;
            // Afgelopen maanden uit cache; lopende maand altijd vers.
            if ( $ym === $current || ! isset( $buckets[ $ym ] ) ) {
                $buckets[ $ym ] = $this->build_bucket( $ym );
            }
            $cursor->modify( '-1 month' );
        }

        // Oude buckets buiten het venster opruimen.
        $buckets = array_intersect_key( $buckets, array_flip( $keep ) );
        update_option( 'dpa_mainwp_buckets', $buckets, false );

        $payload = [
            'version'  => DPA_VERSION,
            'currency' => self::currency(),
            'months'   => $buckets,
        ];

        set_transient( 'dpa_mainwp_payload', $payload, 6 * HOUR_IN_SECONDS );
        return $payload;
    }

    /**
     * Statistiek-bucket voor één kalendermaand (Y-m).
     */
    private function build_bucket( $ym ) {
        list( $from, $to, $from_ts, $to_ts, $offset ) = $this->month_window( $ym );

        $t = DPA_Stats::totals( $from, $to );

        $bucket = [
            'views'    => (int) $t['views'],
            'visitors' => (int) $t['visitors'],
            'sessions' => (int) $t['sessions'],
            'bounce'   => (float) $t['bounce_rate'],
            'daily'    => array_values( DPA_Stats::timeseries( $from, $to, 'day', $offset ) ),
            'top_pages' => [],
            'top_refs'  => [],
        ];

        foreach ( DPA_Stats::top_pages( $from, $to, 5 ) as $row ) {
            $bucket['top_pages'][] = [
                'title' => '' !== $row->title ? $row->title : $row->url,
                'views' => (int) $row->views,
            ];
        }
        foreach ( DPA_Stats::top_referrers( $from, $to, 5 ) as $row ) {
            $bucket['top_refs'][] = [
                'host'     => '' !== $row->referrer_host ? $row->referrer_host : 'direct',
                'sessions' => (int) $row->sessions,
            ];
        }

        if ( DPA_Woo::active() ) {
            $woo               = DPA_Woo::report( $from_ts, $to_ts );
            $bucket['revenue'] = (float) $woo['revenue'];
            $bucket['orders']  = (int) $woo['orders'];
            $bucket['aov']     = (float) $woo['aov'];
        }

        return $bucket;
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Venster van een kalendermaand: GMT-strings + timestamps + site-offset.
     *
     * @return array{0:string,1:string,2:int,3:int,4:float}
     */
    private function month_window( $ym ) {
        $tz    = wp_timezone();
        $start = DateTime::createFromFormat( 'Y-m-d H:i:s', $ym . '-01 00:00:00', $tz );
        $start->modify( 'first day of this month' )->setTime( 0, 0, 0 );
        $end   = ( clone $start )->modify( 'first day of next month' );

        $utc = new DateTimeZone( 'UTC' );
        $g   = function ( $dt ) use ( $utc ) {
            return ( clone $dt )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
        };

        return [
            $g( $start ),
            $g( $end ),
            $start->getTimestamp(),
            $end->getTimestamp(),
            (float) get_option( 'gmt_offset' ),
        ];
    }

    private static function currency() {
        if ( function_exists( 'get_woocommerce_currency' ) ) {
            return [
                'code'   => get_woocommerce_currency(),
                'symbol' => html_entity_decode( get_woocommerce_currency_symbol() ),
            ];
        }
        return [ 'code' => '', 'symbol' => '' ];
    }

    /**
     * Wis de cache zodat de volgende sync een verse payload opbouwt.
     */
    public static function flush() {
        delete_transient( 'dpa_mainwp_payload' );
    }
}
