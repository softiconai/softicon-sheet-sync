<?php
namespace ALSS_Sheet_Sync;
if ( !defined('ABSPATH') ) { exit;}

// background jobs on Action Scheduler (ships with WooCommerce): full export, queued pushes, pulls from the sheet
class sync {

    const GROUP       = 'alss';
    const BATCH       = 50;
    const PULL_BATCH  = 100;
    const PUSH_DELAY  = 10;
    const PROTECTED   = 'SoftIcon Sheet Sync: read only column';

    // product ids being saved from the sheet right now, their rows are written back by the Apps Script
    public static $muted = array();

    public function __construct()
    {
        add_action('alss_export_batch', array( $this, 'export_batch' ));
        add_action('alss_push', array( $this, 'push' ));
        add_action('alss_pull', array( $this, 'pull' ), 10, 1);
        add_action('alss_scheduled', array( $this, 'scheduled' ));
    }

    public static function exporting() {
        $state = get_option('alss_export');
        return is_array( $state ) && 'running' === $state['status'];
    }

    public static function export_state() {
        $state = get_option('alss_export');
        return is_array( $state ) ? $state : array();
    }

    public static function start_export( $source = 'admin' ) {
        if ( ! settings::is_connected() ) {
            return new \WP_Error( 'alss_not_connected', __( 'Connect Google Sheets first.', 'softicon-sheet-sync' ) );
        }

        // a stuck run (fatal error, killed worker) is restarted after 15 minutes
        $state = self::export_state();
        if ( self::exporting() && time() - $state['updated'] < 15 * MINUTE_IN_SECONDS ) {
            return new \WP_Error( 'alss_busy', __( 'A sync to Google Sheets is already running.', 'softicon-sheet-sync' ) );
        }

        $ids = wc_get_products( array(
            'limit'   => -1,
            'return'  => 'ids',
            'status'  => array_keys( columns::STATUS ),
            'orderby' => 'ID',
            'order'   => 'ASC',
        ) );

        update_option( 'alss_export', array(
            'status'  => 'running',
            'source'  => $source,
            'ids'     => array_map( 'absint', $ids ),
            'done'    => 0,
            'total'   => count( $ids ),
            'row'     => 2,
            'grid'    => 0,
            'started' => time(),
            'updated' => time(),
            'message' => '',
        ), false );

        as_unschedule_all_actions( 'alss_export_batch', array(), self::GROUP );
        as_enqueue_async_action( 'alss_export_batch', array(), self::GROUP );
        return true;
    }

    public function export_batch() {
        $state = self::export_state();
        if ( ! $state || 'running' !== $state['status'] ) {
            return;
        }

        try {
            if ( 0 === $state['done'] ) {
                $state['grid'] = self::prepare_sheet();
            }

            $rows     = array();
            $products = array();
            foreach ( array_slice( $state['ids'], $state['done'], self::BATCH ) as $id ) {
                $product = wc_get_product( $id );
                if ( ! product_row::exportable( $product ) ) {
                    continue;
                }
                foreach ( product_row::family( $product ) as $item ) {
                    $rows[]     = product_row::build( $item );
                    $products[] = $item;
                }
            }

            if ( $rows ) {
                $last = $state['row'] + count( $rows ) - 1;
                if ( $last > $state['grid'] ) {
                    $state['grid'] = self::grow_grid( $last + 500 );
                }
                self::check( google::update_values( array( array(
                    'range'  => google::range( 'A' . $state['row'] . ':' . google::column( count( $rows[0] ) ) . $last ),
                    'values' => $rows,
                ) ) ) );
                foreach ( $products as $item ) {
                    product_row::remember( $item );
                }
                $state['row'] = $last + 1;
            }

            $state['done']    = min( $state['total'], $state['done'] + self::BATCH );
            $state['updated'] = time();

            if ( $state['done'] < $state['total'] ) {
                update_option( 'alss_export', $state, false );
                as_enqueue_async_action( 'alss_export_batch', array(), self::GROUP );
                return;
            }

            self::finish_sheet( $state['row'], $state['grid'] );
            $state['status'] = 'done';
            update_option( 'alss_export', $state, false );
            /* translators: %d: number of rows */
            log::add( sprintf( __( 'Sent %d rows to Google Sheets.', 'softicon-sheet-sync' ), $state['row'] - 2 ), $state['source'] );
        } catch ( \Exception $e ) {
            $state['status']  = 'error';
            $state['message'] = self::message( $e );
            update_option( 'alss_export', $state, false );
            log::error( self::message( $e ), $state['source'] );
        }
    }

