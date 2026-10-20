<?php
namespace ALSS_Sheet_Sync;
if ( !defined('ABSPATH') ) { exit;}

// one product <-> one sheet row
class product_row {

    // Google Sheets cells hold at most 50,000 characters
    const TOO_LONG = '[Too long for a sheet cell, edit it in WooCommerce]';

    public static function exportable( $product ) {
        if ( ! $product ) {
            return false;
        }
        if ( $product->is_type('variation') ) {
            $parent = wc_get_product( $product->get_parent_id() );
            return $parent && self::exportable( $parent ) && in_array( $product->get_status('edit'), array( 'publish', 'private' ), true );
        }
        return array_key_exists( $product->get_status('edit'), columns::STATUS );
    }

    // the product followed by its variations, in sheet order
    public static function family( $product ) {
        $products = array( $product );
        if ( $product->is_type('variable') ) {
            foreach ( $product->get_children() as $child_id ) {
                $child = wc_get_product( $child_id );
                if ( self::exportable( $child ) ) {
                    $products[] = $child;
                }
            }
        }
        return $products;
    }

    public static function build( $product, $note = '' ) {
        $row = array();
        foreach ( columns::enabled() as $key ) {
            $row[] = 'sync_status' === $key ? $note : self::value( $product, $key );
        }
        return $row;
    }

    public static function value( $product, $key ) {
        $is_variation = $product->is_type('variation');

        switch ( $key ) {
            case 'id':
                return $product->get_id();
            case 'type':
                return $product->get_type();
            case 'name':
                return $product->get_name();
            case 'sku':
                return $product->get_sku('edit');
            case 'regular_price':
            case 'sale_price':
                if ( ! self::has_price( $product ) ) {
                    return '';
                }
                $price = 'regular_price' === $key ? $product->get_regular_price('edit') : $product->get_sale_price('edit');
                return is_numeric( $price ) ? (float) $price : '';
            case 'stock':
                if ( true === $product->get_manage_stock('edit') ) {
                    return (int) wc_stock_amount( $product->get_stock_quantity('edit') );
                }
                $status = $product->get_stock_status('edit');
                return isset( columns::STOCK[ $status ] ) ? columns::STOCK[ $status ] : $status;
            case 'status':
                $status = $product->get_status('edit');
                return isset( columns::STATUS[ $status ] ) ? columns::STATUS[ $status ] : $status;
            case 'categories':
                if ( $is_variation ) {
                    return '';
                }
                $names = array();
                foreach ( $product->get_category_ids('edit') as $term_id ) {
                    $term = get_term( $term_id, 'product_cat' );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $names[] = $term->name;
                    }
                }
                natcasesort( $names );
                return implode( ', ', $names );
            case 'short_description':
                return $is_variation ? '' : self::text( $product->get_short_description('edit') );
            case 'description':
                return self::text( $product->get_description('edit') );
            case 'total_sales':
                return (int) $product->get_total_sales('edit');
            case 'url':
                return $is_variation ? '' : (string) get_permalink( $product->get_id() );
        }

