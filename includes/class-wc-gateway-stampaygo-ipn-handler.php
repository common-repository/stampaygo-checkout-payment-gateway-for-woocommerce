<?php
/**
 * Handles responses from stampayGO IPN.
 */

require_once dirname( __FILE__ ) . '/class-wc-gateway-stampaygo-response.php';

/**
 * WC_Gateway_StampayGo_IPN_Handler class.
 */
class WC_Gateway_StampayGo_IPN_Handler extends WC_Gateway_StampayGo_Response {
	/**
	 * Constructor.
	 *
	 * @param bool $sandbox Use sandbox or not.
	 */
	public function __construct( $sandbox = false ) {
		add_action( 'woocommerce_api_wc_gateway_stampaygo', array( $this, 'check_response' ) );
		add_action( 'valid-stampaygo-standard-ipn-request', array( $this, 'valid_response' ) );

		$this->sandbox = $sandbox;
	}

	/**
	 * Check for stampayGO IPN Response.
	 */
	public function check_response() {
		WC_Gateway_StampayGo::log( 'Received IPN: ' . wc_print_r( $_POST, true ) );

		if ( ! empty( $_POST ) && $this->validate_ipn() ) { // WPCS: CSRF ok.
			$posted = wp_unslash( $_POST ); // WPCS: CSRF ok, input var ok.

			// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			do_action( 'valid-stampaygo-standard-ipn-request', $posted );
			exit;
		}

		wp_die( 'stampayGO IPN Request Failure', 'stampayGO IPN', array( 'response' => 500 ) );
	}

	/**
	 * There was a valid response.
	 *
	 * @param array $posted Post data after wp_unslash.
	 */
	public function valid_response( $posted ) {
		WC_Gateway_StampayGo::log( 'Got a valid response with custom data' . $posted['custom'] );

		$order = ! empty( $posted['custom'] ) ? $this->get_stampaygo_order( $posted['custom'] ) : false;

		if ( $order ) {

			// Lowercase returned variables.
			$posted['payment_status'] = strtolower( $posted['payment_status'] );

			WC_Gateway_StampayGo::log( 'Found order #' . $order->get_id() );
			WC_Gateway_StampayGo::log( 'Payment status: ' . $posted['payment_status'] );

			if ( method_exists( $this, 'payment_status_' . $posted['payment_status'] ) ) {
				call_user_func( array( $this, 'payment_status_' . $posted['payment_status'] ), $order, $posted );
			}
		}
	}

	/**
	 * Check stampayGO IPN validity.
	 */
	public function validate_ipn() {
		WC_Gateway_StampayGo::log( 'Checking IPN response is valid' );

		// Get received values from post data.
		$validate_ipn        = wp_unslash( $_POST ); // WPCS: CSRF ok, input var ok.

		WC_Gateway_StampayGo::log( 'stampayGO request id' . wc_print_r( $validate_ipn, true ) );

		// Send back post vars to stampay.
		$params = array(
			'body'        => array(
			    'payment_gateway_transaction' => $validate_ipn
            ),
			'timeout'     => 60,
			'httpversion' => '1.1',
			'compress'    => false,
			'decompress'  => false,
			'user-agent'  => 'WooCommerce/' . WC()->version,
		);

		$url = $this->sandbox ? 'https://demoapi.go.stampay.com/v1/payment_gateway_transactions/%s' : 'https://api.go.stampay.com/v1/payment_gateway_transactions/%s';
		$url = sprintf( $url, $validate_ipn['request_id'] );

		// Post back to get a response.
		$response = wp_remote_post( $url, $params );

		WC_Gateway_StampayGo::log( 'IPN Response: ' . wc_print_r( $response, true ) );

		// Check to see if the request was valid.
		if ( ! is_wp_error( $response ) && $response['response']['code'] >= 200 && $response['response']['code'] < 300 && json_decode( $response['body'], true )['valid'] == 1 ) {
			WC_Gateway_StampayGo::log( 'Received valid response from stampayGO IPN' );

			return true;
		}

		WC_Gateway_StampayGo::log( 'Received invalid response from stampayGO IPN' );

		if ( is_wp_error( $response ) ) {
			WC_Gateway_StampayGo::log( 'Error response: ' . $response->get_error_message() );
		}

		return false;
	}

