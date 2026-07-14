<?php
/**
 * Plugin Name: UltiCommerce PayPal
 * Plugin URI:  https://github.com/ThonDAlmonde/ulticommerce-paypal
 * Description: PayPal payment gateway for UltiCommerce. Requires UltiCommerce core plugin.
 * Version:     1.0.0
 * Author:      UltiCommerce
 * License: GPL v2 or later
 * Text Domain: ulticommerce-paypal
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'ULTI_PAYPAL_PATH', plugin_dir_path( __FILE__ ) );

add_action( 'plugins_loaded', 'ulti_paypal_check_dependencies' );
function ulti_paypal_check_dependencies() {
    if ( ! class_exists( 'Ulti_Payment_Gateways' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__( 'UltiCommerce PayPal requires UltiCommerce core plugin to be installed and activated.', 'ulticommerce-paypal' )
                . '</p></div>';
        } );
        return;
    }
    require_once ULTI_PAYPAL_PATH . 'includes/class-gateway-paypal.php';
}

add_action( 'ulti_register_payment_gateways', function () {
    if ( class_exists( 'Ulti_Payment_Gateways' ) ) {
        Ulti_Payment_Gateways::register( 'paypal', new Ulti_Gateway_PayPal() );
    }
} );

add_action( 'rest_api_init', 'ulti_paypal_register_routes' );
function ulti_paypal_register_routes() {
    register_rest_route( 'wpc/v1', '/payment/paypal-return', [
        'methods'             => 'GET',
        'callback'            => 'ulti_paypal_return_handler',
        'permission_callback' => '__return_true',
    ] );
    register_rest_route( 'wpc/v1', '/payment/paypal-cancel', [
        'methods'             => 'GET',
        'callback'            => 'ulti_paypal_cancel_handler',
        'permission_callback' => '__return_true',
    ] );
}

function ulti_paypal_return_handler( $request ) {
    $debug_file = '/tmp/ulti-paypal-debug.log';
    $token = $request->get_param( 'token' );
    $order_id = $request->get_param( 'order_id' );
    file_put_contents( $debug_file, gmdate('H:i:s') . " handler called, token=$token, order_id=$order_id\n", FILE_APPEND );
    if ( ! $token || ! $order_id ) {
        file_put_contents( $debug_file, gmdate('H:i:s') . " missing token or order_id\n", FILE_APPEND );
        wp_safe_redirect( home_url( '/my-account/' ) );
        exit;
    }
    $paypal = Ulti_Payment_Gateways::get( 'paypal' );
    if ( $paypal ) {
        $settings = get_option( 'ulti_paypal_settings', [] );
        $sandbox  = ! empty( $settings['sandbox'] );
        file_put_contents( $debug_file, gmdate('H:i:s') . " calling capture_payment\n", FILE_APPEND );
        $paypal->capture_payment( $token, $order_id, $sandbox );
    } else {
        file_put_contents( $debug_file, gmdate('H:i:s') . " paypal gateway not found\n", FILE_APPEND );
    }
    $redirect = add_query_arg( 'order', get_the_title( $order_id ), get_permalink( get_page_by_template( 'page-confirmation.php' ) ) ?: home_url() );
    file_put_contents( $debug_file, gmdate('H:i:s') . " redirecting to $redirect\n", FILE_APPEND );
    wp_safe_redirect( $redirect );
    exit;
}

function ulti_paypal_cancel_handler( $request ) {
    $order_id = $request->get_param( 'order_id' );
    if ( $order_id ) {
        wp_safe_redirect( home_url( '/?view-order=' . $order_id ) );
        exit;
    }
    wp_safe_redirect( home_url( '/my-account/' ) );
    exit;
}

add_action( 'plugins_loaded', function () {
    if ( class_exists( 'Ulti_Payment_Gateways' ) ) {
        new UltiCommerce_PayPal_Settings();
    }
} );