        return '';
    }

    // the editable values as last written to or read from the sheet, so a scheduled pull only applies what changed there
    public static function remember( $product ) {
        $values = array();
        foreach ( columns::editable() as $key ) {
            $values[ $key ] = columns::normalize( $key, self::value( $product, $key ) );
        }
        update_post_meta( $product->get_id(), '_alss_synced', $values );
    }

    public static function remembered( $product_id ) {
        $values = get_post_meta( $product_id, '_alss_synced', true );
        return is_array( $values ) ? $values : array();
    }

    // values: column key => cell value, keys: the columns to apply
    public static function apply( $product, $values, $keys ) {
        $errors  = array();
        $pending = array();

        foreach ( $keys as $key ) {
            if ( ! array_key_exists( $key, $values ) || self::TOO_LONG === $values[ $key ] ) {
                continue;
            }
            $new = columns::normalize( $key, $values[ $key ] );
            if ( $new !== columns::normalize( $key, self::value( $product, $key ) ) ) {
                $pending[ $key ] = $new;
            }
        }

        if ( isset( $pending['regular_price'] ) || isset( $pending['sale_price'] ) ) {
            $error = self::check_prices( $product, $pending );
            if ( $error ) {
                $errors[] = $error;
                unset( $pending['regular_price'], $pending['sale_price'] );
            }
        }

        $changed = array();
        foreach ( $pending as $key => $value ) {
            try {
                $result = self::set( $product, $key, $value );
            } catch ( \WC_Data_Exception $e ) {
                $result = new \WP_Error( 'alss_invalid', $e->getMessage() );
            }
            if ( is_wp_error( $result ) ) {
                $errors[] = $result->get_error_message();
            } else {
                $changed[] = $key;
            }
        }

        if ( $changed ) {
            try {
                $product->save();
            } catch ( \Exception $e ) {
                $errors[] = $e->getMessage();
                $changed  = array();
            }
        }

        return array( 'changed' => $changed, 'errors' => $errors );
    }

    private static function set( $product, $key, $value ) {
        $is_variation = $product->is_type('variation');

        switch ( $key ) {
            case 'name':
                if ( $is_variation ) {
                    return new \WP_Error( 'alss_invalid', __( 'Variation names come from the parent product, edit the parent row.', 'softicon-sheet-sync' ) );
                }
                $value = sanitize_text_field( $value );
                if ( '' === $value ) {
                    return new \WP_Error( 'alss_invalid', __( 'Name cannot be empty.', 'softicon-sheet-sync' ) );
                }
                $product->set_name( $value );
                break;

            case 'sku':
                $product->set_sku( wc_clean( $value ) );
                break;

            case 'regular_price':
                $product->set_regular_price( $value );
                break;

            case 'sale_price':
                $product->set_sale_price( $value );
                break;

            case 'stock':
                if ( is_numeric( $value ) ) {
                    if ( $product->is_type('external') ) {
                        return new \WP_Error( 'alss_invalid', __( 'External products have no stock quantity.', 'softicon-sheet-sync' ) );
                    }
                    $product->set_manage_stock( true );
                    $product->set_stock_quantity( wc_stock_amount( $value ) );
                    break;
                }
                if ( ! isset( columns::STOCK[ $value ] ) ) {
                    return new \WP_Error( 'alss_invalid', __( 'Stock must be a number, or In stock, Out of stock or On backorder.', 'softicon-sheet-sync' ) );
                }
                $product->set_manage_stock( false );
                $product->set_stock_status( $value );
                break;

            case 'status':
                $allowed = $is_variation ? array( 'publish', 'private' ) : array( 'publish', 'draft', 'pending', 'private' );
                if ( ! in_array( $value, $allowed, true ) ) {
                    $labels = array_intersect_key( columns::STATUS, array_flip( $allowed ) );
                    /* translators: %s: allowed status names */
                    return new \WP_Error( 'alss_invalid', sprintf( __( 'Status must be one of: %s.', 'softicon-sheet-sync' ), implode( ', ', $labels ) ) );
                }
                $product->set_status( $value );
                break;

            case 'categories':
                if ( $is_variation ) {
                    return new \WP_Error( 'alss_invalid', __( 'Variations have no categories, edit the parent row.', 'softicon-sheet-sync' ) );
                }
                $ids     = array();
                $missing = array();
                foreach ( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) as $name ) {
                    $term = get_term_by( 'name', $name, 'product_cat' );
                    if ( $term ) {
                        $ids[] = (int) $term->term_id;
                    } else {
                        $missing[] = $name;
                    }
                }
                if ( $missing ) {
                    /* translators: %s: category names */
                    return new \WP_Error( 'alss_invalid', sprintf( __( 'Unknown categories: %s. Create them in WooCommerce first.', 'softicon-sheet-sync' ), implode( ', ', $missing ) ) );
                }
                $product->set_category_ids( $ids );
                break;

            case 'short_description':
                if ( $is_variation ) {
                    return new \WP_Error( 'alss_invalid', __( 'Variations have no short description, use the Description column.', 'softicon-sheet-sync' ) );
                }
                $product->set_short_description( wp_kses_post( $value ) );
                break;

            case 'description':
                $product->set_description( wp_kses_post( $value ) );
                break;

            default:
                return new \WP_Error( 'alss_invalid', __( 'This column is read only.', 'softicon-sheet-sync' ) );
        }

        return true;
    }

    private static function check_prices( $product, $pending ) {
        if ( ! self::has_price( $product ) ) {
            return __( 'Variable and grouped products have no price of their own, edit the variation rows.', 'softicon-sheet-sync' );
        }

        $regular = isset( $pending['regular_price'] ) ? $pending['regular_price'] : columns::normalize( 'regular_price', $product->get_regular_price('edit') );
        $sale    = isset( $pending['sale_price'] ) ? $pending['sale_price'] : columns::normalize( 'sale_price', $product->get_sale_price('edit') );

        foreach ( array( $regular, $sale ) as $price ) {
            if ( '' !== $price && ( ! is_numeric( $price ) || $price < 0 ) ) {
                return __( 'Prices must be positive numbers.', 'softicon-sheet-sync' );
            }
        }
        if ( '' !== $sale && ( '' === $regular || (float) $sale >= (float) $regular ) ) {
            return __( 'Sale price must be lower than the regular price.', 'softicon-sheet-sync' );
        }
        return '';
    }

    private static function has_price( $product ) {
        return ! $product->is_type( array( 'variable', 'grouped' ) );
    }

    private static function text( $text ) {
        return mb_strlen( $text ) > 49000 ? self::TOO_LONG : $text;
    }
}
