<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_StripeCheckout_Blocks extends AbstractPaymentMethodType {

	private $gateway;
	protected $name = 'stripecheckout';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_stripecheckout_settings', [] );
		$this->gateway = new WC_Gateway_StripeCheckout();
	}

	public function is_active() {
		return $this->get_setting( 'enabled' ) === 'yes';
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'wc-stripecheckout-blocks-integration',
			plugins_url( 'checkout.js', __FILE__ ),
			[
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			],
			false,
			true
		);
		if( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wc-stripecheckout-blocks-integration');
		}
		return [ 'wc-stripecheckout-blocks-integration' ];
	}

	public function get_payment_method_data() {
		return [
			'title' => $this->gateway->title,
			'description' => $this->gateway->description,
		];
	}

}