<?php
/**
 * Instellingen: defaults, ophalen, opslaan.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DPA_Settings {

    public static function defaults() {
        return [
            'enabled'          => 1,
            'exclude_editors'  => 1,   // ingelogde gebruikers die kunnen bewerken niet tracken
            'retention_days'   => 730, // ~2 jaar; 0 = onbeperkt
            'dashboard_widget' => 1,   // overzicht-widget op het WP-dashboard
            'email_reports'    => 0,   // periodiek e-mailrapport aan/uit
            'email_frequency'  => 'monthly', // monthly | weekly
            'email_recipients' => '',  // komma-gescheiden; leeg = beheerder-e-mail
        ];
    }

    /**
     * Ontvangers van het e-mailrapport als schone array. Valt terug op het
     * WordPress-beheerdersadres als er niets geldigs is ingesteld.
     */
    public static function report_recipients() {
        $raw = (string) self::val( 'email_recipients' );
        $out = [];
        foreach ( preg_split( '/[,;\s]+/', $raw ) as $email ) {
            $email = sanitize_email( trim( $email ) );
            if ( $email && is_email( $email ) ) {
                $out[] = $email;
            }
        }
        if ( empty( $out ) ) {
            $out[] = get_option( 'admin_email' );
        }
        return array_values( array_unique( $out ) );
    }

    public static function get() {
        $saved = get_option( 'dpa_settings', [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
        return array_merge( self::defaults(), $saved );
    }

    public static function val( $key ) {
        $s = self::get();
        return isset( $s[ $key ] ) ? $s[ $key ] : null;
    }
}
