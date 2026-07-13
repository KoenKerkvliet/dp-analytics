<?php
/**
 * Admin: dashboard-pagina, instellingen, weergaven-kolom en dashboard-widget.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DPA_Admin {

    private static $instance = null;
    private $hook = '';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action( 'admin_menu', [ $this, 'menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
        add_action( 'admin_post_dpa_save_settings', [ $this, 'save_settings' ] );

        // Weergaven-kolom in de lijst van pagina's/berichten.
        foreach ( [ 'page', 'post' ] as $type ) {
            add_filter( "manage_{$type}_posts_columns", [ $this, 'add_views_column' ] );
            add_action( "manage_{$type}_posts_custom_column", [ $this, 'render_views_column' ], 10, 2 );
        }

        // Overzicht-widget op het hoofd-dashboard.
        if ( DPA_Settings::val( 'dashboard_widget' ) ) {
            add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
        }
    }

    public function menu() {
        $this->hook = add_menu_page(
            'DP Analytics',
            'Statistieken',
            'manage_options',
            'dp-analytics',
            [ $this, 'render_page' ],
            'dashicons-chart-bar',
            3
        );
    }

    public function assets( $hook ) {
        if ( $hook !== $this->hook ) {
            return;
        }
        wp_enqueue_style( 'dpa-admin', DPA_URL . 'assets/css/dpa-admin.css', [], DPA_VERSION );
    }

    /* ------------------------------------------------------------------ */
    /*  Periode-afhandeling                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Vertaal de gekozen periode naar een [from, to]-venster in GMT plus de
     * grafiek-eenheid en site-offset.
     */
    private function resolve_period( $period ) {
        $offset = (float) get_option( 'gmt_offset' ); // uren t.o.v. GMT
        $tz     = wp_timezone();
        $now    = new DateTime( 'now', $tz );

        $start = clone $now;
        $unit  = 'day';

        switch ( $period ) {
            case 'today':
                $start->setTime( 0, 0, 0 );
                $unit = 'hour';
                break;
            case '30days':
                $start->modify( '-29 days' )->setTime( 0, 0, 0 );
                break;
            case '12months':
                $start->modify( '-11 months' )->modify( 'first day of this month' )->setTime( 0, 0, 0 );
                break;
            case '7days':
            default:
                $period = '7days';
                $start->modify( '-6 days' )->setTime( 0, 0, 0 );
                break;
        }

        // Naar GMT-strings voor de queries.
        $from = clone $start;
        $to   = clone $now;
        $from->setTimezone( new DateTimeZone( 'UTC' ) );
        $to->setTimezone( new DateTimeZone( 'UTC' ) );

        return [
            'period' => $period,
            'from'   => $from->format( 'Y-m-d H:i:s' ),
            'to'     => $to->format( 'Y-m-d H:i:s' ),
            'unit'   => $unit,
            'offset' => $offset,
        ];
    }

    private function periods() {
        return [
            'today'    => 'Vandaag',
            '7days'    => 'Laatste 7 dagen',
            '30days'   => 'Laatste 30 dagen',
            '12months' => 'Laatste 12 maanden',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Dashboard                                                          */
    /* ------------------------------------------------------------------ */

    public function render_page() {
        $tab = isset( $_GET['tab'] ) && 'settings' === $_GET['tab'] ? 'settings' : 'dashboard';
        ?>
        <div class="wrap dpa-wrap">
            <h1>DP Analytics <small style="font-size:.55em;color:#888;">v<?php echo esc_html( DPA_VERSION ); ?> &mdash; Design Pixels</small></h1>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=dp-analytics' ) ); ?>" class="nav-tab <?php echo 'dashboard' === $tab ? 'nav-tab-active' : ''; ?>">Dashboard</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=dp-analytics&tab=settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">Instellingen</a>
            </h2>

            <?php
            if ( 'settings' === $tab ) {
                $this->render_settings();
            } else {
                $this->render_dashboard();
            }
            ?>
        </div>
        <?php
    }

    private function render_dashboard() {
        $requested = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : '7days';
        $p         = $this->resolve_period( $requested );

        $totals   = DPA_Stats::totals( $p['from'], $p['to'] );
        $series   = DPA_Stats::timeseries( $p['from'], $p['to'], $p['unit'], $p['offset'] );
        $pages    = DPA_Stats::top_pages( $p['from'], $p['to'], 15 );
        $refs     = DPA_Stats::top_referrers( $p['from'], $p['to'], 12 );
        $types    = DPA_Stats::referrer_types( $p['from'], $p['to'] );

        $woo      = DPA_Woo::active() ? DPA_Woo::report( strtotime( $p['from'] . ' UTC' ), strtotime( $p['to'] . ' UTC' ) ) : null;
        ?>
        <div class="dpa-periods">
            <?php foreach ( $this->periods() as $key => $label ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=dp-analytics&period=' . $key ) ); ?>"
                   class="button <?php echo $key === $p['period'] ? 'button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </div>

        <div class="dpa-kpis">
            <?php
            $this->kpi( 'Weergaven', $totals['views'] );
            $this->kpi( 'Bezoekers', $totals['visitors'], 'unieke bezoekers per dag' );
            $this->kpi( 'Sessies', $totals['sessions'] );
            $this->kpi( 'Bouncepercentage', $totals['bounce_rate'] . '%', 'sessies met één weergave' );
            ?>
        </div>

        <div class="dpa-card">
            <h2>Weergaven over tijd</h2>
            <?php $this->render_chart( $series ); ?>
        </div>

        <div class="dpa-columns">
            <div class="dpa-card">
                <h2>Populairste pagina's</h2>
                <?php if ( $pages ) : ?>
                    <table class="widefat striped">
                        <thead><tr><th>Pagina</th><th class="dpa-num">Weergaven</th></tr></thead>
                        <tbody>
                        <?php foreach ( $pages as $row ) :
                            $title = '' !== $row->title ? $row->title : $row->url;
                            ?>
                            <tr>
                                <td><a href="<?php echo esc_url( $row->url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $title ); ?></a></td>
                                <td class="dpa-num"><?php echo number_format_i18n( $row->views ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p class="dpa-empty">Nog geen weergaven in deze periode.</p>
                <?php endif; ?>
            </div>

            <div class="dpa-card">
                <h2>Verkeersbronnen</h2>
                <?php $this->render_source_types( $types ); ?>
                <?php if ( $refs ) : ?>
                    <table class="widefat striped" style="margin-top:12px;">
                        <thead><tr><th>Bron</th><th>Type</th><th class="dpa-num">Sessies</th></tr></thead>
                        <tbody>
                        <?php foreach ( $refs as $r ) : ?>
                            <tr>
                                <td><?php echo '' !== $r->referrer_host ? esc_html( $r->referrer_host ) : '<em>direct</em>'; ?></td>
                                <td><?php echo esc_html( $this->type_label( $r->referrer_type ) ); ?></td>
                                <td class="dpa-num"><?php echo number_format_i18n( $r->sessions ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <?php if ( null !== $woo ) : ?>
            <?php
            $conv = $totals['sessions'] > 0 ? round( $woo['orders'] / $totals['sessions'] * 100, 1 ) : 0.0;
            ?>
            <h2 class="dpa-section-title">WooCommerce</h2>
            <div class="dpa-kpis">
                <?php
                $this->kpi( 'Omzet', DPA_Woo::price( $woo['revenue'] ), 'betaalde bestellingen', true );
                $this->kpi( 'Bestellingen', (int) $woo['orders'] );
                $this->kpi( 'Gem. orderwaarde', DPA_Woo::price( $woo['aov'] ), '', true );
                $this->kpi( 'Conversieratio', $conv . '%', 'bestellingen ÷ sessies' );
                ?>
            </div>

            <div class="dpa-card">
                <h2>Best verkochte producten</h2>
                <?php if ( ! empty( $woo['top_products'] ) ) : ?>
                    <table class="widefat striped">
                        <thead><tr><th>Product</th><th class="dpa-num">Aantal</th><th class="dpa-num">Omzet</th></tr></thead>
                        <tbody>
                        <?php foreach ( $woo['top_products'] as $pid => $prod ) :
                            $edit = get_edit_post_link( $pid );
                            ?>
                            <tr>
                                <td><?php echo $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $prod['name'] ) . '</a>' : esc_html( $prod['name'] ); ?></td>
                                <td class="dpa-num"><?php echo esc_html( number_format_i18n( $prod['qty'] ) ); ?></td>
                                <td class="dpa-num"><?php echo wp_kses_post( DPA_Woo::price( $prod['revenue'] ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p class="dpa-empty">Geen betaalde bestellingen in deze periode.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <p class="description" style="margin-top:16px;">Cookieless &amp; privacyvriendelijk: er worden geen cookies geplaatst en geen IP-adressen of persoonsgegevens opgeslagen. Bezoekers worden geteld via een per-dag roterende, onomkeerbare hash. Daarom is voor deze statistieken geen cookie-toestemming nodig.<?php echo null !== $woo ? ' De omzetcijfers komen rechtstreeks uit WooCommerce (statussen "verwerkt" en "voltooid"); de conversieratio is het aantal bestellingen gedeeld door het aantal sessies in de periode.' : ''; ?></p>
        <?php
    }

    private function kpi( $label, $value, $sub = '', $raw = false ) {
        ?>
        <div class="dpa-kpi">
            <span class="dpa-kpi-value"><?php echo $raw ? wp_kses_post( $value ) : esc_html( is_int( $value ) ? number_format_i18n( $value ) : $value ); ?></span>
            <span class="dpa-kpi-label"><?php echo esc_html( $label ); ?></span>
            <?php if ( $sub ) : ?><span class="dpa-kpi-sub"><?php echo esc_html( $sub ); ?></span><?php endif; ?>
        </div>
        <?php
    }

    private function type_label( $type ) {
        $labels = [ 'direct' => 'Direct', 'search' => 'Zoekmachine', 'social' => 'Social media', 'referral' => 'Verwijzing', 'internal' => 'Intern' ];
        return $labels[ $type ] ?? $type;
    }

    private function render_source_types( $types ) {
        $order = [ 'direct', 'search', 'social', 'referral' ];
        $total = array_sum( array_map( function ( $t ) use ( $types ) {
            return $types[ $t ] ?? 0;
        }, $order ) );
        if ( $total <= 0 ) {
            return;
        }
        echo '<div class="dpa-bars">';
        foreach ( $order as $type ) {
            $n   = $types[ $type ] ?? 0;
            $pct = round( $n / $total * 100 );
            printf(
                '<div class="dpa-bar-row"><span class="dpa-bar-label">%s</span><span class="dpa-bar-track"><span class="dpa-bar-fill dpa-bar-%s" style="width:%d%%"></span></span><span class="dpa-bar-num">%s</span></div>',
                esc_html( $this->type_label( $type ) ),
                esc_attr( $type ),
                (int) $pct,
                esc_html( number_format_i18n( $n ) )
            );
        }
        echo '</div>';
    }

    /**
     * Eenvoudige, afhankelijkheidsvrije staafgrafiek als inline SVG.
     */
    private function render_chart( $series ) {
        if ( empty( $series ) || array_sum( $series ) === 0 ) {
            echo '<p class="dpa-empty">Nog geen weergaven in deze periode.</p>';
            return;
        }

        $labels = array_keys( $series );
        $values = array_values( $series );
        $max    = max( $values );
        $count  = count( $values );

        $w   = 900;
        $h   = 220;
        $pad = 24;
        $bw  = ( $w - $pad * 2 ) / max( 1, $count );

        echo '<div class="dpa-chart">';
        echo '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="Weergaven over tijd">';

        // Elke ~zoveelste label tonen om overlap te voorkomen.
        $label_every = (int) max( 1, ceil( $count / 12 ) );

        foreach ( $values as $i => $v ) {
            $bar_h = $max > 0 ? ( $v / $max ) * ( $h - $pad * 2 ) : 0;
            $x     = $pad + $i * $bw;
            $y     = $h - $pad - $bar_h;
            printf(
                '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="2" class="dpa-chart-bar"><title>%s: %s</title></rect>',
                $x + $bw * 0.15, $y, $bw * 0.7, $bar_h,
                esc_html( $labels[ $i ] ), esc_html( number_format_i18n( $v ) )
            );
            if ( 0 === $i % $label_every ) {
                printf(
                    '<text x="%.2f" y="%d" text-anchor="middle" class="dpa-chart-label">%s</text>',
                    $x + $bw / 2, $h - 6, esc_html( $labels[ $i ] )
                );
            }
        }
        echo '</svg></div>';
    }

    /* ------------------------------------------------------------------ */
    /*  Instellingen                                                       */
    /* ------------------------------------------------------------------ */

    public function save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Geen toegang.' );
        }
        check_admin_referer( 'dpa_save_settings' );

        $post = wp_unslash( $_POST );
        update_option( 'dpa_settings', [
            'enabled'          => empty( $post['enabled'] ) ? 0 : 1,
            'exclude_editors'  => empty( $post['exclude_editors'] ) ? 0 : 1,
            'retention_days'   => max( 0, min( 3650, (int) ( $post['retention_days'] ?? 730 ) ) ),
            'dashboard_widget' => empty( $post['dashboard_widget'] ) ? 0 : 1,
        ] );

        wp_safe_redirect( add_query_arg(
            [ 'page' => 'dp-analytics', 'tab' => 'settings', 'dpa_saved' => 1 ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    private function render_settings() {
        $s = DPA_Settings::get();
        ?>
        <?php if ( isset( $_GET['dpa_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Instellingen opgeslagen.</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'dpa_save_settings' ); ?>
            <input type="hidden" name="action" value="dpa_save_settings">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Tracking</th>
                    <td><label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> Statistieken verzamelen</label></td>
                </tr>
                <tr>
                    <th scope="row">Eigen bezoeken</th>
                    <td>
                        <label><input type="checkbox" name="exclude_editors" value="1" <?php checked( $s['exclude_editors'] ); ?>> Ingelogde redacteuren/beheerders niet meetellen</label>
                        <p class="description">Aanbevolen: zo blijven de cijfers schoon en tel je je eigen bezoeken niet mee.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="dpa-retention">Bewaartermijn</label></th>
                    <td>
                        <input type="number" id="dpa-retention" name="retention_days" value="<?php echo esc_attr( $s['retention_days'] ); ?>" min="0" max="3650" style="width:90px;"> dagen
                        <p class="description">Oudere gegevens worden dagelijks opgeruimd. 0 = onbeperkt bewaren.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Dashboard-widget</th>
                    <td><label><input type="checkbox" name="dashboard_widget" value="1" <?php checked( $s['dashboard_widget'] ); ?>> Overzicht tonen op het WordPress-dashboard</label></td>
                </tr>
            </table>
            <?php submit_button( 'Instellingen opslaan' ); ?>
        </form>

        <hr>
        <h2>Privacy</h2>
        <p class="description" style="max-width:640px;">DP Analytics plaatst geen cookies en slaat geen IP-adressen of andere persoonsgegevens op. Bezoekers worden geteld via een onomkeerbare, per-dag roterende hash. Hierdoor vallen deze statistieken buiten de cookie-toestemmingsplicht en hoef je ze niet in de cookiebanner op te nemen.</p>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /*  Weergaven-kolom in de berichten-/pagina-lijst                      */
    /* ------------------------------------------------------------------ */

    public function add_views_column( $columns ) {
        $columns['dpa_views'] = 'Weergaven';
        return $columns;
    }

    public function render_views_column( $column, $post_id ) {
        if ( 'dpa_views' !== $column ) {
            return;
        }
        echo esc_html( number_format_i18n( DPA_Stats::views_for_post( $post_id ) ) );
    }

    /* ------------------------------------------------------------------ */
    /*  Dashboard-widget                                                   */
    /* ------------------------------------------------------------------ */

    public function register_dashboard_widget() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        wp_add_dashboard_widget( 'dpa_overview', 'Statistieken (laatste 7 dagen)', [ $this, 'render_dashboard_widget' ] );
    }

    public function render_dashboard_widget() {
        $p      = $this->resolve_period( '7days' );
        $totals = DPA_Stats::totals( $p['from'], $p['to'] );
        $pages  = DPA_Stats::top_pages( $p['from'], $p['to'], 5 );
        $woo    = DPA_Woo::active() ? DPA_Woo::report( strtotime( $p['from'] . ' UTC' ), strtotime( $p['to'] . ' UTC' ) ) : null;
        ?>
        <div class="dpa-widget-kpis" style="display:flex;gap:16px;margin-bottom:12px;flex-wrap:wrap;">
            <div><strong style="font-size:1.4em;"><?php echo esc_html( number_format_i18n( $totals['views'] ) ); ?></strong><br>Weergaven</div>
            <div><strong style="font-size:1.4em;"><?php echo esc_html( number_format_i18n( $totals['visitors'] ) ); ?></strong><br>Bezoekers</div>
            <div><strong style="font-size:1.4em;"><?php echo esc_html( number_format_i18n( $totals['sessions'] ) ); ?></strong><br>Sessies</div>
            <?php if ( null !== $woo ) : ?>
                <div><strong style="font-size:1.4em;"><?php echo wp_kses_post( DPA_Woo::price( $woo['revenue'] ) ); ?></strong><br>Omzet</div>
            <?php endif; ?>
        </div>
        <?php if ( $pages ) : ?>
            <ol style="margin:0 0 8px 18px;">
                <?php foreach ( $pages as $row ) :
                    $title = '' !== $row->title ? $row->title : $row->url; ?>
                    <li><a href="<?php echo esc_url( $row->url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $title ); ?></a> &mdash; <?php echo esc_html( number_format_i18n( $row->views ) ); ?></li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
        <p style="margin:0;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=dp-analytics' ) ); ?>">Volledig dashboard &rarr;</a></p>
        <?php
    }
}
