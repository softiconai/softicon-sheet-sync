<?php
namespace ALSS_Sheet_Sync;
if ( !defined('ABSPATH') ) { exit;}

// endpoints the Apps Script in the sheet calls, each request is signed with the shared secret
class rest {

    const NS       = 'alss/v1';
    const MAX_ROWS = 500;

    public function __construct()
    {
        add_action('rest_api_init', array( $this, 'routes' ));
    }

    public function routes() {
        register_rest_route( self::NS, '/update', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'update' ),
            'permission_callback' => array( $this, 'verify' ),
        ) );
        register_rest_route( self::NS, '/export', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'export' ),
            'permission_callback' => array( $this, 'verify' ),
        ) );
        register_rest_route( self::NS, '/ping', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'ping' ),
            'permission_callback' => array( $this, 'verify' ),
        ) );
        register_rest_route( self::NS, '/status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'status' ),
            'permission_callback' => function () { return current_user_can('manage_woocommerce'); },
        ) );
    }

    // HMAC of "timestamp.nonce.route.body", each nonce works once and only within five minutes
    public function verify( $request ) {
        $timestamp = (int) $request->get_header('x_alss_timestamp');
        $nonce     = (string) $request->get_header('x_alss_nonce');
        $signature = strtolower( (string) $request->get_header('x_alss_signature') );

        if ( ! $timestamp || ! $signature || ! preg_match( '/^[a-f0-9-]{16,64}$/i', $nonce ) || abs( time() - $timestamp ) > 5 * MINUTE_IN_SECONDS ) {
            return new \WP_Error( 'alss_expired', __( 'The request is missing its signature or has expired.', 'softicon-sheet-sync' ), array( 'status' => 401 ) );
        }

        $signed   = implode( '.', array( $timestamp, $nonce, $request->get_route(), $request->get_body() ) );
        $expected = hash_hmac( 'sha256', $signed, settings::secret() );
        if ( ! hash_equals( $expected, $signature ) ) {
            return new \WP_Error( 'alss_signature', __( 'Invalid signature. Copy the Apps Script code again from WooCommerce > Sheet Sync.', 'softicon-sheet-sync' ), array( 'status' => 401 ) );
        }

        // checked after the signature so unsigned requests can't fill the transient table
        $used = 'alss_nonce_' . md5( $nonce );
        if ( get_transient( $used ) ) {
            return new \WP_Error( 'alss_replay', __( 'This request was already received.', 'softicon-sheet-sync' ), array( 'status' => 409 ) );
        }
        set_transient( $used, 1, 6 * MINUTE_IN_SECONDS );

        if ( ! settings::is_connected() ) {
            return new \WP_Error( 'alss_not_connected', __( 'Google Sheets is not connected in WooCommerce.', 'softicon-sheet-sync' ), array( 'status' => 409 ) );
        }
        return true;
    }

    public function update( $request ) {
        $data    = $request->get_json_params();
        $headers = isset( $data['header'] ) && is_array( $data['header'] ) ? array_map( 'strval', $data['header'] ) : array();
        $rows    = isset( $data['rows'] ) && is_array( $data['rows'] ) ? array_slice( $data['rows'], 0, self::MAX_ROWS ) : array();
        $keys    = array_map( array( 'ALSS_Sheet_Sync\columns', 'key_for_header' ), $headers );

        $id_index = array_search( 'id', $keys, true );
        if ( false === $id_index ) {
            return new \WP_Error( 'alss_no_id', __( 'The ID column is missing. Use Sheet Sync > Fetch from WooCommerce to rebuild the sheet.', 'softicon-sheet-sync' ), array( 'status' => 400 ) );
        }

        $apply = $this->columns_to_apply( $keys, isset( $data['cols'] ) ? $data['cols'] : null );
        $time  = date_i18n( get_option('time_format') );
        $out   = array();
        $saved = 0;

        foreach ( $rows as $item ) {
            $cells = isset( $item['values'] ) && is_array( $item['values'] ) ? $item['values'] : array();
            $line  = isset( $item['row'] ) ? absint( $item['row'] ) : 0;
            $id    = isset( $cells[ $id_index ] ) ? absint( $cells[ $id_index ] ) : 0;

            if ( ! $id ) {
                if ( implode( '', array_map( 'strval', $cells ) ) !== '' ) {
                    $out[] = array( 'row' => $line, 'status' => 'error', 'message' => '✗ ' . __( 'No product ID. New products can only be added in WooCommerce.', 'softicon-sheet-sync' ) );
                }
                continue;
            }

            $product = wc_get_product( $id );
            if ( ! product_row::exportable( $product ) ) {
                /* translators: %d: product id */
                $out[] = array( 'row' => $line, 'status' => 'error', 'message' => '✗ ' . sprintf( __( 'Product #%d was not found.', 'softicon-sheet-sync' ), $id ) );
                continue;
            }

            sync::$muted[] = $id;
            $result = product_row::apply( $product, sync::keyed( $keys, $cells ), $apply );
            product_row::remember( $product );
            $saved += $result['changed'] ? 1 : 0;

            $values = array();
            foreach ( $keys as $index => $key ) {
                if ( $key && 'sync_status' !== $key ) {
                    $values[ $headers[ $index ] ] = product_row::value( $product, $key );
                }
            }

            $out[] = array(
                'row'     => $line,
                'status'  => $result['errors'] ? 'error' : 'ok',
                /* translators: %s: time */
                'message' => $result['errors'] ? '✗ ' . implode( ' ', $result['errors'] ) : '✓ ' . sprintf( __( 'Synced %s', 'softicon-sheet-sync' ), $time ),
                'values'  => $values,
            );

            if ( $result['errors'] ) {
                /* translators: 1: product id, 2: error */
                log::error( sprintf( __( 'Product #%1$d: %2$s', 'softicon-sheet-sync' ), $id, implode( ' ', $result['errors'] ) ), 'sheet' );
            }
        }

        if ( $saved ) {
            /* translators: %d: number of products */
            log::add( sprintf( _n( 'Updated %d product from Google Sheets.', 'Updated %d products from Google Sheets.', $saved, 'softicon-sheet-sync' ), $saved ), 'sheet' );
        }
        update_option( 'alss_script_seen', time(), false );

        return rest_ensure_response( array( 'rows' => $out ) );
    }

    // an edit only applies the columns that were touched, so a stale cell elsewhere in the row can't overwrite newer data
    private function columns_to_apply( $keys, $cols ) {
        $editable = columns::editable();
        if ( ! is_array( $cols ) || 2 !== count( $cols ) ) {
            return $editable;
        }

        $first = max( 1, (int) $cols[0] );
        $last  = (int) $cols[1];
        $apply = array();
        foreach ( $keys as $index => $key ) {
            if ( $index + 1 >= $first && $index + 1 <= $last && in_array( $key, $editable, true ) ) {
                $apply[] = $key;
            }
        }
        return $apply;
    }

    public function export() {
        $result = sync::start_export('sheet');
        if ( is_wp_error( $result ) ) {
            return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 409 ) );
        }
        return rest_ensure_response( array( 'message' => __( 'Fetching products from WooCommerce. The sheet updates in a minute.', 'softicon-sheet-sync' ) ) );
    }

    public function ping( $request ) {
        $data = $request->get_json_params();
        update_option( 'alss_script_seen', time(), false );
        update_option( 'alss_script_version', isset( $data['version'] ) ? sanitize_text_field( $data['version'] ) : '', false );
        log::add( __( 'Instant sync turned on from the Apps Script.', 'softicon-sheet-sync' ), 'sheet' );

        return rest_ensure_response( array( 'ok' => true ) );
    }

    public function status() {
        $state = sync::export_state();
        $seen  = (int) get_option('alss_script_seen');

        return rest_ensure_response( array(
            'export' => array(
                'status'  => isset( $state['status'] ) ? $state['status'] : '',
                'done'    => isset( $state['done'] ) ? (int) $state['done'] : 0,
                'total'   => isset( $state['total'] ) ? (int) $state['total'] : 0,
                'message' => isset( $state['message'] ) ? $state['message'] : '',
            ),
            'script' => array(
                'seen' => $seen,
                /* translators: %s: human readable time difference */
                'text' => $seen ? sprintf( __( 'Connected, last request %s ago', 'softicon-sheet-sync' ), human_time_diff( $seen ) ) : '',
            ),
        ) );
    }
}
