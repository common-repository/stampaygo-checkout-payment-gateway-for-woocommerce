<?php

/**
 * Plugin Name: stampayGO Checkout Payment Gateway for woocommerce
 * Plugin URI: https://stampaygo.com
 * Description: Simply accept payments with stampayGO in the WooCommerce Shop via credit card, PayPal, Alipay, Apple Pay and Google Pay as well as Klarna.
 * Version: 1.3.1
 * Requires at least: 5.0
 * Author: Stampay GmbH
 * Author URI: https://stampay.com
 * License: GPL v2 or later
 * Text Domain: stampaygo
 */

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
function add_stampaygo_gateway_class( $gateways ) {
	$gateways[] = 'WC_Gateway_StampayGo'; // your class name is here

	return $gateways;
}

add_filter( 'woocommerce_payment_gateways', 'add_stampaygo_gateway_class' );

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action( 'plugins_loaded', 'init_stampaygo_gateway_class' );
function init_stampaygo_gateway_class() {

	/**
	 * Class WC_Gateway_StampayGo
	 */
	class WC_Gateway_StampayGo extends WC_Payment_Gateway {

		/**
		 * Whether or not logging is enabled
		 *
		 * @var bool
		 */
		public static $log_enabled = false;

		/**
		 * Logger instance
		 *
		 * @var WC_Logger
		 */
		public static $log = false;

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
			$this->id                 = 'stampaygo'; // Unique ID for your gateway, e.g., ‘your_gateway’
			$this->icon               = ''; // If you want to show an image next to the gateway’s name on the frontend, enter a URL to an image.
			$this->has_fields         = false; // Bool. Can be set to true if you want payment fields to show on the checkout (if doing a direct integration)
			$this->method_title       = 'stampayGO';
			$this->method_description = __('stampayGO redirects customers to stampayGO, allowing them to pay with credit card, PayPal, Alipay, Apple Pay and Google Pay as well as Klarna', 'stampaygo'); // will be displayed on the options page
			$this->icon = apply_filters( 'woocommerce_gateway_icon', plugin_dir_url(__FILE__) .'/assets/images/stampaygo.png' );
			$this->supports           = array(
				'products',
			);

			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables.
			$this->title         = $this->get_option( 'title' );
			$this->description   = $this->get_option( 'description' );
			$this->enabled       = $this->get_option( 'enabled' );
			$this->testmode      = 'yes' === $this->get_option( 'testmode', 'no' );
			$this->debug         = 'yes' === $this->get_option( 'debug', 'no' );
			$this->token         = $this->get_option( 'token' );
			$this->restaurant_id = $this->get_option( 'restaurant_id' );
			self::$log_enabled   = $this->debug;

			if ( $this->testmode ) {
				$this->description .= ' ' . sprintf( __( 'SANDBOX ENABLED. You will test against demo environment. You will not be charged.', 'stampaygo' ) );
				$this->description = trim( $this->description );
			}

			// This action hook saves the settings
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
				$this,
				'process_admin_options'
			) );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
				$this,
				'process_admin_options'
			) );

			if ( ! $this->is_valid_for_use() ) {
				$this->enabled = 'no';
			} else {
				include_once dirname( __FILE__ ) . '/includes/class-wc-gateway-stampaygo-ipn-handler.php';
				new WC_Gateway_StampayGo_IPN_Handler( $this->testmode );
			}
		}

		/**
		 * Return whether or not this gateway still requires setup to function.
		 *
		 * When this gateway is toggled on via AJAX, if this returns true a
		 * redirect will occur to the settings page instead.
		 *
		 * @return bool
		 * @since 3.4.0
		 */
		public function needs_setup() {
			return empty( $this->restaurant_id );
		}

		/**
		 * Logging method.
		 *
		 * @param string $message Log message.
		 * @param string $level Optional. Default 'info'. Possible values:
		 *                      emergency|alert|critical|error|warning|notice|info|debug.
		 */
		public static function log( $message, $level = 'info' ) {
			if ( self::$log_enabled ) {
				if ( empty( self::$log ) ) {
					self::$log = wc_get_logger();
				}
				self::$log->log( $level, $message, array( 'source' => 'stampaygo' ) );
			}
		}

		/**
		 * Processes and saves options.
		 * If there is an error thrown, will continue to save and validate fields, but will leave the erroring field out.
		 *
		 * @return bool was anything saved?
		 */
		public function process_admin_options() {
			$saved = parent::process_admin_options();

			// Maybe clear logs.
			if ( 'yes' !== $this->get_option( 'debug', 'no' ) ) {
				if ( empty( self::$log ) ) {
					self::$log = wc_get_logger();
				}
				self::$log->clear( 'stampaygo' );
			}

			return $saved;
		}

		/**
		 * Check if this gateway is enabled and available in the user's country.
		 *
		 * @return bool
		 */
		public function is_valid_for_use() {
			return in_array(
				get_woocommerce_currency(),
				array( 'EUR' ),
				true
			);
		}

		/**
		 * Admin Panel Options.
		 * - Options for bits like 'title' and availability on a country-by-country basis.
		 *
		 * @since 1.0.0
		 */
		public function admin_options() {
			if ( $this->is_valid_for_use() ) {
				parent::admin_options();
			} else {
				?>
                <div class="inline error">
                    <p>
                        <strong><?php esc_html_e( 'Gateway disabled', 'woocommerce' ); ?></strong>: <?php esc_html_e( 'stampayGO does not support your store currency.', 'stampaygo' ); ?>
                    </p>
                </div>
				<?php
			}
		}

		/**
		 * Initialise Gateway Settings Form Fields.
		 */
		public function init_form_fields() {
			$this->form_fields = include 'includes/settings-stampaygo.php';
		}

		/**
		 * Process the payment and return the result.
		 *
		 * @param int $order_id Order id
		 *
		 * @return array
		 */
		public function process_payment( $order_id ) {
			include_once dirname( __FILE__ ) . '/includes/class-wc-gateway-stampaygo-request.php';

			// we need it to get any order detailes
			$order = wc_get_order( $order_id );

			// Mark as on-hold (we're awaiting the cheque)
//			$order->update_status( 'on-hold', __( 'Awaiting stampayGO payment', 'woocommerce' ) );

			$stampaygo_request = new WC_Gateway_StampayGo_Request( $this );

			return array(
				'result'   => 'success',
				'redirect' => $stampaygo_request->get_request_url( $order, $this->testmode ),
			);
		}
	}
}
