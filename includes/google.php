<?php
namespace ALSS_Sheet_Sync;
if ( !defined('ABSPATH') ) { exit;}

// a small Google Sheets REST client, signs a service account JWT itself instead of shipping the Google SDK
class google {

    const API   = 'https://sheets.googleapis.com/v4/spreadsheets/';
    const TOKEN = 'https://oauth2.googleapis.com/token';
    const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    public static function validate_credentials( $json ) {
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            return new \WP_Error( 'alss_json', __( 'This is not a valid JSON file. Upload the key file you downloaded from Google Cloud.', 'softicon-sheet-sync' ) );
        }
        if ( empty( $data['type'] ) || 'service_account' !== $data['type'] || empty( $data['client_email'] ) || ! is_string( $data['private_key'] ?? null ) || 0 !== strpos( $data['private_key'], '-----BEGIN' ) ) {
            return new \WP_Error( 'alss_json', __( 'This JSON file is not a service account key. In Google Cloud open the service account, go to Keys, choose Add key, Create new key and pick JSON.', 'softicon-sheet-sync' ) );
        }
        if ( ! function_exists('openssl_sign') ) {
            return new \WP_Error( 'alss_openssl', __( 'The PHP OpenSSL extension is missing on this server. Ask your host to enable it.', 'softicon-sheet-sync' ) );
        }
        // a PEM key is base64 lines between BEGIN/END markers, this only trims the trailing newline
        $private_key = sanitize_textarea_field( $data['private_key'] );
        if ( ! openssl_pkey_get_private( $private_key ) ) {
            return new \WP_Error( 'alss_json', __( 'The private key in this file could not be read. Download a new JSON key and try again.', 'softicon-sheet-sync' ) );
        }

