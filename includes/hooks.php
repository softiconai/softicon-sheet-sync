<?php
namespace ALSS_Sheet_Sync;
if ( !defined('ABSPATH') ) { exit;}

// WooCommerce changes => sheet queue
class hooks {

    public function __construct()
    {
        add_action('woocommerce_new_product', array( $this, 'changed' ));
        add_action('woocommerce_update_product', array( $this, 'changed' ));
        add_action('woocommerce_new_product_variation', array( $this, 'changed' ));
        add_action('woocommerce_update_product_variation', array( $this, 'changed' ));

        // orders reduce stock with a direct query, these fire instead of the update hooks
        add_action('woocommerce_product_set_stock', array( $this, 'stock_changed' ));
        add_action('woocommerce_variation_set_stock', array( $this, 'stock_changed' ));

        add_action('wp_trash_post', array( $this, 'post_changed' ));
        add_action('untrashed_post', array( $this, 'post_changed' ));
        add_action('before_delete_post', array( $this, 'post_changed' ));
    }

    public function changed( $product_id ) {
        $this->post_changed( $product_id );
    }

    public function stock_changed( $product ) {
        if ( $product instanceof \WC_Product ) {
            sync::queue( array( $product->get_id() ) );
        }
    }

    // a variable product brings its variation rows along, so publishing, trashing or deleting it moves them too
    public function post_changed( $post_id ) {
        $type = get_post_type( $post_id );
        if ( 'product_variation' === $type ) {
            sync::queue( array( $post_id ) );
            return;
        }
        if ( 'product' !== $type ) {
            return;
        }

        $ids     = array( $post_id );
        $product = wc_get_product( $post_id );
        if ( $product && $product->is_type('variable') ) {
            $ids = array_merge( $ids, $product->get_children() );
        }
        sync::queue( $ids );
    }
}
