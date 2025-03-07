<?php
/**
 * Class WC_Gateway_StampayGo_Response file.
 */

/**
 * Handles Responses.
 */
abstract class WC_Gateway_StampayGo_Response {
	/**
	 * Sandbox mode
	 *
	 * @var bool
	 */
	protected $sandbox = false;

	/**
	 * Get the order from the stampayGO 'Custom' variable.
	 *
	 * @param string $raw_custom JSON Data passed back by stampayGO.
	 *
	 * @return bool|WC_Order object
	 */
	protected function get_stampaygo_order( $raw_custom ) {
		// We have the data in the correct format, so get the order.
		$custom = json_decode( $raw_custom );
		if ( $custom && is_object( $custom ) ) {
			$order_id  = $custom->order_id;
			$order_key = $custom->order_key;
		} else {
			// Nothing was found.
			WC_Gateway_StampayGo::log( 'Order ID and key were not found in "custom".', 'error' );

			return false;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			// We have an invalid $order_id, probably because invoice_prefix has changed.
			$order_id = wc_get_order_id_by_order_key( $order_key );
			$order    = wc_get_order( $order_id );
		}

		if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			WC_Gateway_StampayGo::log( 'Order Keys do not match.', 'error' );

			return false;
		}

		return $order;
	}

	/**
	 * Complete order, add transaction ID and note.
	 *
	 * @param WC_Order $order Order object.
	 * @param string $txn_id Transaction ID.
	 * @param string $note Payment note.
	 */
	protected function payment_complete( $order, $txn_id = '', $note = '' ) {
		if ( ! $order->has_status( array( 'processing', 'completed' ) ) ) {
			$order->add_order_note( $note );
			$order->payment_complete( $txn_id );
			WC()->cart->empty_cart();
		}
	}

	/**
	 * Hold order and add note.
	 *
	 * @param WC_Order $order Order object.
	 * @param string $reason Reason why the payment is on hold.
	 */
	protected function payment_on_hold( $order, $reason = '' ) {
		$order->update_status( 'on-hold', $reason );
		WC()->cart->empty_cart();
	}
}