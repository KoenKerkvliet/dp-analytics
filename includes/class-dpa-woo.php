<?php
/**
 * WooCommerce-integratie.
 *
 * Leest orders rechtstreeks uit WooCommerce (via wc_get_orders, dus werkt met
 * zowel de klassieke opslag als HPOS). Er wordt niets extra's getrackt — de
 * omzetcijfers komen uit WooCommerce zelf, de conversieratio combineert die met
 * de sessies die DP Analytics al telt.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DPA_Woo {

    public static function active() {
        return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' );
    }

    /**
     * Order-statussen die als omzet meetellen. Standaard "betaald" (verwerkt +
     * voltooid). Aanpasbaar via de filter voor afwijkende workflows.
     */
    public static function paid_statuses() {
        return apply_filters( 'dpa_woo_paid_statuses', [ 'wc-processing', 'wc-completed' ] );
    }

    /**
     * Volledig rapport voor het venster in één order-uitvraag (gecached).
     *
     * @param int $from_ts GMT-timestamp begin
     * @param int $to_ts   GMT-timestamp eind
     * @return array{revenue:float,orders:int,aov:float,top_products:array}
     */
    public static function report( $from_ts, $to_ts ) {
        if ( ! self::active() ) {
            return [ 'revenue' => 0.0, 'orders' => 0, 'aov' => 0.0, 'top_products' => [] ];
        }

        $key    = 'dpa_woo_' . md5( $from_ts . '_' . $to_ts . '_' . implode( ',', self::paid_statuses() ) );
        $cached = get_transient( $key );
        if ( false !== $cached ) {
            return $cached;
        }

        $orders = wc_get_orders( [
            'limit'        => -1,
            'status'       => self::paid_statuses(),
            'date_created' => $from_ts . '...' . $to_ts,
            'return'       => 'objects',
            'type'         => 'shop_order',
        ] );

        $revenue  = 0.0;
        $count    = 0;
        $products = []; // product_id => [ name, qty, revenue ]

        foreach ( $orders as $order ) {
            if ( ! $order instanceof WC_Order ) {
                continue;
            }
            $revenue += (float) $order->get_total();
            $count++;

            foreach ( $order->get_items() as $item ) {
                if ( ! $item instanceof WC_Order_Item_Product ) {
                    continue;
                }
                $pid = $item->get_product_id();
                if ( ! isset( $products[ $pid ] ) ) {
                    $products[ $pid ] = [ 'name' => $item->get_name(), 'qty' => 0, 'revenue' => 0.0 ];
                }
                $products[ $pid ]['qty']     += (int) $item->get_quantity();
                $products[ $pid ]['revenue'] += (float) $item->get_total();
            }
        }

        // Top producten op omzet.
        uasort( $products, function ( $a, $b ) {
            return $b['revenue'] <=> $a['revenue'];
        } );
        $top = array_slice( $products, 0, 10, true );

        $result = [
            'revenue'      => $revenue,
            'orders'       => $count,
            'aov'          => $count > 0 ? $revenue / $count : 0.0,
            'top_products' => $top,
        ];

        set_transient( $key, $result, 10 * MINUTE_IN_SECONDS );
        return $result;
    }

    /**
     * Bedrag netjes opgemaakt met het WooCommerce-valutasymbool (HTML).
     */
    public static function price( $amount ) {
        return function_exists( 'wc_price' ) ? wc_price( $amount ) : number_format_i18n( (float) $amount, 2 );
    }
}
