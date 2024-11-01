<?php
/**
 * Class WC_Gateway_StampayGo_Request file.
 */

/**
 * Generates requests to send to stampayGO
 */
class WC_Gateway_StampayGo_Request {

    /**
     * Stores line items to send to stampayGO.
     *
     * @var array
     */
    protected $line_items = array();

	/**
	 * Pointer to gateway making the request.
	 *
	 * @var WC_Gateway_StampayGo
	 */
	protected $gateway;

	/**
	 * Endpoint for requests from stampayGO.
	 *
	 * @var string
	 */
	protected $notify_url;

	/**
	 * Endpoint for requests to stampayGO.
	 *
	 * @var string
	 */
	protected $endpoint;

	/**
	 * Constructor.
	 *
	 * @param WC_Gateway_StampayGo $gateway stampayGO gateway object.
	 */
	public function __construct( $gateway ) {
		$this->gateway    = $gateway;
		$this->notify_url = WC()->api_request_url( 'WC_Gateway_StampayGo' );
	}

	/**
	 * Get the stampayGO request URL for an order.
	 *
	 * @param WC_Order $order Order object.
	 * @param bool $demo Whether to use demo mode or not.
	 *
	 * @return string
	 */
	public function get_request_url( $order, $demo = false ) {
		$this->endpoint  = $demo ? 'https://demo.go.stampay.com/restaurants/%s?' : 'https://go.stampay.com/restaurants/%s?';
		$this->endpoint  = sprintf( $this->endpoint, $this->gateway->get_option( 'restaurant_id' ) );
		$stampaygo_args = $this->get_stampaygo_args( $order );

		// Mask (remove) PII from the logs.
		$mask = array(
			'first_name'    => '***',
			'last_name'     => '***',
			'address1'      => '***',
			'address2'      => '***',
			'city'          => '***',
			'state'         => '***',
			'zip'           => '***',
			'country'       => '***',
			'email'         => '***@***',
			'phone_a' => '***',
			'phone_b' => '***',
			'phone_c' => '***',
		);

		WC_Gateway_StampayGo::log( 'Request args for order ' . $order->get_order_number() . ':' . wc_print_r( array_merge( $stampaygo_args, array_intersect_key( $mask, $stampaygo_args ) ), true ) );

		return $this->endpoint . http_build_query( $stampaygo_args, '', '&' );
	}

	/**
	 * Limit length of an arg.
	 *
	 * @param string $string Argument to limit.
	 * @param integer $limit Limit size in characters.
	 *
	 * @return string
	 */
	protected function limit_length( $string, $limit = 127 ) {
		$str_limit = $limit - 3;
		if ( function_exists( 'mb_strimwidth' ) ) {
			if ( mb_strlen( $string ) > $limit ) {
				$string = mb_strimwidth( $string, 0, $str_limit ) . '...';
			}
		} else {
			if ( strlen( $string ) > $limit ) {
				$string = substr( $string, 0, $str_limit ) . '...';
			}
		}

		return $string;
	}

	/**
	 * Get transaction args for stampayGO request, except for line item args.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return array
	 */
	protected function get_transaction_args( $order ) {
		return array(
            'amount'          => str_replace( '.', '', $order->get_total() ),
            'external_trx_id' => $order->get_id(),
            'currency_code' => get_woocommerce_currency(), // Not yet implemented in stampayGO
            'charset'       => 'utf-8',
            'return_url'        => esc_url_raw( add_query_arg( 'utm_nooverride', '1', $this->gateway->get_return_url( $order ) ) ),
            'cancel_return_url' => esc_url_raw( $order->get_cancel_order_url_raw() ),
//            'image_url'     => esc_url_raw( $this->gateway->get_option( 'image_url' ) ),
            'invoice'       => $this->limit_length( $this->gateway->get_option( 'invoice_prefix' ) . $order->get_order_number(), 127 ),
            'custom'        => wp_json_encode(
                array(
                    'order_id'  => $order->get_id(),
                    'order_key' => $order->get_order_key(),
                    'logo'      => wp_get_attachment_image_src( get_theme_mod( 'custom_logo' ), 'full', false),
                )
            ),
            'billing' => wp_json_encode(
                array_merge(
                    array(
                        'first_name'    => $this->limit_length( $order->get_billing_first_name(), 32 ),
                        'last_name'     => $this->limit_length( $order->get_billing_last_name(), 64 ),
                        'address1'      => $this->limit_length( $order->get_billing_address_1(), 100 ),
                        'address2'      => $this->limit_length( $order->get_billing_address_2(), 100 ),
                        'city'          => $this->limit_length( $order->get_billing_city(), 40 ),
                        'state'         => $this->get_stampaygo_state( $order->get_billing_country(), $order->get_billing_state() ),
                        'zip'           => $this->limit_length( wc_format_postcode( $order->get_billing_postcode(), $order->get_billing_country() ), 32 ),
                        'country'       => $this->limit_length( $order->get_billing_country(), 2 ),
                        'email'         => $this->limit_length( $order->get_billing_email() ),
                    ),
                    $this->get_phone_number_args( $order )
                )
            ),
            'notify_url'    => $this->limit_length( $this->notify_url, 255 ),
        );
	}

