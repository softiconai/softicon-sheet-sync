<?php
if ( !defined('WP_UNINSTALL_PLUGIN') ) { exit; }

$alss_options = array( 'alss_settings', 'alss_credentials', 'alss_sheet', 'alss_secret', 'alss_setup_done', 'alss_wizard', 'alss_export', 'alss_queue', 'alss_log', 'alss_script_seen', 'alss_script_version', 'alss_width' );
foreach ( $alss_options as $alss_option ) {
    delete_option( $alss_option );
}
delete_transient('alss_token');
delete_post_meta_by_key('_alss_synced');

if ( function_exists('as_unschedule_all_actions') ) {
    foreach ( array( 'alss_export_batch', 'alss_push', 'alss_pull', 'alss_scheduled' ) as $alss_hook ) {
        as_unschedule_all_actions( $alss_hook, array(), 'alss' );
    }
}