    // header row first, then rows are overwritten in place so the sheet never sits empty
    private static function prepare_sheet() {
        $headers = columns::headers();
        self::check( google::update_values( array( array(
            'range'  => google::range( 'A1:' . google::column( count( $headers ) ) . '1' ),
            'values' => array( $headers ),
        ) ) ) );

        // columns switched off since the last export
        $old_width = (int) get_option('alss_width');
        if ( $old_width > count( $headers ) ) {
            self::check( google::clear_values( google::range( google::column( count( $headers ) + 1 ) . '1:' . google::column( $old_width ) ) ) );
        }
        update_option( 'alss_width', count( $headers ), false );

        return self::grid_rows();
    }

    private static function finish_sheet( $next_row, $grid ) {
        $width = count( columns::enabled() );
        if ( $next_row <= $grid ) {
            self::check( google::clear_values( google::range( 'A' . $next_row . ':' . google::column( $width ) . $grid ) ) );
        }
        self::format( $next_row - 1 );
    }

    private static function grid_rows() {
        $sheet = settings::sheet();
        $data  = self::check( google::spreadsheet( $sheet['id'] ) );
        foreach ( (array) $data['sheets'] as $item ) {
            if ( self::sheet_id( $item ) === (int) $sheet['gid'] ) {
                return (int) $item['properties']['gridProperties']['rowCount'];
            }
        }
        throw new \Exception( esc_html__( 'The sheet tab was not found. Check the tab name in the settings.', 'softicon-sheet-sync' ) );
    }

    private static function grow_grid( $rows ) {
        $sheet = settings::sheet();
        self::check( google::batch_update( array( array(
            'updateSheetProperties' => array(
                'properties' => array( 'sheetId' => (int) $sheet['gid'], 'gridProperties' => array( 'rowCount' => $rows ) ),
                'fields'     => 'gridProperties.rowCount',
            ),
        ) ) ) );
        return $rows;
    }