	/**
	 * Check currency from IPN matches the order.
	 *
	 * @param WC_Order $order Order object.
	 * @param string $currency Currency code.
	 */
	protected function validate_currency( $order, $currency ) {
		if ( $order->get_currency() !== $currency ) {
			WC_Gateway_StampayGo::log( 'Payment error: Currencies do not match (sent "' . $order->get_currency() . '" | returned "' . $currency . '")' );

			/* translators: %s: currency code. */
			$order->update_status( 'on-hold', sprintf( __( 'Validation error: stampayGO currencies do not match (code %s).', 'stampaygo' ), $currency ) );
			exit;
		}
	}

	/**
	 * Check payment amount from IPN matches the order.
	 *
	 * @param WC_Order $order Order object.
	 * @param int $amount Amount to validate.
	 */
	protected function validate_amount( $order, $amount ) {
		if ( number_format( $order->get_total(), 2, '.', '' ) !== number_format( $amount, 2, '.', '' ) ) {
			WC_Gateway_StampayGo::log( 'Payment error: Amounts do not match (gross ' . $amount . ')' );

			/* translators: %s: Amount. */
			$order->update_status( 'on-hold', sprintf( __( 'Validation error: stampayGO amounts do not match (gross %s).', 'stampaygo' ), $amount ) );
			exit;
		}
	}

	/**
	 * Handle a completed payment.
	 *
	 * @param WC_Order $order Order object.
	 * @param array $posted Posted data.
	 */
	protected function payment_status_success( $order, $posted ) {
		WC_Gateway_StampayGo::log( 'Handling a success payment for order: ' . $order->get_id() . ' with $posted' . wc_print_r( $posted, true ) );
		WC_Gateway_StampayGo::log( 'Order has status: ' . $order->get_status() . '. Is paid statuses are: ' . wc_Print_r(wc_get_is_paid_statuses(), true) );

		if ( $order->has_status( wc_get_is_paid_statuses() ) ) {
			WC_Gateway_StampayGo::log( 'Aborting, Order #' . $order->get_id() . ' is already complete.' );
			exit;
		}

		$this->validate_currency( $order, $posted['currency'] );
		$this->validate_amount( $order, $posted['total_amount'] );

		if ( 'success' === $posted['payment_status'] ) {
			if ( $order->has_status( 'canceled' ) ) {
				$this->payment_status_paid_canceled_order( $order, $posted );
			}

			$this->payment_complete( $order, ( ! empty( $posted['txn_id'] ) ? wc_clean( $posted['txn_id'] ) : '' ), __( 'IPN payment completed', 'woocommerce' ) );
		}
	}

	/**
	 * Handle a failed payment.
	 *
	 * @param WC_Order $order Order object.
	 * @param array $posted Posted data.
	 */
	protected function payment_status_failed( $order, $posted ) {
		/* translators: %s: payment status. */
		$order->update_status( 'failed', sprintf( __( 'Payment %s via IPN.', 'woocommerce' ), wc_clean( $posted['payment_status'] ) ) );
	}

	/**
	 * Handle a canceled payment.
	 *
	 * @param WC_Order $order Order object.
	 * @param array $posted Posted data.
	 */
	protected function payment_status_canceled( $order, $posted ) {
		$this->payment_status_failed( $order, $posted );
	}

	/**
	 * When a user cancelled order is marked paid.
	 *
	 * @param WC_Order $order Order object.
	 * @param array $posted Posted data.
	 */
	protected function payment_status_paid_canceled_order( $order, $posted ) {
		WC_Gateway_StampayGo::log( 'Order #' . $order->get_id() . ' has been cancelled.' );
	}
}