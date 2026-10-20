<?php
namespace ALSS_Sheet_Sync;
if ( !defined('ABSPATH') ) { exit;}

class settings {

    const SCHEDULES = array( '', '10min', '30min', 'hourly', 'daily' );

    public static function defaults() {
        return array(
            'columns'   => array( 'name', 'sku', 'regular_price', 'sale_price', 'stock', 'status' ),
            'auto_push' => true,
            'schedule'  => '',
        );
    }

    public static function get( $key ) {
        $settings = wp_parse_args( (array) get_option('alss_settings', array()), self::defaults() );
        return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
    }

    public static function update( $values ) {
        $settings = wp_parse_args( (array) get_option('alss_settings', array()), self::defaults() );
        update_option( 'alss_settings', array_merge( $settings, $values ), false );
    }

    public static function credentials() {
        $credentials = get_option('alss_credentials', array());
        return is_array( $credentials ) && ! empty( $credentials['client_email'] ) && ! empty( $credentials['private_key'] ) ? $credentials : array();
    }

    public static function client_email() {
        $credentials = self::credentials();
        return $credentials ? $credentials['client_email'] : '';
    }

    public static function sheet() {
        $sheet = get_option('alss_sheet', array());
        return is_array( $sheet ) && ! empty( $sheet['id'] ) && ! empty( $sheet['tab'] ) ? $sheet : array();
    }

    public static function sheet_url() {
        $sheet = self::sheet();
        if ( ! $sheet ) {
            return '';
        }
        return 'https://docs.google.com/spreadsheets/d/' . rawurlencode( $sheet['id'] ) . '/edit#gid=' . absint( isset( $sheet['gid'] ) ? $sheet['gid'] : 0 );
    }

    public static function is_connected() {
        return self::credentials() && self::sheet();
    }

    // shared secret the Apps Script signs its requests with
    public static function secret() {
        $secret = get_option('alss_secret');
        if ( ! $secret ) {
            $secret = wp_generate_password( 48, false );
            update_option( 'alss_secret', $secret, false );
        }
        return $secret;
    }

    public static function regenerate_secret() {
        delete_option('alss_secret');
        delete_option('alss_script_seen');
        return self::secret();
    }

    public static function schedule_interval( $schedule ) {
        $intervals = array(
            '10min'  => 10 * MINUTE_IN_SECONDS,
            '30min'  => 30 * MINUTE_IN_SECONDS,
            'hourly' => HOUR_IN_SECONDS,
            'daily'  => DAY_IN_SECONDS,
        );
        return isset( $intervals[ $schedule ] ) ? $intervals[ $schedule ] : 0;
    }
}
