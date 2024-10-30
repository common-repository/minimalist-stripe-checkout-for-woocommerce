const stripecheckout_data = window.wc.wcSettings.getSetting( 'stripecheckout_data', {} );
const stripecheckout_label = window.wp.htmlEntities.decodeEntities( stripecheckout_data.title )
	|| window.wp.i18n.__( 'Stripe Checkout', 'ccom-stripe-checkout' );
const stripecheckout_content = () => {
	return window.wp.htmlEntities.decodeEntities( stripecheckout_data.description || '' );
};
const StripeCheckout = {
	name: 'stripecheckout',
	label: stripecheckout_label,
	content: Object( window.wp.element.createElement )( stripecheckout_content, null ),
	edit: Object( window.wp.element.createElement )( stripecheckout_content, null ),
	canMakePayment: () => true,
	placeOrderButtonLabel: window.wp.i18n.__( 'Continue', 'ccom-stripe-checkout' ),
	ariaLabel: stripecheckout_label,
	supports: {
		features: stripecheckout_data.supports,
	},
};
window.wc.wcBlocksRegistry.registerPaymentMethod( StripeCheckout );