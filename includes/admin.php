<?php
namespace ALSS_Sheet_Sync;
if ( !defined('ABSPATH') ) { exit;}

// WooCommerce > Sheet Sync: setup wizard, dashboard, settings, Apps Script and log
class admin {

    const SLUG = 'alss-sheet-sync';
    const CAP  = 'manage_woocommerce';

    public function __construct()
    {
        add_action('admin_menu', array( $this, 'menu' ), 60);
        add_action('admin_enqueue_scripts', array( $this, 'assets' ));
        add_filter('plugin_action_links_' . plugin_basename( ALSS_PLUGIN_FILE ), array( $this, 'action_links' ));

        foreach ( array( 'credentials', 'sheet', 'settings', 'action', 'wizard' ) as $form ) {
            add_action('admin_post_alss_' . $form, array( $this, 'save_' . $form ));
        }
    }

    public function menu() {
        add_submenu_page( 'woocommerce', __( 'Sheet Sync', 'softicon-sheet-sync' ), __( 'Sheet Sync', 'softicon-sheet-sync' ), self::CAP, self::SLUG, array( $this, 'render' ) );
    }

    public function action_links( $links ) {
        array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'softicon-sheet-sync' ) . '</a>' );
        return $links;
    }

    public function assets( $hook ) {
        if ( 'woocommerce_page_' . self::SLUG !== $hook ) {
            return;
        }
        wp_enqueue_style( 'alss-admin', ALSS_PLUGIN_URL . 'assets/admin.css', array(), ALSS_PLUGIN_VERSION );
        wp_enqueue_script( 'alss-admin', ALSS_PLUGIN_URL . 'assets/admin.js', array(), ALSS_PLUGIN_VERSION, true );
        wp_localize_script( 'alss-admin', 'alssAdmin', array(
            'statusUrl' => rest_url( rest::NS . '/status' ),
            'nonce'     => wp_create_nonce('wp_rest'),
            'i18n'      => array(
                'copied'   => __( 'Copied!', 'softicon-sheet-sync' ),
                /* translators: 1: products done, 2: total products */
                'running'  => __( 'Sending products to Google Sheets: %1$d of %2$d', 'softicon-sheet-sync' ),
                'done'     => __( 'All products are in your Google Sheet.', 'softicon-sheet-sync' ),
                'waiting'  => __( 'Waiting for the script... run alssInstall in the Apps Script editor.', 'softicon-sheet-sync' ),
                'confirm'  => __( 'Are you sure?', 'softicon-sheet-sync' ),
            ),
        ) );
    }

    public static function url( $args = array() ) {
        return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url('admin.php') );
    }

    public function render() {
        if ( ! current_user_can( self::CAP ) ) {
            return;
        }

        echo '<div class="wrap alss">';
        echo '<h1 class="alss-title">' . esc_html__( 'SoftIcon Sheet Sync', 'softicon-sheet-sync' ) . '</h1>';
        $this->notice();

        if ( ! get_option('alss_setup_done') ) {
            $this->wizard();
        } else {
            $tabs = array(
                'dashboard' => __( 'Dashboard', 'softicon-sheet-sync' ),
                'settings'  => __( 'Settings', 'softicon-sheet-sync' ),
                'script'    => __( 'Instant sync (Apps Script)', 'softicon-sheet-sync' ),
                'log'       => __( 'Log', 'softicon-sheet-sync' ),
            );
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab switch only
            $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
            $tab = isset( $tabs[ $tab ] ) ? $tab : 'dashboard';

            echo '<nav class="nav-tab-wrapper">';
            foreach ( $tabs as $key => $label ) {
                printf( '<a href="%s" class="nav-tab %s">%s</a>', esc_url( self::url( array( 'tab' => $key ) ) ), $key === $tab ? 'nav-tab-active' : '', esc_html( $label ) );
            }
            echo '</nav>';
            call_user_func( array( $this, 'tab_' . $tab ) );
        }
        echo '</div>';
    }

    private function wizard() {
        $step  = self::wizard_step();
        $steps = array(
            1 => __( 'Google account', 'softicon-sheet-sync' ),
            2 => __( 'Your sheet', 'softicon-sheet-sync' ),
            3 => __( 'Send products', 'softicon-sheet-sync' ),
            4 => __( 'Instant sync', 'softicon-sheet-sync' ),
        );

        echo '<ol class="alss-steps">';
        foreach ( $steps as $number => $label ) {
            $class = $number < $step ? 'is-done' : ( $number === $step ? 'is-current' : '' );
            printf( '<li class="%s"><span>%d</span>%s</li>', esc_attr( $class ), (int) $number, esc_html( $label ) );
        }
        echo '</ol><div class="alss-card alss-wizard">';

        if ( 1 === $step ) {
            $this->credentials_form( true );
        } elseif ( 2 === $step ) {
            $this->sheet_form( true );
        } elseif ( 3 === $step ) {
            $this->first_sync();
        } else {
            echo '<h2>' . esc_html__( 'Turn on instant sync', 'softicon-sheet-sync' ) . '</h2>';
            echo '<p>' . esc_html__( 'Your products are in the sheet and WooCommerce changes already flow there. This last step sends edits made in the sheet back to your store the moment you make them.', 'softicon-sheet-sync' ) . '</p>';
            $this->script_steps();
            echo '<div class="alss-buttons">';
            $this->wizard_button( 'finish', __( 'Finish setup', 'softicon-sheet-sync' ), 'button-primary' );
            $this->wizard_button( 'finish', __( 'Skip, I will do it later', 'softicon-sheet-sync' ), 'button-link' );
            echo '</div>';
        }
        echo '</div>';
    }

    private static function wizard_step() {
        if ( ! settings::credentials() ) {
            return 1;
        }
        if ( ! settings::sheet() ) {
            return 2;
        }
        return max( 3, min( 4, (int) get_option( 'alss_wizard', 3 ) ) );
    }

    private function wizard_button( $go, $label, $class ) {
        echo '<form method="post" action="' . esc_url( admin_url('admin-post.php') ) . '" class="alss-inline">';
        wp_nonce_field('alss_wizard');
        echo '<input type="hidden" name="action" value="alss_wizard"><input type="hidden" name="go" value="' . esc_attr( $go ) . '">';
        echo '<button type="submit" class="button ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
    }

    private function credentials_form( $wizard ) {
        echo '<h2>' . esc_html__( 'Connect your Google account', 'softicon-sheet-sync' ) . '</h2>';
        echo '<p>' . esc_html__( 'The plugin talks to Google Sheets through a service account, a robot Google user that only sees the sheets you share with it. It is free and takes about five minutes.', 'softicon-sheet-sync' ) . '</p>';
        echo '<ol class="alss-howto">';
        printf( '<li>%s <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">%s</a></li>', esc_html__( 'Create a project in Google Cloud, any name works.', 'softicon-sheet-sync' ), esc_html__( 'Create project', 'softicon-sheet-sync' ) );
        printf( '<li>%s <a href="https://console.cloud.google.com/apis/library/sheets.googleapis.com" target="_blank" rel="noopener">%s</a></li>', esc_html__( 'With that project selected, turn on the Google Sheets API.', 'softicon-sheet-sync' ), esc_html__( 'Enable Sheets API', 'softicon-sheet-sync' ) );
        printf( '<li>%s <a href="https://console.cloud.google.com/iam-admin/serviceaccounts/create" target="_blank" rel="noopener">%s</a></li>', esc_html__( 'Create a service account. Fill in a name, then click Done; it needs no roles.', 'softicon-sheet-sync' ), esc_html__( 'Create service account', 'softicon-sheet-sync' ) );
        echo '<li>' . esc_html__( 'Open the new service account, go to the Keys tab, click Add key, Create new key, choose JSON and click Create. A .json file downloads.', 'softicon-sheet-sync' ) . '</li>';
        echo '<li>' . esc_html__( 'Upload that file below.', 'softicon-sheet-sync' ) . '</li>';
        echo '</ol>';

        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url('admin-post.php') ) . '">';
        wp_nonce_field('alss_credentials');
        echo '<input type="hidden" name="action" value="alss_credentials">';
        echo '<p><input type="file" name="alss_key" accept=".json,application/json"></p>';
        echo '<details><summary>' . esc_html__( 'Or paste the JSON instead', 'softicon-sheet-sync' ) . '</summary>';
        echo '<textarea name="alss_key_json" rows="6" class="large-text code" autocomplete="off" spellcheck="false"></textarea></details>';
        echo '<p class="description">' . esc_html__( 'The key is stored in your WordPress database and only used to talk to Google. Never share this file.', 'softicon-sheet-sync' ) . '</p>';
        submit_button( $wizard ? __( 'Upload and continue', 'softicon-sheet-sync' ) : __( 'Replace key', 'softicon-sheet-sync' ) );
        echo '</form>';
    }

    private function sheet_form( $wizard ) {
        $sheet = settings::sheet();
        $email = settings::client_email();

        echo '<h2>' . esc_html__( 'Connect your Google Sheet', 'softicon-sheet-sync' ) . '</h2>';
        echo '<ol class="alss-howto">';
        echo '<li>' . wp_kses_post( __( 'Create a new Google Sheet at <a href="https://sheets.new" target="_blank" rel="noopener">sheets.new</a> or open an existing one.', 'softicon-sheet-sync' ) ) . '</li>';
        echo '<li>' . esc_html__( 'Click Share and add this email as an Editor:', 'softicon-sheet-sync' );
        echo '<span class="alss-copy-row"><code id="alss-email">' . esc_html( $email ) . '</code> <button type="button" class="button button-small" data-alss-copy="#alss-email">' . esc_html__( 'Copy', 'softicon-sheet-sync' ) . '</button></span></li>';
        echo '<li>' . esc_html__( 'Paste the sheet URL below.', 'softicon-sheet-sync' ) . '</li>';
        echo '</ol>';

        echo '<form method="post" action="' . esc_url( admin_url('admin-post.php') ) . '">';
        wp_nonce_field('alss_sheet');
        echo '<input type="hidden" name="action" value="alss_sheet">';
        echo '<table class="form-table" role="presentation">';
        printf( '<tr><th><label for="alss-url">%s</label></th><td><input type="url" id="alss-url" name="url" class="large-text" required placeholder="https://docs.google.com/spreadsheets/d/..." value="%s"></td></tr>', esc_html__( 'Sheet URL', 'softicon-sheet-sync' ), esc_attr( $sheet ? 'https://docs.google.com/spreadsheets/d/' . $sheet['id'] . '/edit' : '' ) );
        printf( '<tr><th><label for="alss-tab">%s</label></th><td><input type="text" id="alss-tab" name="tab" class="regular-text" required value="%s"><p class="description">%s</p></td></tr>', esc_html__( 'Tab name', 'softicon-sheet-sync' ), esc_attr( $sheet ? $sheet['tab'] : 'Products' ), esc_html__( 'The tab at the bottom of the sheet. It is created if it does not exist. Anything already in it will be replaced by your products.', 'softicon-sheet-sync' ) );
        echo '</table>';
        submit_button( $wizard ? __( 'Check access and continue', 'softicon-sheet-sync' ) : __( 'Save sheet', 'softicon-sheet-sync' ) );
        echo '</form>';
    }

    private function first_sync() {
        $state = sync::export_state();
        echo '<h2>' . esc_html__( 'Send your products to the sheet', 'softicon-sheet-sync' ) . '</h2>';
        echo '<p>' . esc_html__( 'Every product and variation gets its own row. This runs in the background, you can leave this page.', 'softicon-sheet-sync' ) . '</p>';
        $this->progress();

        echo '<div class="alss-buttons">';
        if ( ! $state || 'done' !== $state['status'] ) {
            $this->action_button( 'export', __( 'Send products to Google Sheets', 'softicon-sheet-sync' ), 'button-primary button-hero', 'data-alss-hide-running' );
        }
        echo '<span data-alss-when="done" ' . ( $state && 'done' === $state['status'] ? '' : 'hidden' ) . '>';
        printf( '<a href="%s" target="_blank" rel="noopener" class="button">%s</a> ', esc_url( settings::sheet_url() ), esc_html__( 'Open Google Sheet', 'softicon-sheet-sync' ) );
        $this->wizard_button( 'script', __( 'Continue', 'softicon-sheet-sync' ), 'button-primary' );
        echo '</span></div>';
    }

    private function progress() {
        $state = sync::export_state();
        $done  = $state ? (int) $state['done'] : 0;
        $total = $state ? max( 1, (int) $state['total'] ) : 1;
        $show  = $state && in_array( $state['status'], array( 'running', 'error' ), true );

        printf(
            '<div class="alss-progress" id="alss-progress" data-status="%s" %s><div class="alss-progress-bar"><span style="width:%d%%"></span></div><p class="alss-progress-text">%s</p></div>',
            esc_attr( $state ? $state['status'] : '' ),
            $show ? '' : 'hidden',
            (int) round( 100 * $done / $total ),
            esc_html( $state && 'error' === $state['status'] ? $state['message'] : '' )
        );
    }

    private function action_button( $do, $label, $class = '', $attr = '' ) {
        echo '<form method="post" action="' . esc_url( admin_url('admin-post.php') ) . '" class="alss-inline" ' . esc_attr( $attr ) . '>';
        wp_nonce_field('alss_action');
        echo '<input type="hidden" name="action" value="alss_action"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
        $confirm = in_array( $do, array( 'disconnect', 'regenerate' ), true ) ? ' data-alss-confirm' : '';
        echo '<button type="submit" class="button ' . esc_attr( $class ) . '"' . $confirm . '>' . esc_html( $label ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute
    }

    private function tab_dashboard() {
        $sheet    = settings::sheet();
        $seen     = (int) get_option('alss_script_seen');
        $schedule = settings::get('schedule');
        $labels   = $this->schedule_labels();

        echo '<div class="alss-grid">';
        $this->status_card( __( 'Google account', 'softicon-sheet-sync' ), '<code>' . esc_html( settings::client_email() ) . '</code>', true );
        $this->status_card(
            __( 'Sheet', 'softicon-sheet-sync' ),
            sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a> &rsaquo; %s', esc_url( settings::sheet_url() ), esc_html( ! empty( $sheet['title'] ) ? $sheet['title'] : __( 'Google Sheet', 'softicon-sheet-sync' ) ), esc_html( $sheet['tab'] ) ),
            true
        );
        $this->status_card(
            __( 'Sheet to WooCommerce', 'softicon-sheet-sync' ),
            '<span id="alss-script-status">' . ( $seen
                /* translators: %s: human readable time difference */
                ? esc_html( sprintf( __( 'Instant, last request %s ago', 'softicon-sheet-sync' ), human_time_diff( $seen ) ) )
                : '<a href="' . esc_url( self::url( array( 'tab' => 'script' ) ) ) . '">' . esc_html__( 'Turn on instant sync', 'softicon-sheet-sync' ) . '</a>' ) . '</span>',
            (bool) $seen
        );
        $this->status_card(
            __( 'WooCommerce to sheet', 'softicon-sheet-sync' ),
            esc_html( settings::get('auto_push') ? __( 'Automatic, a few seconds after each change', 'softicon-sheet-sync' ) : __( 'Off, use the button below', 'softicon-sheet-sync' ) )
                /* translators: %s: schedule name */
                . ( $schedule ? '<br>' . esc_html( sprintf( __( 'Full sync: %s', 'softicon-sheet-sync' ), $labels[ $schedule ] ) ) : '' ),
            (bool) settings::get('auto_push')
        );
        echo '</div>';

        echo '<div class="alss-card"><h2>' . esc_html__( 'Sync now', 'softicon-sheet-sync' ) . '</h2>';
        $this->progress();
        echo '<div class="alss-buttons">';
        $this->action_button( 'export', __( 'Send all products to Google Sheets', 'softicon-sheet-sync' ), 'button-primary' );
        $this->action_button( 'pull', __( 'Pull changes from Google Sheets', 'softicon-sheet-sync' ) );
        printf( '<a href="%s" target="_blank" rel="noopener" class="button">%s</a>', esc_url( settings::sheet_url() ), esc_html__( 'Open Google Sheet', 'softicon-sheet-sync' ) );
        echo '</div>';
        echo '<p class="description">' . esc_html__( 'Send rewrites every row from WooCommerce. Pull applies cells edited in the sheet that did not reach the store, for example while instant sync was off.', 'softicon-sheet-sync' ) . '</p></div>';

        echo '<div class="alss-card"><h2>' . esc_html__( 'Recent activity', 'softicon-sheet-sync' ) . '</h2>';
        $this->log_table( 8 );
        printf( '<p><a href="%s">%s</a></p></div>', esc_url( self::url( array( 'tab' => 'log' ) ) ), esc_html__( 'View full log', 'softicon-sheet-sync' ) );
    }

    private function status_card( $title, $html, $ok ) {
        printf( '<div class="alss-card alss-stat %s"><h3>%s</h3><p>%s</p></div>', $ok ? 'is-ok' : 'is-off', esc_html( $title ), wp_kses_post( $html ) );
    }

    private function schedule_labels() {
        return array(
            ''       => __( 'Off', 'softicon-sheet-sync' ),
            '10min'  => __( 'Every 10 minutes', 'softicon-sheet-sync' ),
            '30min'  => __( 'Every 30 minutes', 'softicon-sheet-sync' ),
            'hourly' => __( 'Every hour', 'softicon-sheet-sync' ),
            'daily'  => __( 'Once a day', 'softicon-sheet-sync' ),
        );
    }

    private function tab_settings() {
        $chosen = (array) settings::get('columns');

        echo '<form method="post" action="' . esc_url( admin_url('admin-post.php') ) . '" class="alss-card">';
        wp_nonce_field('alss_settings');
        echo '<input type="hidden" name="action" value="alss_settings">';
        echo '<h2>' . esc_html__( 'Sync settings', 'softicon-sheet-sync' ) . '</h2><table class="form-table" role="presentation">';

        echo '<tr><th>' . esc_html__( 'Columns', 'softicon-sheet-sync' ) . '</th><td><fieldset>';
        echo '<p class="description">' . esc_html__( 'ID, Type and Sync status are always included. Keep the header names in the sheet as they are, columns are matched by name.', 'softicon-sheet-sync' ) . '</p>';
        foreach ( columns::optional() as $key => $column ) {
            printf( '<label class="alss-check"><input type="checkbox" name="columns[]" value="%s" %s> %s</label>', esc_attr( $key ), checked( in_array( $key, $chosen, true ), true, false ), esc_html( $column['label'] ) );
        }
        echo '</fieldset></td></tr>';

        printf(
            '<tr><th>%s</th><td><label><input type="checkbox" name="auto_push" value="1" %s> %s</label></td></tr>',
            esc_html__( 'WooCommerce to sheet', 'softicon-sheet-sync' ),
            checked( settings::get('auto_push'), true, false ),
            esc_html__( 'Update the sheet automatically when a product is saved or its stock changes after an order', 'softicon-sheet-sync' )
        );

        echo '<tr><th><label for="alss-schedule">' . esc_html__( 'Scheduled full sync', 'softicon-sheet-sync' ) . '</label></th><td><select id="alss-schedule" name="schedule">';
        foreach ( $this->schedule_labels() as $value => $label ) {
            printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( settings::get('schedule'), $value, false ), esc_html( $label ) );
        }
        echo '</select><p class="description">' . esc_html__( 'Pulls missed sheet edits, then rewrites the sheet from WooCommerce. A safety net next to instant sync.', 'softicon-sheet-sync' ) . '</p></td></tr>';
        echo '</table>';
        submit_button();
        echo '</form>';

        echo '<div class="alss-card">';
        $this->sheet_form( false );
        echo '</div><div class="alss-card">';
        $this->credentials_form( false );
        echo '</div>';

        echo '<div class="alss-card alss-danger"><h2>' . esc_html__( 'Disconnect', 'softicon-sheet-sync' ) . '</h2>';
        echo '<p>' . esc_html__( 'Removes the Google key and sheet from this site and stops syncing. Your sheet and products are not changed.', 'softicon-sheet-sync' ) . '</p>';
        $this->action_button( 'disconnect', __( 'Disconnect Google Sheets', 'softicon-sheet-sync' ), 'button-link-delete' );
        echo '</div>';
    }

    private function tab_script() {
        echo '<div class="alss-card"><h2>' . esc_html__( 'Instant sync from the sheet', 'softicon-sheet-sync' ) . '</h2>';
        echo '<p>' . esc_html__( 'A small script inside your Google Sheet sends each edit to WooCommerce right away and writes the result in the Sync status column. It also adds a Sheet Sync menu to the sheet.', 'softicon-sheet-sync' ) . '</p>';
        $this->script_steps();
        echo '</div>';

        echo '<div class="alss-card alss-danger"><h2>' . esc_html__( 'Script key', 'softicon-sheet-sync' ) . '</h2>';
        echo '<p>' . esc_html__( 'If the script code was shared by mistake, make a new key. The old script stops working until you paste the new code.', 'softicon-sheet-sync' ) . '</p>';
        $this->action_button( 'regenerate', __( 'Make a new key', 'softicon-sheet-sync' ) );
        echo '</div>';
    }

    private function script_steps() {
        $seen = (int) get_option('alss_script_seen');

        if ( script::unreachable() ) {
            echo '<div class="notice notice-warning inline"><p>' . wp_kses_post( sprintf(
                /* translators: 1: site address, 2: wp-config line */
                __( 'Google cannot reach %1$s because it only exists on this computer, so instant sync will not work here. For testing, open a tunnel to this site (ngrok or Cloudflare Tunnel) and add %2$s to wp-config.php. The code below then uses that address.', 'softicon-sheet-sync' ),
                '<code>' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</code>',
                "<code>define( 'ALSS_PUBLIC_URL', 'https://your-tunnel-address' );</code>"
            ) ) . '</p></div>';
        } elseif ( script::public_url() ) {
            /* translators: %s: public URL */
            echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( __( 'The script sends edits through %s (ALSS_PUBLIC_URL).', 'softicon-sheet-sync' ), script::public_url() ) ) . '</p></div>';
        }

        echo '<ol class="alss-howto">';
        printf( '<li>%s <a href="%s" target="_blank" rel="noopener">%s</a></li>', esc_html__( 'Open your sheet and click Extensions > Apps Script.', 'softicon-sheet-sync' ), esc_url( settings::sheet_url() ), esc_html__( 'Open sheet', 'softicon-sheet-sync' ) );
        echo '<li>' . esc_html__( 'Delete the code that is there, paste the code below and click Save (the disk icon).', 'softicon-sheet-sync' ) . '</li>';
        echo '<li>' . wp_kses_post( __( 'In the toolbar choose <code>alssInstall</code> next to Debug and click <strong>Run</strong>.', 'softicon-sheet-sync' ) ) . '</li>';
        echo '<li>' . esc_html__( 'Google asks for permission: pick your account, click Advanced, then Go to (project name) and Allow. The warning appears because the script is your own and not published by Google.', 'softicon-sheet-sync' ) . '</li>';
        echo '</ol>';

        echo '<div class="alss-code"><div class="alss-code-head"><strong>Code.gs</strong> <button type="button" class="button button-primary" data-alss-copy="#alss-script">' . esc_html__( 'Copy code', 'softicon-sheet-sync' ) . '</button></div>';
        echo '<textarea id="alss-script" class="large-text code" rows="12" readonly spellcheck="false">' . esc_textarea( script::code() ) . '</textarea></div>';

        printf(
            '<p class="alss-script-state %s" id="alss-script-wait" data-seen="%d">%s</p>',
            $seen ? 'is-ok' : '',
            (int) $seen,
            esc_html( $seen
                /* translators: %s: human readable time difference */
                ? sprintf( __( 'Connected, last request %s ago', 'softicon-sheet-sync' ), human_time_diff( $seen ) )
                : __( 'Waiting for the script... run alssInstall in the Apps Script editor.', 'softicon-sheet-sync' ) )
        );
    }

    private function tab_log() {
        echo '<div class="alss-card"><h2>' . esc_html__( 'Log', 'softicon-sheet-sync' ) . '</h2>';
        $this->log_table( log::MAX );
        $this->action_button( 'clear_log', __( 'Clear log', 'softicon-sheet-sync' ) );
        echo '</div>';
    }

    private function log_table( $limit ) {
        $entries = array_slice( log::all(), 0, $limit );
        if ( ! $entries ) {
            echo '<p>' . esc_html__( 'Nothing synced yet.', 'softicon-sheet-sync' ) . '</p>';
            return;
        }
        $sources = array(
            'sheet'    => __( 'Sheet', 'softicon-sheet-sync' ),
            'store'    => __( 'Store', 'softicon-sheet-sync' ),
            'schedule' => __( 'Schedule', 'softicon-sheet-sync' ),
            'admin'    => __( 'Admin', 'softicon-sheet-sync' ),
        );
        echo '<table class="widefat striped alss-log"><thead><tr><th>' . esc_html__( 'Time', 'softicon-sheet-sync' ) . '</th><th>' . esc_html__( 'From', 'softicon-sheet-sync' ) . '</th><th>' . esc_html__( 'Message', 'softicon-sheet-sync' ) . '</th></tr></thead><tbody>';
        foreach ( $entries as $entry ) {
            printf(
                '<tr class="%s"><td>%s</td><td>%s</td><td>%s</td></tr>',
                'error' === $entry['level'] ? 'is-error' : '',
                esc_html( wp_date( get_option('date_format') . ' ' . get_option('time_format'), $entry['time'] ) ),
                esc_html( isset( $sources[ $entry['source'] ] ) ? $sources[ $entry['source'] ] : $entry['source'] ),
                esc_html( $entry['message'] )
            );
        }
        echo '</tbody></table>';
    }

    public function save_credentials() {
        $this->guard();
        check_admin_referer('alss_credentials');

        $json = '';
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput -- raw JSON can't go through a text sanitizer, google::validate_credentials() decodes it and sanitizes every field it keeps
        if ( ! empty( $_FILES['alss_key']['tmp_name'] ) && is_uploaded_file( $_FILES['alss_key']['tmp_name'] ) ) {
            if ( (int) $_FILES['alss_key']['size'] > 20 * KB_IN_BYTES ) {
                $this->back( 'error', __( 'That file is too large to be a Google key file.', 'softicon-sheet-sync' ) );
            }
            $json = file_get_contents( $_FILES['alss_key']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        } elseif ( ! empty( $_POST['alss_key_json'] ) ) {
            $json = wp_unslash( $_POST['alss_key_json'] );
        }
        // phpcs:enable

        if ( ! $json ) {
            $this->back( 'error', __( 'Choose the JSON key file first.', 'softicon-sheet-sync' ) );
        }

        $credentials = google::validate_credentials( $json );
        if ( is_wp_error( $credentials ) ) {
            $this->back( 'error', $credentials->get_error_message() );
        }
        $token = google::token( $credentials );
        if ( is_wp_error( $token ) ) {
            $this->back( 'error', $token->get_error_message() );
        }

        update_option( 'alss_credentials', $credentials, false );
        delete_transient('alss_token');
        log::add( __( 'Google service account connected.', 'softicon-sheet-sync' ), 'admin' );

        if ( get_option('alss_setup_done') && settings::sheet() ) {
            /* translators: %s: email */
            $this->back( 'success', sprintf( __( 'Key saved. Make sure the sheet is shared with %s.', 'softicon-sheet-sync' ), $credentials['client_email'] ), 'settings' );
        }
        $this->back( 'success', __( 'Google account connected.', 'softicon-sheet-sync' ) );
    }

    public function save_sheet() {
        $this->guard();
        check_admin_referer('alss_sheet');

        $url = isset( $_POST['url'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['url'] ) ) ) : '';
        $tab = isset( $_POST['tab'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['tab'] ) ) ) : '';
        $tab = '' === $tab ? 'Products' : mb_substr( $tab, 0, 100 );

        if ( preg_match( '#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $match ) ) {
            $id = $match[1];
        } elseif ( preg_match( '#^[a-zA-Z0-9_-]{20,}$#', $url ) ) {
            $id = $url;
        } else {
            $this->back( 'error', __( 'That does not look like a Google Sheets URL. Copy it from the address bar of your sheet.', 'softicon-sheet-sync' ), 'settings' );
        }

        $sheet = $this->find_or_create_tab( $id, $tab );
        if ( is_wp_error( $sheet ) ) {
            $this->back( 'error', $sheet->get_error_message(), 'settings' );
        }

        $old = settings::sheet();
        update_option( 'alss_sheet', $sheet, false );
        delete_option('alss_width');
        /* translators: 1: spreadsheet title, 2: tab name */
        log::add( sprintf( __( 'Connected to sheet "%1$s", tab %2$s.', 'softicon-sheet-sync' ), $sheet['title'], $sheet['tab'] ), 'admin' );

        if ( ! get_option('alss_setup_done') ) {
            update_option( 'alss_wizard', 3, false );
            $this->back( 'success', __( 'The sheet is connected.', 'softicon-sheet-sync' ) );
        }

        sync::reschedule();
        if ( ! $old || $old['id'] !== $sheet['id'] || $old['tab'] !== $sheet['tab'] ) {
            // editors of the old spreadsheet can read its script, so its key stops working
            if ( $old && $old['id'] !== $sheet['id'] ) {
                settings::regenerate_secret();
            }
            sync::start_export('admin');
            $this->back( 'success', __( 'Sheet saved and your products are being sent to it now. Paste the Apps Script code into this sheet again to keep instant sync.', 'softicon-sheet-sync' ), 'dashboard' );
        }
        $this->back( 'success', __( 'Sheet saved.', 'softicon-sheet-sync' ), 'settings' );
    }

    // checks access, then makes sure the tab exists and has room for every column
    private function find_or_create_tab( $id, $tab ) {
        $data = google::spreadsheet( $id );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $found = null;
        foreach ( (array) ( isset( $data['sheets'] ) ? $data['sheets'] : array() ) as $item ) {
            if ( isset( $item['properties']['title'] ) && $item['properties']['title'] === $tab ) {
                $found = $item['properties'];
                $found['sheetId'] = sync::sheet_id( $item );
            }
        }

        $requests = array();
        $needed   = count( columns::all() ) + 5;
        if ( ! $found ) {
            $requests[] = array( 'addSheet' => array( 'properties' => array( 'title' => $tab, 'gridProperties' => array( 'rowCount' => 1000, 'columnCount' => $needed ) ) ) );
        } elseif ( isset( $found['gridProperties']['columnCount'] ) && (int) $found['gridProperties']['columnCount'] < $needed ) {
            $requests[] = array( 'updateSheetProperties' => array(
                'properties' => array( 'sheetId' => (int) $found['sheetId'], 'gridProperties' => array( 'columnCount' => $needed ) ),
                'fields'     => 'gridProperties.columnCount',
            ) );
        }

        if ( $requests ) {
            $result = google::request( 'POST', rawurlencode( $id ) . ':batchUpdate', array( 'requests' => $requests ) );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            if ( ! $found ) {
                $found = array( 'sheetId' => isset( $result['replies'][0]['addSheet']['properties']['sheetId'] ) ? (int) $result['replies'][0]['addSheet']['properties']['sheetId'] : 0 );
            }
        }

        return array(
            'id'    => $id,
            'tab'   => $tab,
            'gid'   => (int) $found['sheetId'],
            'title' => isset( $data['properties']['title'] ) ? sanitize_text_field( $data['properties']['title'] ) : '',
        );
    }

    public function save_settings() {
        $this->guard();
        check_admin_referer('alss_settings');

        $optional = array_keys( columns::optional() );
        $columns  = isset( $_POST['columns'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['columns'] ) ) : array();
        $columns  = array_values( array_intersect( $optional, $columns ) );
        $schedule = isset( $_POST['schedule'] ) ? sanitize_key( wp_unslash( $_POST['schedule'] ) ) : '';
        $schedule = in_array( $schedule, settings::SCHEDULES, true ) ? $schedule : '';
        $changed  = $columns !== array_values( array_intersect( $optional, (array) settings::get('columns') ) );

        settings::update( array(
            'columns'   => $columns,
            'auto_push' => ! empty( $_POST['auto_push'] ),
            'schedule'  => $schedule,
        ) );
        sync::reschedule();

        if ( $changed && settings::is_connected() ) {
            sync::start_export('admin');
            $this->back( 'success', __( 'Settings saved. The columns changed, so the sheet is being rebuilt now.', 'softicon-sheet-sync' ), 'settings' );
        }
        $this->back( 'success', __( 'Settings saved.', 'softicon-sheet-sync' ), 'settings' );
    }

    public function save_wizard() {
        $this->guard();
        check_admin_referer('alss_wizard');

        $go = isset( $_POST['go'] ) ? sanitize_key( wp_unslash( $_POST['go'] ) ) : '';
        if ( 'script' === $go ) {
            update_option( 'alss_wizard', 4, false );
        } elseif ( 'finish' === $go ) {
            update_option( 'alss_setup_done', 1, false );
            delete_option('alss_wizard');
            sync::reschedule();
            $this->back( 'success', __( 'Setup complete. Your store and sheet are now in sync.', 'softicon-sheet-sync' ), 'dashboard' );
        }
        $this->back();
    }

    public function save_action() {
        $this->guard();
        check_admin_referer('alss_action');

        $do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
        $tab = get_option('alss_setup_done') ? 'dashboard' : '';

        switch ( $do ) {
            case 'export':
                $result = sync::start_export('admin');
                is_wp_error( $result )
                    ? $this->back( 'error', $result->get_error_message(), $tab )
                    : $this->back( 'success', __( 'Sending products to Google Sheets in the background.', 'softicon-sheet-sync' ), $tab );
                break;

            case 'pull':
                $result = sync::start_pull();
                is_wp_error( $result )
                    ? $this->back( 'error', $result->get_error_message(), $tab )
                    : $this->back( 'success', __( 'Checking the sheet for changes in the background. Results appear in the log.', 'softicon-sheet-sync' ), $tab );
                break;

            case 'regenerate':
                settings::regenerate_secret();
                $this->back( 'success', __( 'New key made. Copy the code again and paste it into Apps Script.', 'softicon-sheet-sync' ), 'script' );
                break;

            case 'clear_log':
                log::clear();
                $this->back( 'success', __( 'Log cleared.', 'softicon-sheet-sync' ), 'log' );
                break;

            case 'disconnect':
                sync::unschedule_all();
                foreach ( array( 'alss_credentials', 'alss_sheet', 'alss_secret', 'alss_setup_done', 'alss_wizard', 'alss_export', 'alss_queue', 'alss_script_seen', 'alss_script_version', 'alss_width' ) as $option ) {
                    delete_option( $option );
                }
                delete_transient('alss_token');
                log::add( __( 'Google Sheets disconnected.', 'softicon-sheet-sync' ), 'admin' );
                $this->back( 'success', __( 'Disconnected. Your sheet and products were not changed.', 'softicon-sheet-sync' ) );
                break;
        }
        $this->back();
    }

    private function guard() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'softicon-sheet-sync' ), 403 );
        }
    }

    private function back( $type = '', $message = '', $tab = '' ) {
        if ( $message ) {
            set_transient( 'alss_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), MINUTE_IN_SECONDS );
        }
        wp_safe_redirect( self::url( $tab && get_option('alss_setup_done') ? array( 'tab' => $tab ) : array() ) );
        exit;
    }

    private function notice() {
        $key    = 'alss_notice_' . get_current_user_id();
        $notice = get_transient( $key );
        if ( ! $notice ) {
            return;
        }
        delete_transient( $key );
        printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'error' === $notice['type'] ? 'error' : 'success', wp_kses_post( make_clickable( esc_html( $notice['message'] ) ) ) );
    }
}