    /**
     * If the default request with line items is too long, generate a new one with only one line item.
     *
     * If URL is longer than 81578 chars, ignore line items and send cart to stampayGO as a single item.
     * One item's name can only be 127 characters long, so the URL should not be longer than limit.
     * URL character limit via:
     * https://stackoverflow.com/questions/32267442/url-length-limitation-of-microsoft-edge.
     *
     * @param WC_Order $order Order to be sent to stampayGO.
     * @param array    $stampaygo_args Arguments sent to stampayGO in the request.
     * @return array
     */
    protected function fix_request_length( $order, $stampaygo_args ) {
        $max_stampaygo_length = 81578;
        $query_candidate   = http_build_query( $stampaygo_args, '', '&' );

        if ( strlen( $this->endpoint . $query_candidate ) <= $max_stampaygo_length ) {
            return $stampaygo_args;
        }

        return apply_filters(
            'woocommerce_stampaygo_args',
            array_merge(
                $this->get_transaction_args( $order ),
                $this->get_line_item_args( $order, true )
            ),
            $order
        );
    }

	/**
	 * Get stampayGO Args for passing to stampayGO.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return array
	 */
	protected function get_stampaygo_args( $order ) {
        WC_Gateway_StampayGo::log( 'Generating payment form for order ' . $order->get_order_number() . '. Notify URL: ' . $this->notify_url );

        $force_one_line_item = apply_filters( 'woocommerce_stampaygo_force_one_line_item', false, $order );

        if ( ( wc_tax_enabled() && wc_prices_include_tax() ) || ! $this->line_items_valid( $order ) ) {
            $force_one_line_item = true;
        }

        $stampaygo_args = apply_filters(
            'woocommerce_stampaygo_args',
            array_merge(
                $this->get_transaction_args( $order ),
                array(
                    'line_items' => wp_json_encode(
                        $this->get_line_item_args( $order, $force_one_line_item )
                    )
                )
            ),
            $order
        );

        return $this->fix_request_length( $order, $stampaygo_args );
	}

    /**
     * Get phone number args for stampayGO request.
     *
     * @param  WC_Order $order Order object.
     * @return array
     */
    protected function get_phone_number_args( $order ) {
        $phone_number = wc_sanitize_phone_number( $order->get_billing_phone() );

        if ( in_array( $order->get_billing_country(), array( 'US', 'CA' ), true ) ) {
            $phone_number = ltrim( $phone_number, '+1' );
            $phone_args   = array(
                'phone_a' => substr( $phone_number, 0, 3 ),
                'phone_b' => substr( $phone_number, 3, 3 ),
                'phone_c' => substr( $phone_number, 6, 4 ),
            );
        } else {
            $calling_code = WC()->countries->get_country_calling_code( $order->get_billing_country() );
            $calling_code = is_array( $calling_code ) ? $calling_code[0] : $calling_code;

            if ( $calling_code ) {
                $phone_number = str_replace( $calling_code, '', preg_replace( '/^0/', '', $order->get_billing_phone() ) );
            }

            $phone_args = array(
                'phone_a' => $calling_code,
                'phone_b' => $phone_number,
            );
        }
        return $phone_args;
    }

    /**
     * Get shipping cost line item args for stampaygo request.
     *
     * @param  WC_Order $order Order object.
     * @param  bool     $force_one_line_item Whether one line item was forced by validation or URL length.
     * @return array
     */
    protected function get_shipping_cost_line_item( $order, $force_one_line_item ) {
        $line_item_args = array();
        $shipping_total = $order->get_shipping_total();
        if ( $force_one_line_item ) {
            $shipping_total += $order->get_shipping_tax();
        }

        // Add shipping costs. StampayGO ignores anything over 5 digits (999.99 is the max).
        // We also check that shipping is not the **only** cost as stampayGO won't allow payment
        // if the items have no cost.
        if ( $order->get_shipping_total() > 0 && $order->get_shipping_total() < 999.99 && $this->number_format( $order->get_shipping_total() + $order->get_shipping_tax(), $order ) !== $this->number_format( $order->get_total(), $order ) ) {
            $line_item_args['shipping_1'] = $this->number_format( $shipping_total, $order );
        } elseif ( $order->get_shipping_total() > 0 ) {
            /* translators: %s: Order shipping method */
            $this->add_line_item( sprintf( __( 'Shipping via %s', 'woocommerce' ), $order->get_shipping_method() ), 1, $this->number_format( $shipping_total, $order ) );
        }

        return $line_item_args;
    }

