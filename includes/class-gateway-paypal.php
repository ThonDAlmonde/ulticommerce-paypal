<?php

defined( 'ABSPATH' ) || exit;

class Ulti_Gateway_PayPal extends Ulti_Payment_Gateway {

    public function __construct() {
        parent::__construct( 'paypal', 'PayPal' );
        $this->supports_redirect = true;
        $this->supports_webhook  = true;
        $settings = get_option( 'ulti_paypal_settings', [] );
        $this->description = __( 'Pay securely via PayPal.', 'ulticommerce-paypal' );
    }

    public function process_payment( $order_id ) {
        $total = get_post_meta( $order_id, '_order_total', true );
        $order_number = get_the_title( $order_id );

        update_post_meta( $order_id, '_order_payment_method', $this->id );
        update_post_meta( $order_id, '_order_status', 'pending_payment' );
        update_post_meta( $order_id, '_order_payment_title', $this->title );

        $url = $this->get_redirect_url( $order_id );
        if ( ! $url ) {
            return [ 'result' => 'error', 'message' => __( 'PayPal is not configured.', 'ulticommerce-paypal' ) ];
        }

        return [
            'result'   => 'success',
            'redirect' => $url,
        ];
    }

    public function get_redirect_url( $order_id ) {
        $settings = get_option( 'ulti_paypal_settings', [] );
        $client_id = $settings['client_id'] ?? '';
        $secret    = $settings['secret'] ?? '';
        $sandbox   = ! empty( $settings['sandbox'] );
        if ( ! $client_id || ! $secret ) return '';

        $api_base = $sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $token    = $this->get_access_token( $api_base, $client_id, $secret );
        if ( ! $token ) return '';

        $total        = get_post_meta( $order_id, '_order_total', true ) ?: '0';
        $currency     = get_option( 'ulti_default_currency', 'USD' );
        $order_number = get_the_title( $order_id );
        $return_url   = add_query_arg( 'order_id', $order_id, rest_url( 'wpc/v1/payment/paypal-return' ) );
        $cancel_url   = add_query_arg( 'order_id', $order_id, rest_url( 'wpc/v1/payment/paypal-cancel' ) );

        $response = wp_remote_post( $api_base . '/v2/checkout/orders', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body' => json_encode( [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => (string) $order_id,
                    'description'  => sprintf(
                        // translators: %s: order number.
                        __( 'Order #%s', 'ulticommerce-paypal' ),
                        $order_number
                    ),
                    'amount' => [
                        'currency_code' => $currency,
                        'value'         => number_format( (float) $total, 2, '.', '' ),
                    ],
                ]],
                'payment_source' => [
                    'paypal' => [
                        'experience_context' => [
                            'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
                            'landing_page'              => 'LOGIN',
                            'user_action'               => 'PAY_NOW',
                            'return_url'                => $return_url,
                            'cancel_url'                => $cancel_url,
                        ],
                    ],
                ],
            ] ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) return '';
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['id'] ) ) return '';

        update_post_meta( $order_id, '_paypal_order_id', $body['id'] );

        foreach ( $body['links'] as $link ) {
            if ( $link['rel'] === 'payer-action' ) {
                return $link['href'];
            }
        }
        return '';
    }

    public function handle_webhook( $payload = null ) {
        if ( $payload === null ) {
            $payload = file_get_contents( 'php://input' );
        }
        $input = json_decode( $payload, true );
        if ( empty( $input['event_type'] ) ) return false;

        $settings = get_option( 'ulti_paypal_settings', [] );
        $sandbox  = ! empty( $settings['sandbox'] );

        if ( ! empty( $settings['webhook_id'] ) ) {
            $verified = $this->verify_webhook_signature( $input, $sandbox );
            if ( ! $verified ) return false;
        }

        if ( $input['event_type'] === 'CHECKOUT.ORDER.APPROVED' ) {
            $paypal_order_id = $input['resource']['id'] ?? '';
            if ( ! $paypal_order_id ) return false;

            $orders = get_posts( [
                'post_type'      => 'order',
                'posts_per_page' => 1,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                'meta_query'     => [
                    [ 'key' => '_paypal_order_id', 'value' => $paypal_order_id ],
                ],
            ] );
            if ( empty( $orders ) ) return false;
            $order_id = $orders[0]->ID;

            $this->capture_payment( $paypal_order_id, $order_id, $sandbox );
            return true;
        }

        if ( $input['event_type'] === 'PAYMENT.CAPTURE.COMPLETED' ) {
            $paypal_order_id = $input['resource']['supplementary_data']['related_ids']['order_id'] ?? '';
            $transaction_id  = $input['resource']['id'] ?? '';
            if ( ! $paypal_order_id ) return false;

            $orders = get_posts( [
                'post_type'      => 'order',
                'posts_per_page' => 1,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                'meta_query'     => [
                    [ 'key' => '_paypal_order_id', 'value' => $paypal_order_id ],
                ],
            ] );
            if ( empty( $orders ) ) return false;
            $order_id = $orders[0]->ID;
            $this->mark_as_paid( $order_id, $transaction_id );
            return true;
        }

        return false;
    }

    public function get_access_token( $api_base, $client_id, $secret ) {
        $response = wp_remote_post( $api_base . '/v1/oauth2/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $secret ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => 'grant_type=client_credentials',
            'timeout' => 30,
        ] );
        if ( is_wp_error( $response ) ) return null;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['access_token'] ?? null;
    }

    public function capture_payment( $paypal_order_id, $order_id, $sandbox ) {
        $debug_file = '/tmp/ulti-paypal-debug.log';
        $settings = get_option( 'ulti_paypal_settings', [] );
        $client_id = $settings['client_id'] ?? '';
        $secret    = $settings['secret'] ?? '';
        if ( ! $client_id || ! $secret ) {
            file_put_contents( $debug_file, gmdate('H:i:s') . " capture: no client_id or secret\n", FILE_APPEND );
            return;
        }

        $api_base = $sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $token    = $this->get_access_token( $api_base, $client_id, $secret );
        if ( ! $token ) {
            file_put_contents( $debug_file, gmdate('H:i:s') . " capture: failed to get access token\n", FILE_APPEND );
            return;
        }

        $response = wp_remote_post( $api_base . '/v2/checkout/orders/' . $paypal_order_id . '/capture', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body'    => '{}',
            'timeout' => 30,
        ] );
        if ( is_wp_error( $response ) ) {
            file_put_contents( $debug_file, gmdate('H:i:s') . " capture: request failed: " . $response->get_error_message() . "\n", FILE_APPEND );
            return;
        }
        $http_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        file_put_contents( $debug_file, gmdate('H:i:s') . " capture: response HTTP $http_code: " . wp_remote_retrieve_body( $response ) . "\n", FILE_APPEND );
        if ( ! empty( $body['purchase_units'][0]['payments']['captures'][0]['id'] ) ) {
            $transaction_id = $body['purchase_units'][0]['payments']['captures'][0]['id'];
            file_put_contents( $debug_file, gmdate('H:i:s') . " capture: success, txn=$transaction_id, calling mark_as_paid\n", FILE_APPEND );
            $this->mark_as_paid( $order_id, $transaction_id );
        } elseif ( ! empty( $body['name'] ) ) {
            file_put_contents( $debug_file, gmdate('H:i:s') . " capture: failed - " . $body['name'] . ": " . ( $body['message'] ?? '' ) . "\n", FILE_APPEND );
        }
    }

    private function verify_webhook_signature( $input, $sandbox ) {
        $settings = get_option( 'ulti_paypal_settings', [] );
        $webhook_id = $settings['webhook_id'] ?? '';
        if ( ! $webhook_id ) return true;

        $api_base = $sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $client_id = $settings['client_id'] ?? '';
        $secret    = $settings['secret'] ?? '';
        $token     = $this->get_access_token( $api_base, $client_id, $secret );
        if ( ! $token ) return false;

        $headers = array_change_key_case( getallheaders(), CASE_UPPER );
        $response = wp_remote_post( $api_base . '/v1/notifications/verify-webhook-signature', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body' => json_encode( [
                'auth_algo'         => $headers['PAYPAL-AUTH-ALGO'] ?? '',
                'cert_url'          => $headers['PAYPAL-CERT-URL'] ?? '',
                'transmission_id'   => $headers['PAYPAL-TRANSMISSION-ID'] ?? '',
                'transmission_sig'  => $headers['PAYPAL-TRANSMISSION-SIG'] ?? '',
                'transmission_time' => $headers['PAYPAL-TRANSMISSION-TIME'] ?? '',
                'webhook_id'        => $webhook_id,
                'webhook_event'     => $input,
            ] ),
            'timeout' => 30,
        ] );
        if ( is_wp_error( $response ) ) return false;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return ( $body['verification_status'] ?? '' ) === 'SUCCESS';
    }
}