        return array(
            'client_email' => sanitize_email( $data['client_email'] ),
            'private_key'  => $private_key,
            'project_id'   => isset( $data['project_id'] ) ? sanitize_text_field( $data['project_id'] ) : '',
        );
    }

    public static function token( $credentials = null ) {
        // a key being tested in the setup wizard is never cached
        $saved = null === $credentials;
        if ( $saved ) {
            $cached = get_transient('alss_token');
            if ( $cached ) {
                return $cached;
            }
            $credentials = settings::credentials();
        }

        if ( ! $credentials ) {
            return new \WP_Error( 'alss_not_connected', __( 'Google is not connected yet.', 'softicon-sheet-sync' ) );
        }

        $now    = time() - 60;
        $header = self::base64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
        $claims = self::base64url( wp_json_encode( array(
            'iss'   => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN,
            'iat'   => $now,
            'exp'   => $now + HOUR_IN_SECONDS,
        ) ) );

        $signature = '';
        if ( ! openssl_sign( $header . '.' . $claims, $signature, $credentials['private_key'], 'SHA256' ) ) {
            return new \WP_Error( 'alss_sign', __( 'Could not sign the request with your private key.', 'softicon-sheet-sync' ) );
        }

        $response = wp_remote_post( self::TOKEN, array(
            'timeout' => 20,
            'body'    => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $header . '.' . $claims . '.' . self::base64url( $signature ),
            ),
        ) );
        if ( is_wp_error( $response ) ) {
            /* translators: %s: error message */
            return new \WP_Error( 'alss_http', sprintf( __( 'Could not reach Google: %s', 'softicon-sheet-sync' ), $response->get_error_message() ) );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['access_token'] ) ) {
            $error = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error'] ) ? $body['error'] : '' );
            if ( isset( $body['error'] ) && 'invalid_grant' === $body['error'] ) {
                return new \WP_Error( 'alss_token', __( 'Google rejected this key. It may have been deleted, or the server clock is wrong. Create a new JSON key and upload it again.', 'softicon-sheet-sync' ) );
            }
            /* translators: %s: error message from Google */
            return new \WP_Error( 'alss_token', sprintf( __( 'Google did not accept the key: %s', 'softicon-sheet-sync' ), $error ) );
        }

        if ( $saved ) {
            set_transient( 'alss_token', $body['access_token'], max( 60, (int) $body['expires_in'] - 300 ) );
        }
        return $body['access_token'];
    }

    public static function request( $method, $path, $body = null, $query = array(), $token = null ) {
        $token = $token ? $token : self::token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $url  = add_query_arg( array_map( 'rawurlencode', $query ), self::API . $path );
        $args = array(
            'method'  => $method,
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json; charset=utf-8',
            ),
        );
        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            /* translators: %s: error message */
            return new \WP_Error( 'alss_http', sprintf( __( 'Could not reach Google: %s', 'softicon-sheet-sync' ), $response->get_error_message() ) );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code >= 200 && $code < 300 ) {
            return is_array( $data ) ? $data : array();
        }

        if ( 401 === $code ) {
            delete_transient('alss_token');
        }
        return self::error( $code, is_array( $data ) && isset( $data['error'] ) ? $data['error'] : array() );
    }

    // turns Google's error into a message that says what to do next
    private static function error( $code, $error ) {
        $message = isset( $error['message'] ) ? $error['message'] : '';
        $status  = isset( $error['status'] ) ? $error['status'] : '';
        $reason  = '';
        $link    = '';
        if ( ! empty( $error['details'] ) && is_array( $error['details'] ) ) {
            foreach ( $error['details'] as $detail ) {
                if ( ! empty( $detail['reason'] ) ) {
                    $reason = $detail['reason'];
                }
                if ( ! empty( $detail['metadata']['activationUrl'] ) ) {
                    $link = $detail['metadata']['activationUrl'];
                }
            }
        }

        if ( 'SERVICE_DISABLED' === $reason || false !== strpos( $message, 'has not been used in project' ) ) {
            $link = $link ? $link : 'https://console.cloud.google.com/apis/library/sheets.googleapis.com';
            /* translators: %s: link to enable the API */
            return new \WP_Error( 'alss_api_disabled', sprintf( __( 'The Google Sheets API is turned off in your Google Cloud project. Turn it on here: %s and try again after a minute.', 'softicon-sheet-sync' ), $link ) );
        }
        if ( 403 === $code ) {
            /* translators: %s: service account email */
            return new \WP_Error( 'alss_no_access', sprintf( __( 'The service account cannot open this sheet. In Google Sheets click Share and add %s as an Editor.', 'softicon-sheet-sync' ), settings::client_email() ) );
        }
        if ( 404 === $code ) {
            return new \WP_Error( 'alss_not_found', __( 'The spreadsheet was not found. Check the sheet URL.', 'softicon-sheet-sync' ) );
        }
        if ( 429 === $code ) {
            return new \WP_Error( 'alss_rate_limit', __( 'Google is limiting requests right now. The sync will try again shortly.', 'softicon-sheet-sync' ) );
        }
        if ( false !== strpos( $message, 'Unable to parse range' ) ) {
            return new \WP_Error( 'alss_no_tab', __( 'The sheet tab was not found. Check the tab name in the settings.', 'softicon-sheet-sync' ) );
        }

        /* translators: 1: HTTP code, 2: error message from Google */
        return new \WP_Error( 'alss_google', sprintf( __( 'Google Sheets error %1$d: %2$s', 'softicon-sheet-sync' ), $code, $message ? $message : $status ) );
    }

    public static function spreadsheet( $id, $token = null ) {
        return self::request( 'GET', rawurlencode( $id ), null, array( 'fields' => 'properties.title,sheets.properties' ), $token );
    }

    public static function get_values( $range ) {
        $sheet = settings::sheet();
        $data  = self::request( 'GET', rawurlencode( $sheet['id'] ) . '/values/' . rawurlencode( $range ), null, array(
            'valueRenderOption' => 'UNFORMATTED_VALUE',
            'majorDimension'    => 'ROWS',
        ) );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        return isset( $data['values'] ) ? $data['values'] : array();
    }

    // RAW keeps text such as "=SUM()" in a product name from turning into a formula
    public static function update_values( $data ) {
        if ( ! $data ) {
            return array();
        }
        $sheet = settings::sheet();
        return self::request( 'POST', rawurlencode( $sheet['id'] ) . '/values:batchUpdate', array(
            'valueInputOption' => 'RAW',
            'data'             => $data,
        ) );
    }

    public static function append_values( $range, $rows ) {
        if ( ! $rows ) {
            return array();
        }
        $sheet = settings::sheet();
        return self::request( 'POST', rawurlencode( $sheet['id'] ) . '/values/' . rawurlencode( $range ) . ':append', array( 'values' => $rows ), array(
            'valueInputOption' => 'RAW',
            'insertDataOption' => 'INSERT_ROWS',
        ) );
    }

    public static function clear_values( $range ) {
        $sheet = settings::sheet();
        // Google wants an empty object here, an empty PHP array would be sent as []
        return self::request( 'POST', rawurlencode( $sheet['id'] ) . '/values/' . rawurlencode( $range ) . ':clear', new \stdClass() );
    }

    public static function batch_update( $requests ) {
        if ( ! $requests ) {
            return array();
        }
        $sheet = settings::sheet();
        return self::request( 'POST', rawurlencode( $sheet['id'] ) . ':batchUpdate', array( 'requests' => $requests ) );
    }

    // 'Products'!A2:F10, quotes inside the tab name are doubled
    public static function range( $a1 ) {
        $sheet = settings::sheet();
        return "'" . str_replace( "'", "''", $sheet['tab'] ) . "'!" . $a1;
    }

    public static function column( $number ) {
        $letters = '';
        while ( $number > 0 ) {
            $mod     = ( $number - 1 ) % 26;
            $letters = chr( 65 + $mod ) . $letters;
            $number  = (int) ( ( $number - $mod ) / 26 );
        }
        return $letters;
    }

    private static function base64url( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }
}