    // frozen coloured header, grey read-only columns with an edit warning, number formats and a status dropdown
    public static function format( $last_row ) {
        $sheet    = settings::sheet();
        $gid      = (int) $sheet['gid'];
        $keys     = columns::enabled();
        $all      = columns::all();
        $width    = count( $keys );
        $requests = array();

        // read-only warnings from the last export are replaced, ours are found by their description
        $data = self::check( google::request( 'GET', rawurlencode( $sheet['id'] ), null, array( 'fields' => 'sheets(properties.sheetId,protectedRanges(protectedRangeId,description))' ) ) );
        foreach ( (array) ( isset( $data['sheets'] ) ? $data['sheets'] : array() ) as $item ) {
            if ( self::sheet_id( $item ) !== $gid || empty( $item['protectedRanges'] ) ) {
                continue;
            }
            foreach ( $item['protectedRanges'] as $range ) {
                if ( isset( $range['description'] ) && self::PROTECTED === $range['description'] ) {
                    $requests[] = array( 'deleteProtectedRange' => array( 'protectedRangeId' => (int) $range['protectedRangeId'] ) );
                }
            }
        }

        $requests[] = array(
            'updateSheetProperties' => array(
                'properties' => array( 'sheetId' => $gid, 'gridProperties' => array( 'frozenRowCount' => 1 ) ),
                'fields'     => 'gridProperties.frozenRowCount',
            ),
        );
        $requests[] = array(
            'repeatCell' => array(
                'range'  => array( 'sheetId' => $gid, 'startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => 0, 'endColumnIndex' => $width ),
                'cell'   => array( 'userEnteredFormat' => array(
                    'backgroundColor' => self::color( '#2271b1' ),
                    'textFormat'      => array( 'bold' => true, 'foregroundColor' => self::color( '#ffffff' ) ),
                ) ),
                'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
            ),
        );

        $decimals = wc_get_price_decimals();
        $pattern  = $decimals > 0 ? '0.' . str_repeat( '0', $decimals ) : '0';

        foreach ( $keys as $index => $key ) {
            $column = array( 'sheetId' => $gid, 'startRowIndex' => 1, 'startColumnIndex' => $index, 'endColumnIndex' => $index + 1 );

            if ( ! empty( $all[ $key ]['locked'] ) && 'sync_status' !== $key ) {
                $requests[] = array(
                    'repeatCell' => array(
                        'range'  => $column,
                        'cell'   => array( 'userEnteredFormat' => array( 'backgroundColor' => self::color( '#f0f0f1' ), 'textFormat' => array( 'foregroundColor' => self::color( '#50575e' ) ) ) ),
                        'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
                    ),
                );
                $requests[] = array(
                    'addProtectedRange' => array( 'protectedRange' => array(
                        'range'       => array_merge( $column, array( 'startRowIndex' => 0 ) ),
                        'description' => self::PROTECTED,
                        'warningOnly' => true,
                    ) ),
                );
            }
            if ( columns::is_price( $key ) ) {
                $requests[] = array(
                    'repeatCell' => array(
                        'range'  => $column,
                        'cell'   => array( 'userEnteredFormat' => array( 'numberFormat' => array( 'type' => 'NUMBER', 'pattern' => $pattern ) ) ),
                        'fields' => 'userEnteredFormat.numberFormat',
                    ),
                );
            }
            if ( 'status' === $key ) {
                $requests[] = array(
                    'setDataValidation' => array(
                        'range' => array_merge( $column, array( 'endRowIndex' => max( 2, $last_row ) ) ),
                        'rule'  => array(
                            'condition'    => array( 'type' => 'ONE_OF_LIST', 'values' => array_map( function ( $value ) { return array( 'userEnteredValue' => $value ); }, array( 'Published', 'Draft', 'Pending', 'Private' ) ) ),
                            'strict'       => false,
                            'showCustomUi' => true,
                        ),
                    ),
                );
            }
            // a list rule would flag every quantity as invalid, so stock gets a formula: a number or one of the stock words
            if ( 'stock' === $key ) {
                $stock = array_merge( $column, array( 'endRowIndex' => max( 2, $last_row ) ) );
                $cell  = google::column( $index + 1 ) . '2';
            }
        }

        // fixed widths, Google's auto resize ignores the bold header and cuts it off
        $widths = array( 'id' => 60, 'type' => 80, 'name' => 240, 'sku' => 180, 'regular_price' => 110, 'sale_price' => 100, 'stock' => 110, 'status' => 110, 'categories' => 200, 'short_description' => 300, 'description' => 300, 'total_sales' => 100, 'url' => 240, 'sync_status' => 300 );
        foreach ( $keys as $index => $key ) {
            $requests[] = array(
                'updateDimensionProperties' => array(
                    'range'      => array( 'sheetId' => $gid, 'dimension' => 'COLUMNS', 'startIndex' => $index, 'endIndex' => $index + 1 ),
                    'properties' => array( 'pixelSize' => isset( $widths[ $key ] ) ? $widths[ $key ] : 150 ),
                    'fields'     => 'pixelSize',
                ),
            );
        }

        self::check( google::batch_update( $requests ) );

        if ( isset( $stock ) ) {
            self::stock_validation( $stock, $cell );
        }
    }

    // sheets in some locales separate formula arguments with ; instead of , so both are tried, and a refusal only skips the hint
    private static function stock_validation( $range, $cell ) {
        $tests = array( 'ISNUMBER(' . $cell . ')', $cell . '=""' );
        foreach ( columns::STOCK as $label ) {
            $tests[] = 'LOWER(' . $cell . ')="' . strtolower( $label ) . '"';
        }
        foreach ( array( ',', ';' ) as $separator ) {
            $result = google::batch_update( array( array(
                'setDataValidation' => array(
                    'range' => $range,
                    'rule'  => array(
                        'condition'    => array( 'type' => 'CUSTOM_FORMULA', 'values' => array( array( 'userEnteredValue' => '=OR(' . implode( $separator, $tests ) . ')' ) ) ),
                        'inputMessage' => 'A quantity, or In stock / Out of stock / On backorder',
                        'strict'       => false,
                    ),
                ),
            ) ) );
            if ( ! is_wp_error( $result ) ) {
                return;
            }
        }
    }

    // product ids changed in WooCommerce wait here and are written together a few seconds later
    public static function queue( $ids ) {
        if ( ! settings::is_connected() || ! settings::get('auto_push') ) {
            return;
        }
        $ids   = array_diff( array_map( 'absint', (array) $ids ), self::$muted );
        $queue = get_option( 'alss_queue', array() );
        $queue = array_values( array_unique( array_merge( is_array( $queue ) ? $queue : array(), array_filter( $ids ) ) ) );
        if ( ! $queue ) {
            return;
        }
        update_option( 'alss_queue', $queue, false );

        if ( function_exists('as_has_scheduled_action') && ! as_has_scheduled_action( 'alss_push', array(), self::GROUP ) ) {
            as_schedule_single_action( time() + self::PUSH_DELAY, 'alss_push', array(), self::GROUP );
        }
    }

    public function push() {
        $queue = get_option( 'alss_queue', array() );
        if ( ! $queue || ! settings::is_connected() ) {
            return;
        }
        // the export writes every product anyway, rows it already passed are pushed afterwards
        if ( self::exporting() ) {
            as_schedule_single_action( time() + 30, 'alss_push', array(), self::GROUP );
            return;
        }
        update_option( 'alss_queue', array(), false );

        try {
            $map     = self::row_map();
            $width   = count( columns::enabled() );
            $updates = array();
            $append  = array();
            $delete  = array();
            $written = array();

            foreach ( $queue as $id ) {
                $product = wc_get_product( $id );
                if ( product_row::exportable( $product ) ) {
                    $row = product_row::build( $product, self::take_note( $id ) );
                    if ( isset( $map[ $id ] ) ) {
                        $updates[] = array(
                            'range'  => google::range( 'A' . $map[ $id ] . ':' . google::column( $width ) . $map[ $id ] ),
                            'values' => array( $row ),
                        );
                    } else {
                        $append[ self::sort_key( $product ) ] = $row;
                    }
                    $written[] = $product;
                } elseif ( isset( $map[ $id ] ) ) {
                    $delete[] = $map[ $id ];
                }
            }

            self::check( google::update_values( $updates ) );
            if ( $append ) {
                ksort( $append );
                self::check( google::append_values( google::range( 'A1:' . google::column( $width ) . '1' ), array_values( $append ) ) );
            }
            if ( $delete ) {
                self::delete_rows( $delete );
            }
            foreach ( $written as $product ) {
                product_row::remember( $product );
            }
        } catch ( \Exception $e ) {
            // the ids go back in the queue and are tried again in five minutes
            $waiting = get_option( 'alss_queue', array() );
            update_option( 'alss_queue', array_values( array_unique( array_merge( $queue, is_array( $waiting ) ? $waiting : array() ) ) ), false );
            as_unschedule_all_actions( 'alss_push', array(), self::GROUP );
            as_schedule_single_action( time() + 5 * MINUTE_IN_SECONDS, 'alss_push', array(), self::GROUP );
            log::error( self::message( $e ), 'store' );
        }
    }

    // product id => sheet row number, read from the ID column
    private static function row_map() {
        $values = self::check( google::get_values( google::range( 'A2:A' ) ) );
        $map    = array();
        foreach ( $values as $index => $cells ) {
            $id = isset( $cells[0] ) ? absint( $cells[0] ) : 0;
            if ( $id && ! isset( $map[ $id ] ) ) {
                $map[ $id ] = $index + 2;
            }
        }
        return $map;
    }

    private static function delete_rows( $rows ) {
        $sheet = settings::sheet();
        rsort( $rows );
        $requests = array();
        foreach ( array_unique( $rows ) as $row ) {
            $requests[] = array( 'deleteDimension' => array( 'range' => array(
                'sheetId'    => (int) $sheet['gid'],
                'dimension'  => 'ROWS',
                'startIndex' => $row - 1,
                'endIndex'   => $row,
            ) ) );
        }
        self::check( google::batch_update( $requests ) );
    }

    // new variations land right after their parent when both are appended together
    private static function sort_key( $product ) {
        $parent = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        return sprintf( '%012d-%d-%012d', $parent, $product->is_type('variation') ? 1 : 0, $product->get_id() );
    }

    // a message for the Sync status cell, used when a scheduled pull rejected a value
    public static function note( $id, $message ) {
        set_transient( 'alss_note_' . absint( $id ), $message, HOUR_IN_SECONDS );
    }

    private static function take_note( $id ) {
        $note = get_transient( 'alss_note_' . $id );
        if ( false === $note ) {
            return '';
        }
        delete_transient( 'alss_note_' . $id );
        return $note;
    }

    public static function start_pull( $then_export = false ) {
        if ( ! settings::is_connected() ) {
            return new \WP_Error( 'alss_not_connected', __( 'Connect Google Sheets first.', 'softicon-sheet-sync' ) );
        }
        as_enqueue_async_action( 'alss_pull', array( array( 'then_export' => (bool) $then_export ) ), self::GROUP );
        return true;
    }

    // applies cells edited in the sheet since the last sync, for edits the Apps Script missed
    public function pull( $args = array() ) {
        $then_export = ! empty( $args['then_export'] );
        $source      = $then_export ? 'schedule' : 'admin';

        try {
            $values = self::check( google::get_values( google::range( 'A1:' . google::column( count( columns::all() ) + 10 ) ) ) );
        } catch ( \Exception $e ) {
            log::error( self::message( $e ), $source );
            return;
        }

        $header   = array_map( array( 'ALSS_Sheet_Sync\columns', 'key_for_header' ), isset( $values[0] ) ? $values[0] : array() );
        $id_index = array_search( 'id', $header, true );
        $editable = columns::editable();
        $applied  = 0;
        $failed   = 0;
        $more     = false;

        if ( false !== $id_index ) {
            foreach ( array_slice( $values, 1 ) as $cells ) {
                $id         = isset( $cells[ $id_index ] ) ? absint( $cells[ $id_index ] ) : 0;
                $remembered = $id ? product_row::remembered( $id ) : array();
                $product    = $remembered ? wc_get_product( $id ) : null;
                if ( ! $product ) {
                    continue;
                }

                $row     = self::keyed( $header, $cells );
                $changed = array();
                foreach ( $editable as $key ) {
                    if ( array_key_exists( $key, $row ) && isset( $remembered[ $key ] ) && columns::normalize( $key, $row[ $key ] ) !== $remembered[ $key ] ) {
                        $changed[] = $key;
                    }
                }
                if ( ! $changed ) {
                    continue;
                }

                if ( $applied + $failed >= self::PULL_BATCH ) {
                    $more = true;
                    break;
                }

                $result = product_row::apply( $product, $row, $changed );
                $result['errors'] ? $failed++ : $applied++;
                if ( $result['errors'] ) {
                    self::note( $id, '✗ ' . implode( ' ', $result['errors'] ) );
                    /* translators: 1: product id, 2: error */
                    log::error( sprintf( __( 'Product #%1$d: %2$s', 'softicon-sheet-sync' ), $id, implode( ' ', $result['errors'] ) ), $source );
                }

                // remember the sheet values so the next run doesn't see the same edit again, the push then writes the real values back
                update_post_meta( $id, '_alss_synced', array_merge( $remembered, array_map( function ( $key ) use ( $row ) { return columns::normalize( $key, $row[ $key ] ); }, array_combine( $changed, $changed ) ) ) );
                self::queue( array( $id ) );
            }
        }

        if ( $applied ) {
            /* translators: %d: number of products */
            log::add( sprintf( _n( 'Updated %d product from Google Sheets.', 'Updated %d products from Google Sheets.', $applied, 'softicon-sheet-sync' ), $applied ), $source );
        }

        if ( $more ) {
            as_enqueue_async_action( 'alss_pull', array( array( 'then_export' => $then_export ) ), self::GROUP );
        } elseif ( $then_export ) {
            self::start_export( 'schedule' );
        }
    }

    public function scheduled() {
        if ( settings::is_connected() ) {
            self::start_pull( true );
        }
    }

    public static function reschedule() {
        if ( ! function_exists('as_unschedule_all_actions') ) {
            return;
        }
        as_unschedule_all_actions( 'alss_scheduled', array(), self::GROUP );
        $interval = settings::schedule_interval( settings::get('schedule') );
        if ( $interval && settings::is_connected() ) {
            as_schedule_recurring_action( time() + $interval, $interval, 'alss_scheduled', array(), self::GROUP );
        }
    }

    public static function unschedule_all() {
        if ( ! function_exists('as_unschedule_all_actions') ) {
            return;
        }
        foreach ( array( 'alss_export_batch', 'alss_push', 'alss_pull', 'alss_scheduled' ) as $hook ) {
            as_unschedule_all_actions( $hook, array(), self::GROUP );
        }
    }

    // header keys + row cells => column key => value
    public static function keyed( $header, $cells ) {
        $row = array();
        foreach ( $header as $index => $key ) {
            if ( $key && ! array_key_exists( $key, $row ) ) {
                $row[ $key ] = isset( $cells[ $index ] ) ? $cells[ $index ] : '';
            }
        }
        return $row;
    }

    private static function check( $result ) {
        if ( is_wp_error( $result ) ) {
            throw new \Exception( esc_html( $result->get_error_message() ) );
        }
        return $result;
    }

    // Google leaves sheetId out of the JSON when it is 0
    public static function sheet_id( $item ) {
        return isset( $item['properties']['sheetId'] ) ? (int) $item['properties']['sheetId'] : 0;
    }

    // check() escapes for the exception, logs and notices escape again on output
    public static function message( $e ) {
        return html_entity_decode( $e->getMessage(), ENT_QUOTES, 'UTF-8' );
    }

    private static function color( $hex ) {
        $hex = ltrim( $hex, '#' );
        return array(
            'red'   => hexdec( substr( $hex, 0, 2 ) ) / 255,
            'green' => hexdec( substr( $hex, 2, 2 ) ) / 255,
            'blue'  => hexdec( substr( $hex, 4, 2 ) ) / 255,
        );
    }
}
