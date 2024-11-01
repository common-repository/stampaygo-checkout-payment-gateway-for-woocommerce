<?php
/**
 * Settings for stampayGO Gateway.
 *
 * @package WooCommerce/Classes/Payment
 */

return array(
	'enabled'        => array(
		'title'   => __( 'Enable/Disable', 'woocommerce' ),
		'type'    => 'checkbox',
		'label'   => __( 'Enable stampayGO Payment', 'stampaygo' ),
		'default' => 'no',
	),
	'title'          => array(
		'title'       => __( 'Title', 'woocommerce' ),
		'type'        => 'text',
		'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
		'default'     => __( 'stampayGO', 'stampaygo' ),
		'desc_tip'    => true,
	),
	'description'    => array(
		'title'       => __( 'Description', 'woocommerce' ),
		'type'        => 'text',
		'desc_tip'    => true,
		'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
		'default'     => __( "Pay with stampayGO and use credit card, PayPal, Alipay, Apple Pay and Google Pay as well as Klarna.", 'stampaygo' ),
	),
	'advanced'       => array(
		'title'       => __( 'Advanced options', 'woocommerce' ),
		'type'        => 'title',
		'description' => '',
	),
	'restaurant_id'  => array(
		'title'       => __( 'stampayGO identifier', 'stampaygo' ),
		/* translators: %s: URL */
		'description' => sprintf( __( 'You get this number from us after successfully registering your shop in our system. Pleas visit <a href="%s">stampaygo.com</a> for more information', 'stampaygo' ), 'https://stampaygo.com' ),
	),
	'testmode'       => array(
		'title'       => __( 'stampayGO demo', 'stampaygo' ),
		'type'        => 'checkbox',
		'label'       => __( 'Enable stampayGO demo mode', 'stampaygo' ),
		'default'     => 'no',
		'description' => __( 'stampayGO demo mode can be used to test payments.', 'stampaygo' ),
	),
	'debug'          => array(
		'title'       => __( 'Debug log', 'woocommerce' ),
		'type'        => 'checkbox',
		'label'       => __( 'Enable logging', 'woocommerce' ),
		'default'     => 'no',
		/* translators: %s: URL */
		'description' => sprintf( __( 'Log stampayGO events, such as IPN requests, inside %s Note: this may log personal information. We recommend using this for debugging purposes only and deleting the logs when finished.', 'stampaygo' ), '<code>' . WC_Log_Handler_File::get_log_file_path( 'stampaygo' ) . '</code>' ),
	),
	'invoice_prefix' => array(
		'title'       => __( 'Invoice prefix', 'woocommerce' ),
		'type'        => 'text',
		'description' => __( 'Please enter a prefix for your invoice numbers. If you use your stampayGO account for multiple stores ensure this prefix is unique as stampayGO will not allow orders with the same invoice number.', 'stampaygo' ),
		'default'     => 'WC-',
		'desc_tip'    => true,
	),
);
