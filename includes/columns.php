<?php
namespace ALSS_Sheet_Sync;
if ( !defined('ABSPATH') ) { exit;}

// sheet headers stay in English on every site, the sync finds columns by these names
class columns {

    const STOCK = array(
        'instock'     => 'In stock',
        'outofstock'  => 'Out of stock',
        'onbackorder' => 'On backorder',
    );

    const STATUS = array(
        'publish' => 'Published',
        'draft'   => 'Draft',
        'pending' => 'Pending',
        'private' => 'Private',
        'future'  => 'Scheduled',
    );

    public static function all() {
        return array(
            'id'                => array( 'header' => 'ID', 'locked' => true, 'always' => true ),
            'type'              => array( 'header' => 'Type', 'locked' => true, 'always' => true ),
            'name'              => array( 'header' => 'Name', 'label' => __( 'Name', 'softicon-sheet-sync' ) ),
            'sku'               => array( 'header' => 'SKU', 'label' => __( 'SKU', 'softicon-sheet-sync' ) ),
            'regular_price'     => array( 'header' => 'Regular price', 'label' => __( 'Regular price', 'softicon-sheet-sync' ), 'price' => true ),
            'sale_price'        => array( 'header' => 'Sale price', 'label' => __( 'Sale price', 'softicon-sheet-sync' ), 'price' => true ),
            'stock'             => array( 'header' => 'Stock', 'label' => __( 'Stock (quantity or In stock / Out of stock / On backorder)', 'softicon-sheet-sync' ) ),
            'status'            => array( 'header' => 'Status', 'label' => __( 'Status (Published, Draft, Pending, Private)', 'softicon-sheet-sync' ) ),
            'categories'        => array( 'header' => 'Categories', 'label' => __( 'Categories (comma separated names)', 'softicon-sheet-sync' ) ),
            'short_description' => array( 'header' => 'Short description', 'label' => __( 'Short description', 'softicon-sheet-sync' ) ),
            'description'       => array( 'header' => 'Description', 'label' => __( 'Description', 'softicon-sheet-sync' ) ),
            'total_sales'       => array( 'header' => 'Total sales', 'label' => __( 'Total sales (read only)', 'softicon-sheet-sync' ), 'locked' => true ),
            'url'               => array( 'header' => 'Product URL', 'label' => __( 'Product URL (read only)', 'softicon-sheet-sync' ), 'locked' => true ),
            'sync_status'       => array( 'header' => 'Sync status', 'locked' => true, 'always' => true ),
        );
    }

    // the optional columns a store owner can switch on or off
    public static function optional() {
        return array_filter( self::all(), function ( $column ) { return empty( $column['always'] ); } );
    }

    public static function enabled() {
        $chosen = (array) settings::get('columns');
        $keys   = array();
        foreach ( self::all() as $key => $column ) {
            if ( ! empty( $column['always'] ) || in_array( $key, $chosen, true ) ) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    public static function editable() {
        $all = self::all();
        return array_values( array_filter( self::enabled(), function ( $key ) use ( $all ) { return empty( $all[ $key ]['locked'] ); } ) );
    }

    public static function headers( $keys = null ) {
        $all = self::all();
        return array_map( function ( $key ) use ( $all ) { return $all[ $key ]['header']; }, null === $keys ? self::enabled() : $keys );
    }

    // header text in the sheet => column key, case and spacing don't matter
    public static function key_for_header( $header ) {
        $header = strtolower( trim( (string) $header ) );
        foreach ( self::all() as $key => $column ) {
            if ( strtolower( $column['header'] ) === $header ) {
                return $key;
            }
        }
        return '';
    }

    public static function is_price( $key ) {
        $all = self::all();
        return ! empty( $all[ $key ]['price'] );
    }

    public static function stock_slug( $value ) {
        $value = strtolower( trim( (string) $value ) );
        foreach ( self::STOCK as $slug => $label ) {
            if ( $value === $slug || strtolower( $label ) === $value ) {
                return $slug;
            }
        }
        return '';
    }

    public static function status_slug( $value ) {
        $value = strtolower( trim( (string) $value ) );
        foreach ( self::STATUS as $slug => $label ) {
            if ( $value === $slug || strtolower( $label ) === $value ) {
                return $slug;
            }
        }
        return '';
    }

    // one comparable string per cell, so "10.50", 10.5 and "10.5" count as the same price
    public static function normalize( $key, $value ) {
        if ( is_bool( $value ) ) {
            $value = $value ? '1' : '';
        }
        if ( is_float( $value ) ) {
            $value = rtrim( rtrim( sprintf( '%.6F', $value ), '0' ), '.' );
        }
        $value = str_replace( "\r\n", "\n", trim( (string) $value ) );

        if ( self::is_price( $key ) ) {
            return '' === $value || ! is_numeric( $value ) ? $value : wc_format_decimal( $value, false, true );
        }

        switch ( $key ) {
            case 'stock':
                if ( is_numeric( $value ) ) {
                    return (string) (int) round( (float) $value );
                }
                $slug = self::stock_slug( $value );
                return $slug ? $slug : $value;
            case 'status':
                $slug = self::status_slug( $value );
                return $slug ? $slug : $value;
            case 'categories':
                $names = array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' );
                natcasesort( $names );
                return implode( ', ', $names );
        }

        return $value;
    }
}
