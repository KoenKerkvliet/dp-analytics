<?php
/**
 * Periodiek e-mailrapport.
 *
 * Stuurt maandelijks (of wekelijks) een overzichtelijke HTML-mail met de
 * belangrijkste cijfers van de site — inclusief vergelijking met de vorige
 * periode en, op webshops, de omzet. Bedoeld als client-facing rapportage:
 * de klant ziet zwart-op-wit wat zijn website oplevert.
 *
 * Verzending gaat via wp_mail(), dus het respecteert de mailconfiguratie van
 * de site (SMTP e.d.). Een dagelijkse cron-tick bepaalt wanneer er een nieuw
 * rapport klaarstaat; een opgeslagen token voorkomt dubbele verzending.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DPA_Report {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action( 'dpa_report_tick', [ $this, 'maybe_send' ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Periode-berekening                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Bepaal de te rapporteren periode (de laatst afgesloten maand/week) plus
     * de vergelijkingsperiode ervoor. Alles in GMT-strings voor de queries.
     *
     * @return array{token,label,from,to,prev_from,prev_to}
     */
    public function period( $freq ) {
        $tz  = wp_timezone();
        $now = new DateTime( 'now', $tz );

        if ( 'weekly' === $freq ) {
            $to        = ( clone $now )->setTime( 0, 0, 0 );
            $from      = ( clone $to )->modify( '-7 days' );
            $prev_to   = clone $from;
            $prev_from = ( clone $from )->modify( '-7 days' );
            $token     = 'w-' . $from->format( 'Y-m-d' );
            $label     = 'de week van ' . wp_date( 'j', $from->getTimestamp() ) . ' t/m ' . wp_date( 'j F Y', ( clone $to )->modify( '-1 day' )->getTimestamp() );
        } else {
            $first_this = ( clone $now )->modify( 'first day of this month' )->setTime( 0, 0, 0 );
            $from       = ( clone $first_this )->modify( '-1 month' );
            $to         = $first_this;
            $prev_to    = clone $from;
            $prev_from  = ( clone $from )->modify( '-1 month' );
            $token      = 'm-' . $from->format( 'Y-m' );
            $label      = wp_date( 'F Y', $from->getTimestamp() );
        }

        $utc = new DateTimeZone( 'UTC' );
        $fmt = function ( $dt ) use ( $utc ) {
            return ( clone $dt )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
        };

        return [
            'token'     => $token,
            'label'     => $label,
            'from'      => $fmt( $from ),
            'to'        => $fmt( $to ),
            'prev_from' => $fmt( $prev_from ),
            'prev_to'   => $fmt( $prev_to ),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Cron: bepalen of er verzonden moet worden                          */
    /* ------------------------------------------------------------------ */

    public function maybe_send() {
        if ( ! DPA_Settings::val( 'email_reports' ) ) {
            return;
        }
        $freq   = 'weekly' === DPA_Settings::val( 'email_frequency' ) ? 'weekly' : 'monthly';
        $period = $this->period( $freq );

        if ( get_option( 'dpa_report_last_sent' ) === $period['token'] ) {
            return; // deze periode is al verstuurd
        }

        $this->send( $period );
        update_option( 'dpa_report_last_sent', $period['token'], false );
    }

    /* ------------------------------------------------------------------ */
    /*  Data verzamelen + versturen                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Stel het rapport samen en verstuur het. Retourneert of de mail is
     * geaccepteerd (voor de test-knop).
     */
    public function send( $period ) {
        $data       = $this->collect( $period );
        $recipients = DPA_Settings::report_recipients();
        $site       = get_bloginfo( 'name' );

        $subject = sprintf( 'Websitestatistieken %s — %s', $site, $period['label'] );
        $body    = $this->render_email( $data, $period, $site );

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        $sent = false;
        foreach ( $recipients as $to ) {
            $sent = wp_mail( $to, $subject, $body, $headers ) || $sent;
        }
        return $sent;
    }

    private function collect( $period ) {
        $cur  = DPA_Stats::totals( $period['from'], $period['to'] );
        $prev = DPA_Stats::totals( $period['prev_from'], $period['prev_to'] );

        return [
            'totals'    => $cur,
            'prev'      => $prev,
            'top_pages' => DPA_Stats::top_pages( $period['from'], $period['to'], 5 ),
            'top_refs'  => DPA_Stats::top_referrers( $period['from'], $period['to'], 5 ),
            'woo'       => DPA_Woo::active()
                ? DPA_Woo::report( strtotime( $period['from'] . ' UTC' ), strtotime( $period['to'] . ' UTC' ) )
                : null,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Vergelijking                                                       */
    /* ------------------------------------------------------------------ */

    /** Procentuele verandering; null = geen vergelijking mogelijk (was 0). */
    private function pct( $cur, $prev ) {
        if ( $prev <= 0 ) {
            return null;
        }
        return round( ( $cur - $prev ) / $prev * 100 );
    }

    /** HTML-badge voor de verandering (groen omhoog / rood omlaag). */
    private function trend_badge( $cur, $prev ) {
        $pct = $this->pct( $cur, $prev );
        if ( null === $pct ) {
            return '<span style="color:#888;font-size:12px;">nieuw</span>';
        }
        if ( 0 === $pct ) {
            return '<span style="color:#888;font-size:12px;">gelijk</span>';
        }
        $up    = $pct > 0;
        $color = $up ? '#00a32a' : '#d63638';
        $arrow = $up ? '&#9650;' : '&#9660;';
        return sprintf(
            '<span style="color:%s;font-size:12px;font-weight:600;">%s %s%d%%</span>',
            $color, $arrow, $up ? '+' : '', $pct
        );
    }

    /* ------------------------------------------------------------------ */
    /*  HTML-mail                                                          */
    /* ------------------------------------------------------------------ */

    private function render_email( $data, $period, $site ) {
        $t     = $data['totals'];
        $p     = $data['prev'];
        $accent = '#281E5D';

        ob_start();
        ?>
        <div style="margin:0;padding:0;background:#f4f4f6;">
        <div style="max-width:600px;margin:0 auto;padding:24px 0;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1d2327;">

            <div style="background:<?php echo esc_attr( $accent ); ?>;border-radius:12px 12px 0 0;padding:26px 28px;color:#fff;">
                <div style="font-size:13px;opacity:.75;letter-spacing:.04em;text-transform:uppercase;">Websitestatistieken</div>
                <div style="font-size:22px;font-weight:700;margin-top:4px;"><?php echo esc_html( $site ); ?></div>
                <div style="font-size:15px;opacity:.85;margin-top:2px;"><?php echo esc_html( ucfirst( $period['label'] ) ); ?></div>
            </div>

            <div style="background:#fff;padding:24px 28px;">
                <p style="margin:0 0 18px;font-size:15px;line-height:1.5;">Hierbij het overzicht van je website over <strong><?php echo esc_html( $period['label'] ); ?></strong>, met de verandering ten opzichte van de periode ervoor.</p>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
                    <tr>
                        <?php
                        $this->email_kpi( 'Bezoekers', number_format_i18n( $t['visitors'] ), $this->trend_badge( $t['visitors'], $p['visitors'] ) );
                        $this->email_kpi( 'Weergaven', number_format_i18n( $t['views'] ), $this->trend_badge( $t['views'], $p['views'] ) );
                        ?>
                    </tr>
                    <tr>
                        <?php
                        $this->email_kpi( 'Sessies', number_format_i18n( $t['sessions'] ), $this->trend_badge( $t['sessions'], $p['sessions'] ) );
                        $this->email_kpi( 'Bouncepercentage', $t['bounce_rate'] . '%', '' );
                        ?>
                    </tr>
                </table>

                <?php if ( null !== $data['woo'] ) : ?>
                    <?php
                    $w        = $data['woo'];
                    $conv     = $t['sessions'] > 0 ? round( $w['orders'] / $t['sessions'] * 100, 1 ) : 0;
                    $rev_html = wp_strip_all_tags( DPA_Woo::price( $w['revenue'] ) );
                    ?>
                    <h3 style="font-size:15px;margin:24px 0 10px;padding-top:16px;border-top:1px solid #eee;">Webshop</h3>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                        <tr>
                            <?php
                            $this->email_kpi( 'Omzet', esc_html( $rev_html ), '' );
                            $this->email_kpi( 'Bestellingen', number_format_i18n( $w['orders'] ), '' );
                        ?>
                        </tr>
                        <tr>
                            <?php
                            $this->email_kpi( 'Gem. orderwaarde', esc_html( wp_strip_all_tags( DPA_Woo::price( $w['aov'] ) ) ), '' );
                            $this->email_kpi( 'Conversieratio', $conv . '%', '' );
                            ?>
                        </tr>
                    </table>
                <?php endif; ?>

                <?php if ( ! empty( $data['top_pages'] ) ) : ?>
                    <h3 style="font-size:15px;margin:24px 0 8px;padding-top:16px;border-top:1px solid #eee;">Populairste pagina's</h3>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
                        <?php foreach ( $data['top_pages'] as $row ) :
                            $title = '' !== $row->title ? $row->title : $row->url; ?>
                            <tr>
                                <td style="padding:5px 0;border-bottom:1px solid #f0f0f1;"><?php echo esc_html( $title ); ?></td>
                                <td style="padding:5px 0;border-bottom:1px solid #f0f0f1;text-align:right;color:#50575e;white-space:nowrap;"><?php echo esc_html( number_format_i18n( $row->views ) ); ?> weergaven</td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>

                <?php if ( ! empty( $data['top_refs'] ) ) : ?>
                    <h3 style="font-size:15px;margin:24px 0 8px;padding-top:16px;border-top:1px solid #eee;">Verkeersbronnen</h3>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
                        <?php foreach ( $data['top_refs'] as $row ) : ?>
                            <tr>
                                <td style="padding:5px 0;border-bottom:1px solid #f0f0f1;"><?php echo '' !== $row->referrer_host ? esc_html( $row->referrer_host ) : 'direct'; ?></td>
                                <td style="padding:5px 0;border-bottom:1px solid #f0f0f1;text-align:right;color:#50575e;white-space:nowrap;"><?php echo esc_html( number_format_i18n( $row->sessions ) ); ?> sessies</td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>

                <p style="margin:22px 0 0;font-size:12px;color:#999;line-height:1.5;">Deze cijfers zijn cookieloos en privacyvriendelijk verzameld — er worden geen persoonsgegevens van bezoekers opgeslagen.</p>
            </div>

            <div style="background:#fff;border-radius:0 0 12px 12px;border-top:1px solid #eee;padding:16px 28px;text-align:center;font-size:12px;color:#999;">
                Verzorgd door <a href="https://designpixels.nl" style="color:<?php echo esc_attr( $accent ); ?>;text-decoration:none;font-weight:600;">Design Pixels</a> &middot; DP Analytics
            </div>
        </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function email_kpi( $label, $value, $trend ) {
        ?>
        <td width="50%" style="padding:6px;">
            <div style="background:#f7f7f9;border-radius:8px;padding:14px 16px;">
                <div style="font-size:24px;font-weight:700;line-height:1.1;"><?php echo wp_kses_post( $value ); ?></div>
                <div style="font-size:13px;color:#50575e;margin-top:2px;"><?php echo esc_html( $label ); ?> <?php echo wp_kses_post( $trend ); ?></div>
            </div>
        </td>
        <?php
    }
}
