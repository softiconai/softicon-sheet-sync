<?php
namespace ALSS_Sheet_Sync;
if ( !defined('ABSPATH') ) { exit;}

class log {

    const MAX = 100;

    // source is sheet, store, schedule or admin
    public static function add( $message, $source = 'store', $level = 'info' ) {
        $entries = self::all();
        array_unshift( $entries, array(
            'time'    => time(),
            'source'  => $source,
            'level'   => $level,
            'message' => wp_strip_all_tags( $message ),
        ) );
        update_option( 'alss_log', array_slice( $entries, 0, self::MAX ), false );
    }

    public static function error( $message, $source = 'store' ) {
        self::add( $message, $source, 'error' );
    }

    public static function all() {
        $entries = get_option('alss_log', array());
        return is_array( $entries ) ? $entries : array();
    }

    public static function clear() {
        delete_option('alss_log');
    }
}