    /**
     * Get line item args for stampayGO request as a single line item.
     *
     * @param  WC_Order $order Order object.
     * @return array
     */
    protected function get_line_item_args_single_item( $order ) {
        $this->delete_line_items();

        $all_items_name = $this->get_order_item_names( $order );
        $this->add_line_item( $all_items_name ? $all_items_name : __( 'Order', 'woocommerce' ), 1, $this->number_format( $order->get_total() - $this->round( $order->get_shipping_total() + $order->get_shipping_tax(), $order ), $order ), $order->get_order_number() );
        $line_item_args = $this->get_shipping_cost_line_item( $order, true );

        return array_merge( $line_item_args, $this->get_line_items() );
    }

    /**
     * Get line item args for stampayGO request.
     *
     * @param  WC_Order $order Order object.
     * @param  bool     $force_one_line_item Create only one item for this order.
     * @return array
     */
    protected function get_line_item_args( $order, $force_one_line_item = false ) {
        $line_item_args = array();

        if ( $force_one_line_item ) {
            /**
             * Send order as a single item.
             *
             * For shipping, we longer use shipping_1 because stampayGO ignores it if *any* shipping rules are within stampayGO, and stampayGO ignores anything over 5 digits (999.99 is the max).
             */
            $line_item_args = $this->get_line_item_args_single_item( $order );
        } else {
            /**
             * Passing a line item per product if supported.
             */
            $this->prepare_line_items( $order );
            $line_item_args['tax_cart'] = $this->number_format( $order->get_total_tax(), $order );

            if ( $order->get_total_discount() > 0 ) {
                $line_item_args['discount_amount_cart'] = $this->number_format( $this->round( $order->get_total_discount(), $order ), $order );
            }

            $line_item_args = array_merge( $line_item_args, $this->get_shipping_cost_line_item( $order, false ) );
            $line_item_args = array_merge( $line_item_args, $this->get_line_items() );

        }

        return $line_item_args;
    }

    /**
     * Get order item names as a string.
     *
     * @param  WC_Order $order Order object.
     * @return string
     */
    protected function get_order_item_names( $order ) {
        $item_names = array();

        foreach ( $order->get_items() as $item ) {
            $item_name = $item->get_name();
            $item_meta = wp_strip_all_tags(
                wc_display_item_meta(
                    $item,
                    array(
                        'before'    => '',
                        'separator' => ', ',
                        'after'     => '',
                        'echo'      => false,
                        'autop'     => false,
                    )
                )
            );

            if ( $item_meta ) {
                $item_name .= ' (' . $item_meta . ')';
            }

            $item_names[] = $item_name . ' x ' . $item->get_quantity();
        }

        return apply_filters( 'woocommerce_stampaygo_get_order_item_names', implode( ', ', $item_names ), $order );
    }

    /**
     * Get order item names as a string.
     *
     * @param  WC_Order      $order Order object.
     * @param  WC_Order_Item $item Order item object.
     * @return string
     */
    protected function get_order_item_name( $order, $item ) {
        $item_name = $item->get_name();
        $item_meta = wp_strip_all_tags(
            wc_display_item_meta(
                $item,
                array(
                    'before'    => '',
                    'separator' => ', ',
                    'after'     => '',
                    'echo'      => false,
                    'autop'     => false,
                )
            )
        );

        if ( $item_meta ) {
            $item_name .= ' (' . $item_meta . ')';
        }

        return apply_filters( 'woocommerce_stampaygo_get_order_item_name', $item_name, $order, $item );
    }

    /**
     * Return all line items.
     */
    protected function get_line_items() {
        return $this->line_items;
    }

    /**
     * Remove all line items.
     */
    protected function delete_line_items() {
        $this->line_items = array();
    }

