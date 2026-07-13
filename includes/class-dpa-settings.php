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
        ];
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
