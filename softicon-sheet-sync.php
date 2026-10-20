<?php
/*
*
* Plugin Name: SoftIcon Sheet Sync for WooCommerce
* Description: Two-way sync between WooCommerce products and a Google Sheet. Edit stock, prices, SKUs, names and descriptions in the sheet and your store updates instantly.
* Version: 1.0.0
* Requires at least: 6.5
* Requires PHP: 7.4
* Requires Plugins: woocommerce
* Author: softiconai
* Author URI: https://profiles.wordpress.org/softiconai/
* License: GPLv3 or later
* License URI: https://www.gnu.org/licenses/gpl-3.0.html
* Text Domain: softicon-sheet-sync
* Domain Path: /languages
* WC requires at least: 8.0
* WC tested up to: 10.3
*
*/


if ( !defined('ABSPATH') ) { exit; }

class ALSS_Sheet_Sync{

    public static $instance;

    private function __construct()
    {
        $this->defined_constants();
        $this->load_classes();
    }

    public static function get_instance() {
        if( self::$instance ) {
            return self::$instance;
        }

        self::$instance = new self();
        return self::$instance;
    }

    public function defined_constants() {
        define('ALSS_PLUGIN_VERSION', '1.0.0');
        define('ALSS_PLUGIN_FILE', __FILE__);
        define('ALSS_PLUGIN_PATH', plugin_dir_path(__FILE__));
        define('ALSS_PLUGIN_URL', plugin_dir_url(__FILE__));
    }

    public function load_classes() {

        require_once ALSS_PLUGIN_PATH . 'includes/settings.php';
        require_once ALSS_PLUGIN_PATH . 'includes/log.php';
        require_once ALSS_PLUGIN_PATH . 'includes/google.php';
        require_once ALSS_PLUGIN_PATH . 'includes/columns.php';
        require_once ALSS_PLUGIN_PATH . 'includes/product-row.php';
        require_once ALSS_PLUGIN_PATH . 'includes/sync.php';
        require_once ALSS_PLUGIN_PATH . 'includes/hooks.php';
        require_once ALSS_PLUGIN_PATH . 'includes/rest.php';
        require_once ALSS_PLUGIN_PATH . 'includes/script.php';
        require_once ALSS_PLUGIN_PATH . 'includes/admin.php';

        new ALSS_Sheet_Sync\sync();
        new ALSS_Sheet_Sync\hooks();
        new ALSS_Sheet_Sync\rest();
        new ALSS_Sheet_Sync\admin();

        add_action('before_woocommerce_init', array( $this, 'declare_compatibility' ));
        register_deactivation_hook(__FILE__, array( 'ALSS_Sheet_Sync\sync', 'unschedule_all' ));
    }

    // only products are touched, orders are never read
    public function declare_compatibility() {
        if ( class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil') ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }
}
ALSS_Sheet_Sync::get_instance();