class UltiCommerce_PayPal_Settings {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=order',
            __( 'PayPal Settings', 'ulticommerce-paypal' ),
            __( 'PayPal', 'ulticommerce-paypal' ),
            'manage_options',
            'ulti-paypal-settings',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'ulti_paypal_settings_group', 'ulti_paypal_settings', [
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
            'default'           => [
                'client_id'  => '',
                'secret'     => '',
                'webhook_id' => '',
                'sandbox'    => 1,
            ],
        ] );
    }

    public function sanitize_settings( $value ) {
        if ( ! is_array( $value ) ) return [];
        return [
            'client_id'  => isset( $value['client_id'] ) ? sanitize_text_field( $value['client_id'] ) : '',
            'secret'     => isset( $value['secret'] ) ? sanitize_text_field( $value['secret'] ) : '',
            'webhook_id' => isset( $value['webhook_id'] ) ? sanitize_text_field( $value['webhook_id'] ) : '',
            'sandbox'    => ! empty( $value['sandbox'] ) ? 1 : 0,
        ];
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $settings = get_option( 'ulti_paypal_settings', [] );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'ulti_paypal_settings_group' ); ?>

                <p><?php esc_html_e( 'Configure PayPal REST API credentials. Create a PayPal app at https://developer.paypal.com/dashboard/applications to get your Client ID and Secret.', 'ulticommerce-paypal' ); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="paypal_sandbox"><?php esc_html_e( 'Sandbox Mode', 'ulticommerce-paypal' ); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ulti_paypal_settings[sandbox]" id="paypal_sandbox" value="1" <?php checked( ! empty( $settings['sandbox'] ) ); ?>>
                                <?php esc_html_e( 'Enable PayPal Sandbox (test mode)', 'ulticommerce-paypal' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="paypal_client_id"><?php esc_html_e( 'Client ID', 'ulticommerce-paypal' ); ?></label></th>
                        <td><input type="text" name="ulti_paypal_settings[client_id]" id="paypal_client_id" value="<?php echo esc_attr( $settings['client_id'] ?? '' ); ?>" class="regular-text" style="width:400px;"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="paypal_secret"><?php esc_html_e( 'Secret', 'ulticommerce-paypal' ); ?></label></th>
                        <td><input type="password" name="ulti_paypal_settings[secret]" id="paypal_secret" value="<?php echo esc_attr( $settings['secret'] ?? '' ); ?>" class="regular-text" style="width:400px;"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="paypal_webhook_id"><?php esc_html_e( 'Webhook ID', 'ulticommerce-paypal' ); ?></label></th>
                        <td>
                            <input type="text" name="ulti_paypal_settings[webhook_id]" id="paypal_webhook_id" value="<?php echo esc_attr( $settings['webhook_id'] ?? '' ); ?>" class="regular-text" style="width:400px;">
                            <p class="description"><?php esc_html_e( 'Optional. Set up a webhook in your PayPal app pointing to:', 'ulticommerce-paypal' ); ?> <code><?php echo esc_url( home_url( '/wp-json/wpc/v1/payment/webhook' ) ); ?></code></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
