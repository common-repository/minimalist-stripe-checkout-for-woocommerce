<?php
/**
 * Plugin Name: Minimalist Stripe Checkout for WooCommerce
 * Plugin URI: https://codedcommerce.com/shop/
 * Description: High efficiency WooCommerce add-on for Stripe Checkout payment method.
 * Version: 1.7
 * Author: Coded Commerce, LLC
 * Author URI: https://codedcommerce.com
 * WC requires at least: 6.6
 * WC tested up to: 9.3.3
 * License: GPLv2 or later
 */

// Bail If Accessed Directly
if( ! defined( 'ABSPATH' ) ) { exit; }

// Declare Support For HPOS
add_action( 'before_woocommerce_init', function() {
	if( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables', __FILE__, true
		);
	}
} );

// Declare Support For Cart+Checkout Blocks
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

// Plugins Page Link To Settings
add_filter(
	'plugin_action_links_minimalist-stripe-checkout-for-woocommerce/ccom-stripe-checkout.php',
	function( $links ) {

    $settings = [
		'settings' => sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=wc-settings&tab=checkout&section=stripecheckout' ),
			__( 'Settings', 'woocommerce' )
		),
	];

    return array_merge( $settings, $links );

} );

// Require Gateway PHP Class
require_once( 'class.wc_gateway_stripecheckout.php' );

// Add Gateway To WooCommerce
add_filter( 'woocommerce_payment_gateways', function( $methods ) {

	$methods[] = 'WC_Gateway_StripeCheckout'; 
	return $methods;

} );

// Blocks Support
add_action( 'woocommerce_blocks_loaded', function() {

	if( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		require_once( 'class.blocks-checkout.php' );
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
				$payment_method_registry->register( new WC_StripeCheckout_Blocks );
		} );
	}

} );

// Handle Successful Payments
add_action( 'woocommerce_thankyou_stripecheckout', function( $order_id ) {

	// Get Order Object
	$order = wc_get_order( $order_id );

	// Validate Order Key
	if( empty( $_GET['key'] ) || $order->get_order_key() != $_GET['key'] ) {
		return;
	}

	// Update Order Status
	$order->payment_complete();

	// Add Order Note
	$order->add_order_note(
		__(
			'Stripe Checkout complete.',
			'ccom-stripe-checkout'
		)
	);

	// Empty The Cart
	if( isset( WC()->cart ) ) {
		WC()->cart->empty_cart();
	}

} );

// REST API Hook
add_action( 'rest_api_init', function() {

	register_rest_route(
		'ccom_stripe/v1',
		'/billing_portal',
		[
			'callback' => 'ccom_stripe_billing_portal',
			'methods' => [ 'GET' ],
			'permission_callback' => function() {
				return is_user_logged_in();
			},
		],
	);

} );

// Billing Portal Request
function ccom_stripe_billing_portal( WP_REST_Request $request ) {

	// Setup Response Object
	$response = new WP_REST_Response();

	// Get Stripe Customer ID
	$stripe_customer_id = get_user_meta(
		get_current_user_id(), '_stripe_customer_id', true
	);

	// Handle Errors
	if( ! $stripe_customer_id ) {
		wp_die(
			__(
				'Error: Stripe Customer ID for this user is missing.',
				'ccom-stripe-checkout'
			)
		);
	}

	// Get Access
	$stripe_settings = get_option(
		'woocommerce_stripecheckout_settings'
	);
	$testmode = isset( $stripe_settings['testmode'] )
		&& $stripe_settings['testmode'] == 'yes';
	$sec_key = $testmode
		? $stripe_settings['sec_key_test']
		: $stripe_settings['sec_key_live'];

	// Get URL From Stripe
	$url = 'https://api.stripe.com/v1/billing_portal/sessions';
	$args = [
		'headers' => [
			'Authorization' => 'Basic '
				. base64_encode( $sec_key . ':' )
		],
		'body' => [
			'customer' => $stripe_customer_id,
		],
	];
	$result = wp_remote_post( $url, $args );
	$result = wp_remote_retrieve_body( $result );
	$result = json_decode( $result );

	// Success
	if( isset( $result->url ) ) {
		$response->set_status( 200 );
		$response->set_data( $result->url );
		exit( wp_redirect( $result->url ) );
	}

	// Error
	wp_die(
		__( 'Stripe Billing Portal error:', 'ccom-stripe-checkout' )
		. ' ' . $result->message
	);

}