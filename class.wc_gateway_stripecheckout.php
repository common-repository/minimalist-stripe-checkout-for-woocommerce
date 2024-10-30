<?php

// Bail If Accessed Directly
if( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'woocommerce_init', function() {

	// Bail If Preexists
	if( class_exists( 'WC_Gateway_StripeCheckout' ) ) {
		return;
	}

	// Payment Gateway Class
	class WC_Gateway_StripeCheckout extends WC_Payment_Gateway {

		// Declare Uninherited Properties For PHP 8.2 Compatibility
		public $endpoint = 'https://api.stripe.com/v1';
		public $payment_method_types = [
			'acss_debit',
			'affirm',
			'afterpay_clearpay',
			'alipay',
			'au_becs_debit',
			'bacs_debit',
			'bancontact',
			'blik',
			'boleto',
			'card',
			'customer_balance',
			'eps',
			'fpx',
			'giropay',
			'grabpay',
			'ideal',
			'klarna',
			'konbini',
			'oxxo',
			'p24',
			'paynow',
			'pix',
			'promptpay',
			'sepa_debit',
			'sofort',
			'us_bank_account',
			'wechat_pay',
		];

		// Constructor
		public function __construct() {
			$this->has_fields = false;
			$this->id = 'stripecheckout';
			$this->method_title = __( 'Stripe Checkout', 'ccom-stripe-checkout' );
			$this->method_description = __(
				'Implements a Stripe Checkout payment session for WooCommerce.',
				'ccom-stripe-checkout'
			);
			$this->init_form_fields();
			$this->init_settings();
			$this->title = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			add_action(
				'woocommerce_update_options_payment_gateways_' . $this->id,
				[ $this, 'process_admin_options' ]
			);
		}

		// Settings Fields
		public function init_form_fields() {

			$this->form_fields = array(
				'enabled' => array(
					'title' => __( 'Enable/Disable', 'woocommerce' ),
					'type' => 'checkbox',
					'label' => 'Enable Stripe Checkout',
					'default' => 'yes',
				),
				'testmode' => array(
					'title' => __( 'Test mode', 'woocommerce' ),
					'type' => 'checkbox',
					'label' => 'Enable test mode',
					'default' => 'yes',
				),
				'title' => array(
					'title' => __( 'Title', 'woocommerce' ),
					'type' => 'text',
				),
				'description' => array(
					'title' => __( 'Description', 'woocommerce' ),
					'type' => 'textarea',
				),
				'sec_key_live' => array(
					'title' => __( 'Secret key (live)', 'ccom-stripe-checkout' ),
					'type' => 'text',
				),
				'sec_key_test' => array(
					'title' => __( 'Secret key (test)', 'ccom-stripe-checkout' ),
					'type' => 'text',
				),
				'payment_method_types' => array(
					'title' => __( 'Payment method', 'ccom-stripe-checkout' ),
					'type' => 'select',
					'default' => 'card',
					'options' => array_merge(
						[ '' => '(dashboard)' ],
						array_combine( $this->payment_method_types, $this->payment_method_types )
					),
				),
				'payment_method_types_recurring' => array(
					'title' => __( 'Subscription pay method', 'ccom-stripe-checkout' ),
					'type' => 'select',
					'default' => 'card',
					'options' => array_merge(
						[ '' => '(dashboard)' ],
						array_combine( $this->payment_method_types, $this->payment_method_types )
					),
				),
			);
		}

		// WooCommerce Order Submitted
		public function process_payment( $order_id ) {

			// Get Customer And Order
			$customer = WC()->session->get( 'customer' );
			$order = wc_get_order( $order_id );

			// Set Payent Pending Status
			$order->update_status(
				'pending', __( 'Awaiting payment', 'woocommerce' )
			);

			// Handle Test Mode
			$sec_key = $this->settings['testmode'] == 'yes'
				? $this->settings['sec_key_test']
				: $this->settings['sec_key_live'];

			// Build Request
			$args = [
				'headers' => [
					'Authorization' => sprintf(
						'Basic %s',
						base64_encode( $sec_key . ':' )
					),
				],
				'body' => [
					'cancel_url' => $order->get_checkout_payment_url(),
					'discounts' => [],
					'line_items' => [],
					'mode' => 'payment',
					'success_url' => $this->get_return_url( $order )
						. '&stripe_session_id={CHECKOUT_SESSION_ID}',
				],
			];

			// Optional Payment Method Type
			if( $this->settings['payment_method_types'] ) {
				$args['body']['payment_method_types']
					= (array) $this->settings['payment_method_types'];
			}

			// Loop Line Items
			$i = -1;
			$discounts = [];
			foreach( $order->get_items( [ 'line_item', 'fee', 'shipping', 'coupon' ] ) as $order_item ) {

				$item_total = 0;
				$item_quantity = 1;
				$interval = false;

				switch( $order_item->get_type() ) {

					case 'line_item':
						$item_total = (float) $order_item->get_subtotal()
							/ (float) $order_item->get_quantity();
						$item_quantity = intval( $order_item->get_quantity() );
						$product = $order_item->get_product();
						if( stristr( $product->get_sku(), 'monthly' ) !== false ) {
							$interval = 'month';
						}
						if( stristr( $product->get_sku(), 'annually' ) !== false ) {
							$interval = 'year';
						}
						break;

					case 'fee':
						if( (float) $order_item->get_total() > 0 ) {
							$item_total = (float) $order_item->get_total();
						} else {
							$discounts[] = [
								'name' => $order_item->get_name(),
								'amount' => (float) $order_item->get_total() * -1
							];	
						}
						break;

					case 'shipping':
						$item_total = (float) $order_item->get_total();
						break;

					case 'coupon':
						$discounts[] = [
							'name' => strtoupper( $order_item->get_name() ),
							'amount' => (float) $order_item->get_discount()
						];
						break;

				}

				// Line Items With Amounts
				if( $item_total && $item_quantity ) {
					$i ++;
					$args['body']['line_items'][$i] = [
						'price_data' => [
							'currency' => get_woocommerce_currency(),
							'product_data' => [
								'name' => $order_item->get_name(),
							],
							'unit_amount' => intval( $item_total * 100 ),
						],
						'quantity' => $item_quantity,
					];
				}

				// Subscription?
				if( $interval ) {
					$args['body']['payment_method_types']
						= (array) $this->settings['payment_method_types_recurring'];
					$args['body']['mode'] = 'subscription';
					$args['body']['line_items'][$i]['price_data']['recurring'] = [
						'interval' => $interval,
						'interval_count' => 1,
					];
				}

			} // End Loop Line Items

			// Maybe Attach Customer
			$stripe_customer_id = get_user_meta(
				get_current_user_id(), '_stripe_customer_id', true
			);
			if( $stripe_customer_id ) {
				$args['body']['customer'] = $stripe_customer_id;
			}

			// Maybe Make And Apply Combined Coupon - Only One Allowed By Stripe
			if( $discounts ) {
				$discount_names = [];
				$discount_percentages = [];
				$discount_amounts = [];
				foreach( $discounts as $discount ) {
					$discount_names[] = $discount['name'];
					$discount_percentages[] = $discount['percentage'];
					$discount_amounts[] = $discount['amount'];
				}
				$discount_percentage = (float) array_sum( $discount_percentages );
				$discount_amount = (float) array_sum( $discount_amounts );
				if( $discount_percentage ) {
					$args_coupon = [
						'headers' => [
							'Authorization' => sprintf(
								'Basic %s', base64_encode( $sec_key . ':' )
							),
						],
						'body' => [
							'name' => implode( ' + ', $discount_names ),
							'percent_off' => $discount_percentage,
						],
					];
				}
				else if( $discount_amount ) {
					$args_coupon = [
						'headers' => [
							'Authorization' => sprintf(
								'Basic %s', base64_encode( $sec_key . ':' )
							),
						],
						'body' => [
							'name' => implode( ' + ', $discount_names ),
							'amount_off' => intval( $discount_amount * 100 ),
							'currency' => get_woocommerce_currency(),
						],
					];
				}
				if( $args_coupon ) {
					$result = wp_remote_post( $this->endpoint . '/coupons', $args_coupon );
					$result = wp_remote_retrieve_body( $result );
					$result = json_decode( $result );
					if( empty( $result->id ) ) {
						$message = sprintf(
							__(
								'Problem with Stripe Checkout coupon creation: %s',
								'ccom-stripe-checkout'
							),
							$result->error->message
						);
						$order->update_status( 'failed', $message );
						throw new Exception( $message );
						return;	
					}
					$args['body']['discounts'][]['coupon'] = $result->id;
				}
			}

			// Send Checkout Session Request
			$url = $this->endpoint . '/checkout/sessions';
			$result = wp_remote_post( $url, $args );
			$result = wp_remote_retrieve_body( $result );
			$result = json_decode( $result );

			// Handle Checkout Session Error
			if( empty( $result->url ) ) {
				$message = sprintf(
					__(
						'Problem with Stripe Checkout: %s',
						'ccom-stripe-checkout'
					),
					$result->error->message
				);
				$order->update_status( 'failed', $message );
				throw new Exception( $message );
				return;
			}

			// Handle Checkout Redirect
			$order->add_order_note(
				__(
					'Redirected to Stripe Checkout.',
					'ccom-stripe-checkout'
				)
			);
			return [
				'result' => 'success',
				'redirect' => $result->url
			];

		} // End Process Payment Function

	} // End Payment Gateway Class

} ); // End Woo Init Hook