    /**
     * Check if the order has valid line items to use for stampayGO request.
     *
     * The line items are invalid in case of mismatch in totals or if any amount < 0.
     *
     * @param WC_Order $order Order to be examined.
     * @return bool
     */
    protected function line_items_valid( $order ) {
        $negative_item_amount = false;
        $calculated_total     = 0;

        // Products.
        foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
            if ( 'fee' === $item['type'] ) {
                $item_line_total   = $this->number_format( $item['line_total'], $order );
                $calculated_total += $item_line_total;
            } else {
                $item_line_total   = $this->number_format( $order->get_item_subtotal( $item, false ), $order );
                $calculated_total += $item_line_total * $item->get_quantity();
            }

            if ( $item_line_total < 0 ) {
                $negative_item_amount = true;
            }
        }
        $mismatched_totals = $this->number_format( $calculated_total + $order->get_total_tax() + $this->round( $order->get_shipping_total(), $order ) - $this->round( $order->get_total_discount(), $order ), $order ) !== $this->number_format( $order->get_total(), $order );
        return ! $negative_item_amount && ! $mismatched_totals;
    }

    /**
     * Get line items to send to stampayGO.
     *
     * @param  WC_Order $order Order object.
     */
    protected function prepare_line_items( $order ) {
        $this->delete_line_items();

        // Products.
        foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
            WC_Gateway_StampayGo::log( 'Line item:' . wc_print_r( $item ) );

            if ( 'fee' === $item['type'] ) {
                $item_line_total = $this->number_format( $item['line_total'], $order );
                $this->add_line_item( $item->get_name(), 1, $item_line_total );
            } else {
                $product         = $item->get_product();
                $sku             = $product ? $product->get_sku() : '';
                $item_line_total = $this->number_format( $order->get_item_subtotal( $item, false ), $order );
                $this->add_line_item( $this->get_order_item_name( $order, $item ), $item->get_quantity(), $item_line_total, $sku );
            }
        }
    }

    /**
     * Add stampayGO Line Item.
     *
     * @param  string $item_name Item name.
     * @param  int    $quantity Item quantity.
     * @param  float  $amount Amount.
     * @param  string $item_number Item number.
     */
    protected function add_line_item( $item_name, $quantity = 1, $amount = 0.0, $item_number = '' ) {
//        $index = ( count( $this->line_items ) / 4 ) + 1;

        $item = apply_filters(
            'woocommerce_stampaygo_line_item',
            array(
                'item_name'   => html_entity_decode( wc_trim_string( $item_name ? wp_strip_all_tags( $item_name ) : __( 'Item', 'woocommerce' ), 127 ), ENT_NOQUOTES, 'UTF-8' ),
                'quantity'    => (int) $quantity,
                'amount'      => wc_float_to_string( (float) $amount ),
                'item_number' => $item_number,
            ),
            $item_name,
            $quantity,
            $amount,
            $item_number
        );

        $new_line_item = array(
            'item_name' => $this->limit_length( $item['item_name'], 127 ),
            'quantity' => $item['quantity'],
            'amount' => $item['amount'],
            'item_number' => $this->limit_length( $item['item_number'], 127 ),
        );

        array_push($this->line_items, $new_line_item);

//        $this->line_items[ 'item_name_' . $index ]   = $this->limit_length( $item['item_name'], 127 );
//        $this->line_items[ 'quantity_' . $index ]    = $item['quantity'];
//        $this->line_items[ 'amount_' . $index ]      = $item['amount'];
//        $this->line_items[ 'item_number_' . $index ] = $this->limit_length( $item['item_number'], 127 );
    }

    /**
     * Get the state to send to stampayGO.
     *
     * @param  string $cc Country two letter code.
     * @param  string $state State code.
     * @return string
     */
    protected function get_stampaygo_state( $cc, $state ) {
        if ( 'US' === $cc ) {
            return $state;
        }

        $states = WC()->countries->get_states( $cc );

        if ( isset( $states[ $state ] ) ) {
            return $states[ $state ];
        }

        return $state;
    }

    /**
     * Check if currency has decimals.
     *
     * @param  string $currency Currency to check.
     * @return bool
     */
    protected function currency_has_decimals( $currency ) {
        if ( in_array( $currency, array( 'HUF', 'JPY', 'TWD' ), true ) ) {
            return false;
        }

        return true;
    }

    /**
     * Round prices.
     *
     * @param  double   $price Price to round.
     * @param  WC_Order $order Order object.
     * @return double
     */
    protected function round( $price, $order ) {
        $precision = 2;

        if ( ! $this->currency_has_decimals( $order->get_currency() ) ) {
            $precision = 0;
        }

        return round( $price, $precision );
    }

    /**
     * Format prices.
     *
     * @param  float|int $price Price to format.
     * @param  WC_Order  $order Order object.
     * @return string
     */
    protected function number_format( $price, $order ) {
        $decimals = 2;

        if ( ! $this->currency_has_decimals( $order->get_currency() ) ) {
            $decimals = 0;
        }

        return number_format( $price, $decimals, '.', '' );
    }
}