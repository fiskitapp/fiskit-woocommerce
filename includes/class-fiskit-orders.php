<?php
/**
 * Issues the invoice of each paid order and the credit note of each refund, in the background, and
 * shows them to the shop owner and to the customer.
 *
 * Orders and refunds are both "documents" here: each one gets at most one voucher, stored in its meta.
 *
 * @package Fiskit_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks into the order lifecycle.
 */
class Fiskit_Orders {

	const GROUP        = 'fiskit';
	const ISSUE_HOOK   = 'fiskit_wc_issue';
	const REFUND_HOOK  = 'fiskit_wc_refund';
	const CHECK_HOOK   = 'fiskit_wc_check';
	const MAX_ATTEMPTS = 10;
	const MAX_CHECKS   = 40;

	/** Order meta keys. */
	const META_STATUS       = '_fiskit_status'; // authorized, pending, error
	const META_ID           = '_fiskit_invoice_id';
	const META_TYPE         = '_fiskit_voucher_type';
	const META_POS          = '_fiskit_point_of_sale';
	const META_NUMBER       = '_fiskit_number';
	const META_CAE          = '_fiskit_cae';
	const META_CAE_DUE      = '_fiskit_cae_due_date';
	const META_RECEIVER     = '_fiskit_receiver';
	const META_LIVE         = '_fiskit_livemode';
	const META_ERROR        = '_fiskit_error';
	const META_REQUEST      = '_fiskit_request';
	const META_DOCUMENT     = '_billing_fiskit_document';
	const BLOCK_DOCUMENT    = 'fiskit/document';
	const META_BLOCK_DOC    = '_wc_other/fiskit/document';

	/** @var Fiskit_Integration */
	private $settings;

	/**
	 * @param Fiskit_Integration $settings Plugin settings.
	 */
	public function __construct( Fiskit_Integration $settings ) {
		$this->settings = $settings;
	}

	public function register() {
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_status' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_status' ), 10, 1 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refund' ), 10, 2 );
		add_action( self::ISSUE_HOOK, array( $this, 'issue_invoice' ), 10, 2 );
		add_action( self::REFUND_HOOK, array( $this, 'issue_credit_note' ), 10, 3 );
		add_action( self::CHECK_HOOK, array( $this, 'check' ), 10, 2 );

		add_filter( 'woocommerce_order_actions', array( $this, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_fiskit_issue', array( $this, 'retry_from_admin' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_post_fiskit_pdf', array( $this, 'admin_pdf' ) );

		// Orders list: an Invoice column and a bulk action, on the HPOS screen and the legacy one
		foreach ( array( 'woocommerce_page_wc-orders', 'edit-shop_order' ) as $screen ) {
			add_filter( "bulk_actions-{$screen}", array( $this, 'bulk_actions' ) );
			add_filter( "handle_bulk_actions-{$screen}", array( $this, 'handle_bulk_action' ), 10, 3 );
		}
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );

		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_link' ), 10, 3 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'customer_link' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'my_orders_action' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'customer_pdf' ) );

		if ( 'yes' === $this->settings->get_option( 'document' ) ) {
			add_filter( 'woocommerce_billing_fields', array( $this, 'checkout_field' ) );
			add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout_field' ), 10, 2 );
			add_action( 'woocommerce_init', array( $this, 'block_checkout_field' ) );
		}
	}

	// ---------------------------------------------------------------- Triggers

	/**
	 * @param int $order_id Order whose status just changed.
	 */
	public function on_status( $order_id ) {
		$order = wc_get_order( $order_id );
		// "Paid" also covers orders completed straight away, without going through processing
		$statuses = 'completed' === $this->settings->get_option( 'trigger' ) ? array( 'completed' ) : array( 'processing', 'completed' );
		if ( ! $order || ! $this->active() || ! $order->has_status( $statuses ) ) {
			return;
		}
		if ( in_array( $order->get_meta( self::META_STATUS ), array( 'authorized', 'pending' ), true ) ) {
			return;
		}
		$this->schedule( self::ISSUE_HOOK, array( $order_id, 0 ) );
	}

	/**
	 * @param int $order_id  Refunded order.
	 * @param int $refund_id The refund just created.
	 */
	public function on_refund( $order_id, $refund_id ) {
		if ( $this->active() && 'yes' === $this->settings->get_option( 'refunds' ) ) {
			$this->schedule( self::REFUND_HOOK, array( $order_id, $refund_id, 0 ) );
		}
	}

	// ---------------------------------------------------------------- Background jobs

	/**
	 * @param int $order_id Order to invoice.
	 * @param int $attempt  Previous temporary failures.
	 */
	public function issue_invoice( $order_id, $attempt = 0 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $this->active() || in_array( $order->get_meta( self::META_STATUS ), array( 'authorized', 'pending' ), true ) ) {
			return;
		}
		if ( 'ARS' !== $order->get_currency() ) {
			$this->fail( $order, __( 'Only orders in Argentine pesos are invoiced.', 'fiskit-for-woocommerce' ) );
			return;
		}
		if ( (float) $order->get_total() <= 0 ) {
			$order->add_order_note( __( 'FisKit: the order total is zero, there is nothing to invoice.', 'fiskit-for-woocommerce' ) );
			return;
		}

		// The body is built once and reused, so every retry sends exactly the same request
		$body = $order->get_meta( self::META_REQUEST );
		if ( ! is_array( $body ) ) {
			$data = $this->order_data( $order );
			if ( is_wp_error( $data ) ) {
				$this->retry_or_fail( $order, $data, self::ISSUE_HOOK, array( $order_id ), $attempt );
				return;
			}
			$body = $this->builder()->invoice( $data );
			$order->update_meta_data( self::META_REQUEST, $body );
			$order->save();
		}

		$this->send( $order, $body, $this->idempotency_key( 'order', $order_id ), self::ISSUE_HOOK, array( $order_id ), $attempt );
	}

	/**
	 * @param int $order_id  Refunded order.
	 * @param int $refund_id Refund to credit.
	 * @param int $attempt   Previous temporary failures or waits for the invoice.
	 */
	public function issue_credit_note( $order_id, $refund_id, $attempt = 0 ) {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund || ! $this->active() || in_array( $refund->get_meta( self::META_STATUS ), array( 'authorized', 'pending' ), true ) ) {
			return;
		}
		$status = $order->get_meta( self::META_STATUS );
		if ( 'pending' === $status && $attempt < self::MAX_CHECKS ) {
			// The invoice is still being issued: the credit note waits for its number
			$this->schedule( self::REFUND_HOOK, array( $order_id, $refund_id, $attempt + 1 ), 10 * MINUTE_IN_SECONDS );
			return;
		}
		if ( 'authorized' !== $status ) {
			$order->add_order_note( __( 'FisKit: the refund has no credit note because the order has no authorized invoice.', 'fiskit-for-woocommerce' ) );
			return;
		}

		$body = $refund->get_meta( self::META_REQUEST );
		if ( ! is_array( $body ) ) {
			$body = $this->builder()->credit_note(
				array(
					'voucher_type'  => $order->get_meta( self::META_TYPE ),
					'point_of_sale' => $order->get_meta( self::META_POS ),
					'number'        => $order->get_meta( self::META_NUMBER ),
					'receiver'      => (array) $order->get_meta( self::META_RECEIVER ),
				),
				array(
					'amount' => abs( (float) $refund->get_amount() ),
					'date'   => $this->date( $refund ),
					'reason' => $refund->get_reason(),
				)
			);
			$refund->update_meta_data( self::META_REQUEST, $body );
			$refund->save();
		}

		$this->send( $refund, $body, $this->idempotency_key( 'refund', $refund_id ), self::REFUND_HOOK, array( $order_id, $refund_id ), $attempt );
	}

	/**
	 * Looks up a voucher FisKit is finishing in the background (ARCA was slow or down).
	 *
	 * @param int $document_id Order or refund.
	 * @param int $attempt     Checks so far.
	 */
	public function check( $document_id, $attempt = 0 ) {
		$document = wc_get_order( $document_id );
		if ( ! $document || 'pending' !== $document->get_meta( self::META_STATUS ) || ! $this->active() ) {
			return;
		}
		$invoice = $this->api()->get_invoice( $document->get_meta( self::META_ID ) );
		if ( is_wp_error( $invoice ) ) {
			$this->schedule( self::CHECK_HOOK, array( $document_id, $attempt + 1 ), $this->check_delay( $attempt ) );
			return;
		}
		$this->apply( $document, $invoice, $attempt );
	}

	// ---------------------------------------------------------------- Issuing

	private function send( WC_Abstract_Order $document, array $body, $key, $hook, array $args, $attempt ) {
		$invoice = $this->api()->create_invoice( $body, $key );
		if ( is_wp_error( $invoice ) ) {
			$this->retry_or_fail( $document, $invoice, $hook, $args, $attempt );
			return;
		}
		$document->update_meta_data( self::META_RECEIVER, isset( $body['receiver'] ) ? $body['receiver'] : array() );
		$this->apply( $document, $invoice, -1 );
	}

	/**
	 * Stores what the API answered and schedules a check while it is still in progress.
	 *
	 * @param int $attempt Checks so far; -1 right after issuing.
	 */
	private function apply( WC_Abstract_Order $document, array $invoice, $attempt ) {
		$status = isset( $invoice['status'] ) ? $invoice['status'] : '';
		$document->update_meta_data( self::META_ID, $invoice['id'] );
		$document->update_meta_data( self::META_TYPE, $invoice['voucher_type'] );
		$document->update_meta_data( self::META_POS, $invoice['point_of_sale'] );
		$document->update_meta_data( self::META_LIVE, $this->api()->is_live() ? 'yes' : 'no' );
		$note_target = $this->note_target( $document );

		if ( 'authorized' === $status ) {
			$document->update_meta_data( self::META_STATUS, 'authorized' );
			$document->update_meta_data( self::META_NUMBER, $invoice['number'] );
			$document->update_meta_data( self::META_CAE, $invoice['cae'] );
			$document->update_meta_data( self::META_CAE_DUE, $invoice['cae_due_date'] );
			$document->delete_meta_data( self::META_ERROR );
			$document->save();
			$note_target->add_order_note(
				sprintf(
					/* translators: 1: voucher, for example "Invoice C 0001-00000042"; 2: CAE. 3: "(test)" or empty. */
					__( 'FisKit: %1$s authorized by ARCA, CAE %2$s %3$s', 'fiskit-for-woocommerce' ),
					self::voucher_label( $document ),
					$invoice['cae'],
					$this->api()->is_live() ? '' : __( '(test, no fiscal validity)', 'fiskit-for-woocommerce' )
				)
			);
			return;
		}

		if ( in_array( $status, array( 'pending', 'processing', 'reconciling' ), true ) ) {
			$document->update_meta_data( self::META_STATUS, 'pending' );
			$document->save();
			if ( $attempt < 0 ) {
				$note_target->add_order_note( __( 'FisKit: ARCA is slow to answer. The voucher is issued in the background and will show up here.', 'fiskit-for-woocommerce' ) );
			}
			if ( $attempt + 1 < self::MAX_CHECKS ) {
				$this->schedule( self::CHECK_HOOK, array( $document->get_id(), $attempt + 1 ), $this->check_delay( $attempt + 1 ) );
			}
			return;
		}

		$reasons = array();
		foreach ( isset( $invoice['authority_errors'] ) ? $invoice['authority_errors'] : array() as $error ) {
			$reasons[] = $error['message'];
		}
		$this->fail( $document, $reasons ? implode( ' · ', $reasons ) : __( 'ARCA did not authorize the voucher.', 'fiskit-for-woocommerce' ) );
	}

	private function retry_or_fail( WC_Abstract_Order $document, WP_Error $error, $hook, array $args, $attempt ) {
		if ( Fiskit_Api::is_temporary( $error ) && $attempt + 1 < self::MAX_ATTEMPTS ) {
			$args[] = $attempt + 1;
			$this->schedule( $hook, $args, min( 60, 2 ** $attempt ) * MINUTE_IN_SECONDS );
			return;
		}
		$data    = $error->get_error_data();
		$message = $error->get_error_message();
		if ( ! empty( $data['authority_errors'] ) ) {
			$message .= ': ' . implode( ' · ', wp_list_pluck( $data['authority_errors'], 'message' ) );
		}
		if ( ! empty( $data['param'] ) ) {
			$message .= ' (' . $data['param'] . ')';
		}
		$this->fail( $document, $message );
	}

	private function fail( WC_Abstract_Order $document, $message ) {
		$document->update_meta_data( self::META_STATUS, 'error' );
		$document->update_meta_data( self::META_ERROR, $message );
		$document->save();
		$this->note_target( $document )->add_order_note(
			/* translators: %s: why it failed. */
			sprintf( __( 'FisKit: the voucher could not be issued. %s', 'fiskit-for-woocommerce' ), $message )
		);
	}

	/**
	 * The data of an order as Fiskit_Invoice_Builder reads it. For a CUIT it asks the registry for the
	 * VAT condition, which decides between Invoice A and B.
	 *
	 * @return array|WP_Error
	 */
	private function order_data( WC_Order $order ) {
		$document  = $this->document_of( $order );
		$condition = null;
		$parsed    = Fiskit_Invoice_Builder::parse_document( $document );
		if ( $parsed && 'cuit' === $parsed['type'] ) {
			$taxpayer = $this->api()->get_taxpayer( $parsed['number'] );
			if ( is_wp_error( $taxpayer ) ) {
				if ( Fiskit_Api::is_temporary( $taxpayer ) ) {
					return $taxpayer;
				}
				// Not in the registry: invoiced to an unidentified final consumer instead of failing the order
				$order->add_order_note(
					/* translators: %s: the CUIT. */
					sprintf( __( 'FisKit: the CUIT %s is not in the ARCA registry; invoiced to a final consumer.', 'fiskit-for-woocommerce' ), $document )
				);
				$document = '';
			} else {
				$condition = isset( $taxpayer['receiver_vat_condition_id'] ) ? (int) $taxpayer['receiver_vat_condition_id'] : null;
			}
		}

		$lines = array();
		foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {
			$total = (float) $item->get_total() + (float) $item->get_total_tax();
			if ( $total <= 0 ) {
				continue;
			}
			$lines[] = array(
				'description' => $item->get_name(),
				'quantity'    => $item->is_type( 'line_item' ) ? $item->get_quantity() : 1,
				'total'       => $total,
			);
		}

		$address = array_filter(
			array(
				$order->get_billing_address_1(),
				$order->get_billing_address_2(),
				$order->get_billing_city(),
				$order->get_billing_state(),
			)
		);
		$name    = trim( $order->get_billing_company() ? $order->get_billing_company() : $order->get_formatted_billing_full_name() );

		return array(
			'number'                    => $order->get_order_number(),
			'date'                      => $this->date( $order ),
			'total'                     => $order->get_total(),
			'name'                      => $name,
			'email'                     => $order->get_billing_email(),
			'address'                   => implode( ', ', $address ),
			'document'                  => $document,
			'receiver_vat_condition_id' => $condition,
			'lines'                     => $lines,
		);
	}

	// ---------------------------------------------------------------- Admin

	/**
	 * @param array    $actions Order actions.
	 * @param WC_Order $order   The order on screen.
	 */
	public function order_actions( $actions, $order = null ) {
		if ( $order && $this->active() && in_array( $order->get_meta( self::META_STATUS ), array( '', 'error' ), true ) ) {
			$actions['fiskit_issue'] = __( 'Issue the FisKit invoice', 'fiskit-for-woocommerce' );
		}
		return $actions;
	}

	/**
	 * @param WC_Order $order The order on screen.
	 */
	public function retry_from_admin( $order ) {
		$order->delete_meta_data( self::META_STATUS );
		$order->delete_meta_data( self::META_ERROR );
		$order->delete_meta_data( self::META_REQUEST );
		$order->save();
		$this->issue_invoice( $order->get_id(), 0 );
	}

	/**
	 * @param array $actions Bulk actions of the orders list.
	 */
	public function bulk_actions( $actions ) {
		if ( $this->active() ) {
			$actions['fiskit_issue'] = __( 'Issue FisKit invoices', 'fiskit-for-woocommerce' );
		}
		return $actions;
	}

	/**
	 * Queues the invoice of every selected paid order that has none, including those that failed.
	 *
	 * @param string $redirect_to Where to go afterwards.
	 * @param string $action      Chosen bulk action.
	 * @param array  $ids         Selected orders.
	 */
	public function handle_bulk_action( $redirect_to, $action, $ids ) {
		if ( 'fiskit_issue' !== $action || ! $this->active() ) {
			return $redirect_to;
		}
		$queued = 0;
		$paid   = 'completed' === $this->settings->get_option( 'trigger' ) ? array( 'completed' ) : array( 'processing', 'completed' );
		foreach ( array_map( 'absint', (array) $ids ) as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order || ! $order->has_status( $paid ) || in_array( $order->get_meta( self::META_STATUS ), array( 'authorized', 'pending' ), true ) ) {
				continue;
			}
			$order->delete_meta_data( self::META_STATUS );
			$order->delete_meta_data( self::META_ERROR );
			$order->delete_meta_data( self::META_REQUEST );
			$order->save();
			$this->schedule( self::ISSUE_HOOK, array( $id, 0 ) );
			++$queued;
		}
		return add_query_arg(
			array(
				'fiskit_queued'  => $queued,
				'fiskit_skipped' => count( (array) $ids ) - $queued,
			),
			$redirect_to
		);
	}

	public function bulk_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only: two counts set by our own redirect, cast to integers, no action taken
		if ( ! isset( $_GET['fiskit_queued'] ) ) {
			return;
		}
		$queued  = absint( $_GET['fiskit_queued'] );
		$skipped = isset( $_GET['fiskit_skipped'] ) ? absint( $_GET['fiskit_skipped'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$message = sprintf(
			/* translators: %d: number of orders. */
			_n( '%d invoice is being issued in the background.', '%d invoices are being issued in the background.', $queued, 'fiskit-for-woocommerce' ),
			$queued
		);
		if ( $skipped ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of orders. */
				_n( '%d order skipped: already invoiced or not paid.', '%d orders skipped: already invoiced or not paid.', $skipped, 'fiskit-for-woocommerce' ),
				$skipped
			);
		}
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * @param array $columns Orders list columns.
	 */
	public function add_column( $columns ) {
		$result = array();
		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			// Right after the order total, or at the end if the list has no total column
			if ( 'order_total' === $key ) {
				$result['fiskit_invoice'] = __( 'Invoice', 'fiskit-for-woocommerce' );
			}
		}
		if ( ! isset( $result['fiskit_invoice'] ) ) {
			$result['fiskit_invoice'] = __( 'Invoice', 'fiskit-for-woocommerce' );
		}
		return $result;
	}

	/**
	 * @param string       $column        Column being rendered.
	 * @param int|WC_Order $order_or_id   The row's order (HPOS) or its post id (legacy).
	 */
	public function render_column( $column, $order_or_id ) {
		if ( 'fiskit_invoice' !== $column ) {
			return;
		}
		$order  = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
		$status = $order ? $order->get_meta( self::META_STATUS ) : '';
		if ( 'authorized' === $status ) {
			echo esc_html( self::voucher_label( $order ) );
			if ( 'yes' !== $order->get_meta( self::META_LIVE ) ) {
				echo ' <em>' . esc_html__( '(test)', 'fiskit-for-woocommerce' ) . '</em>';
			}
		} elseif ( 'pending' === $status ) {
			echo '<span style="color:#996800">' . esc_html__( 'Being issued', 'fiskit-for-woocommerce' ) . '</span>';
		} elseif ( 'error' === $status ) {
			printf(
				'<span style="color:#b32d2e" title="%1$s">%2$s</span>',
				esc_attr( $order->get_meta( self::META_ERROR ) ),
				esc_html__( 'Error', 'fiskit-for-woocommerce' )
			);
		} else {
			echo '<span aria-hidden="true">–</span>';
		}
	}

	/**
	 * Customer emails carry a link to the invoice. The email of a just-paid order goes out before ARCA
	 * answers, so the link is added as soon as the order will be invoiced; if it is opened too early, the
	 * page says the invoice is still being issued.
	 *
	 * @param WC_Order $order         The order in the email.
	 * @param bool     $sent_to_admin Whether the email goes to the shop.
	 * @param bool     $plain_text    Plain text or HTML email.
	 */
	public function email_link( $order, $sent_to_admin = false, $plain_text = false ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order || ! $this->active() ) {
			return;
		}
		$status = $order->get_meta( self::META_STATUS );
		$paid   = 'completed' === $this->settings->get_option( 'trigger' ) ? array( 'completed' ) : array( 'processing', 'completed' );
		if ( 'error' === $status || ( '' === $status && ! $order->has_status( $paid ) ) ) {
			return;
		}
		$url  = $this->customer_pdf_url( $order );
		$text = __( 'Download your invoice', 'fiskit-for-woocommerce' );
		if ( $plain_text ) {
			echo "\n" . esc_html( $text ) . ': ' . esc_url_raw( $url ) . "\n\n";
			return;
		}
		printf(
			'<p style="margin:16px 0"><a href="%1$s" style="font-weight:bold">%2$s</a></p>',
			esc_url( $url ),
			esc_html( $text )
		);
	}

	public function add_meta_box() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'fiskit-invoice', __( 'ARCA invoice', 'fiskit-for-woocommerce' ), array( $this, 'render_meta_box' ), $screen, 'side', 'high' );
		}
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order The order being edited.
	 */
	public function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order ) {
			return;
		}
		$documents = array( $order );
		foreach ( $order->get_refunds() as $refund ) {
			if ( $refund->get_meta( self::META_STATUS ) ) {
				$documents[] = $refund;
			}
		}
		echo '<div class="fiskit-invoice">';
		foreach ( $documents as $document ) {
			$this->render_document( $document, $order );
		}
		echo '</div>';
	}

	private function render_document( WC_Abstract_Order $document, WC_Order $order ) {
		$status = $document->get_meta( self::META_STATUS );
		if ( '' === $status ) {
			echo '<p>' . esc_html( $this->active() ? __( 'Not invoiced yet. It is issued automatically when the order is paid.', 'fiskit-for-woocommerce' ) : __( 'Set your FisKit API key in WooCommerce → Settings → Integration.', 'fiskit-for-woocommerce' ) ) . '</p>';
			return;
		}
		if ( 'pending' === $status ) {
			echo '<p>' . esc_html__( 'Being issued: ARCA is slow to answer.', 'fiskit-for-woocommerce' ) . '</p>';
			return;
		}
		if ( 'error' === $status ) {
			echo '<p style="color:#b32d2e">' . esc_html( $document->get_meta( self::META_ERROR ) ) . '</p>';
			if ( $document === $order ) {
				echo '<p>' . esc_html__( 'Fix it and choose "Issue the FisKit invoice" in the order actions.', 'fiskit-for-woocommerce' ) . '</p>';
			}
			return;
		}
		$pdf = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'fiskit_pdf',
					'document' => $document->get_id(),
				),
				admin_url( 'admin-post.php' )
			),
			'fiskit_pdf_' . $document->get_id()
		);
		printf(
			'<p><strong>%1$s</strong>%2$s<br>CAE %3$s<br><a href="%4$s" target="_blank" rel="noopener">%5$s</a></p>',
			esc_html( self::voucher_label( $document ) ),
			'yes' === $document->get_meta( self::META_LIVE ) ? '' : ' <em>' . esc_html__( '(test)', 'fiskit-for-woocommerce' ) . '</em>',
			esc_html( $document->get_meta( self::META_CAE ) ),
			esc_url( $pdf ),
			esc_html__( 'Download PDF', 'fiskit-for-woocommerce' )
		);
	}

	public function admin_pdf() {
		$id = isset( $_GET['document'] ) ? absint( $_GET['document'] ) : 0;
		check_admin_referer( 'fiskit_pdf_' . $id );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to see this invoice.', 'fiskit-for-woocommerce' ), 403 );
		}
		$this->stream_pdf( wc_get_order( $id ) );
	}

	// ---------------------------------------------------------------- Customer

	/**
	 * @param WC_Order $order Order shown to the customer.
	 */
	public function customer_link( $order ) {
		if ( 'authorized' !== $order->get_meta( self::META_STATUS ) ) {
			return;
		}
		printf(
			'<p class="fiskit-invoice-link"><a class="button" href="%1$s" target="_blank" rel="noopener">%2$s</a></p>',
			esc_url( $this->customer_pdf_url( $order ) ),
			/* translators: %s: voucher, for example "Invoice C 0001-00000042". */
			esc_html( sprintf( __( 'Download %s', 'fiskit-for-woocommerce' ), self::voucher_label( $order ) ) )
		);
	}

	/**
	 * @param array    $actions My Account order actions.
	 * @param WC_Order $order   The order in the row.
	 */
	public function my_orders_action( $actions, $order ) {
		if ( 'authorized' === $order->get_meta( self::META_STATUS ) ) {
			$actions['fiskit-invoice'] = array(
				'url'  => $this->customer_pdf_url( $order ),
				'name' => __( 'Invoice', 'fiskit-for-woocommerce' ),
			);
		}
		return $actions;
	}

	/**
	 * Serves the PDF to whoever has the order key: the same secret WooCommerce uses for its order links.
	 */
	public function customer_pdf() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a link sent by email cannot carry a nonce; access is granted by the order key, the same secret WooCommerce uses for its own order links, compared with hash_equals
		if ( empty( $_GET['fiskit_invoice'] ) || empty( $_GET['key'] ) ) {
			return;
		}
		$order = wc_get_order( absint( $_GET['fiskit_invoice'] ) );
		$key   = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $order || ! $order instanceof WC_Order || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_die( esc_html__( 'Invoice not found.', 'fiskit-for-woocommerce' ), 404 );
		}
		if ( 'authorized' !== $order->get_meta( self::META_STATUS ) && 'error' !== $order->get_meta( self::META_STATUS ) ) {
			// Linked from the order email before ARCA answered: not an error, just early
			wp_die(
				esc_html__( 'Your invoice is still being issued. Try again in a few minutes.', 'fiskit-for-woocommerce' ),
				esc_html__( 'Invoice', 'fiskit-for-woocommerce' ),
				array( 'response' => 200 )
			);
		}
		$this->stream_pdf( $order );
	}

	private function customer_pdf_url( WC_Order $order ) {
		return add_query_arg(
			array(
				'fiskit_invoice' => $order->get_id(),
				'key'            => $order->get_order_key(),
			),
			home_url( '/' )
		);
	}

	private function stream_pdf( $document ) {
		if ( ! $document || 'authorized' !== $document->get_meta( self::META_STATUS ) ) {
			wp_die( esc_html__( 'Invoice not found.', 'fiskit-for-woocommerce' ), 404 );
		}
		$pdf = $this->api()->get_pdf( $document->get_meta( self::META_ID ) );
		if ( is_wp_error( $pdf ) ) {
			wp_die( esc_html( $pdf->get_error_message() ), 502 );
		}
		$name = sanitize_file_name( strtolower( self::voucher_label( $document ) ) ) . '.pdf';
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $name . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF
		exit;
	}

	// ---------------------------------------------------------------- Checkout

	/**
	 * Classic checkout: an optional CUIT or DNI field under the billing details.
	 *
	 * @param array $fields Billing fields.
	 */
	public function checkout_field( $fields ) {
		$fields['billing_fiskit_document'] = array(
			'label'       => __( 'CUIT or DNI', 'fiskit-for-woocommerce' ),
			'placeholder' => __( 'Optional, for the invoice', 'fiskit-for-woocommerce' ),
			'required'    => false,
			'class'       => array( 'form-row-wide' ),
			'priority'    => 35,
		);
		return $fields;
	}

	/**
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Checkout errors.
	 */
	public function validate_checkout_field( $data, $errors ) {
		if ( empty( $data['billing_fiskit_document'] ) ) {
			return;
		}
		$message = self::document_error( $data['billing_fiskit_document'] );
		if ( $message ) {
			$errors->add( 'validation', $message );
		}
	}

	/**
	 * Block checkout (the default since WooCommerce 8.3): the same field through the additional fields API.
	 */
	public function block_checkout_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'                => self::BLOCK_DOCUMENT,
				'label'             => __( 'CUIT or DNI (optional, for the invoice)', 'fiskit-for-woocommerce' ),
				'optionalLabel'     => __( 'CUIT or DNI (optional, for the invoice)', 'fiskit-for-woocommerce' ),
				'location'          => 'order',
				'type'              => 'text',
				'required'          => false,
				'validate_callback' => function ( $value ) {
					$message = '' === trim( (string) $value ) ? null : Fiskit_Orders::document_error( $value );
					return $message ? new WP_Error( 'fiskit_document', $message ) : true;
				},
			)
		);
	}

	/**
	 * Why a typed document cannot be used, or null if it can.
	 *
	 * @param string $raw As typed.
	 */
	public static function document_error( $raw ) {
		$parsed = Fiskit_Invoice_Builder::parse_document( $raw );
		if ( ! $parsed ) {
			return __( 'Enter a CUIT (11 digits) or a DNI (7 or 8 digits).', 'fiskit-for-woocommerce' );
		}
		if ( 'cuit' === $parsed['type'] && ! Fiskit_Invoice_Builder::valid_cuit( $raw ) ) {
			return __( 'The CUIT is not valid: check its digits.', 'fiskit-for-woocommerce' );
		}
		return null;
	}

	private function document_of( WC_Order $order ) {
		foreach ( array( self::META_DOCUMENT, self::META_BLOCK_DOC ) as $key ) {
			$value = $order->get_meta( $key );
			if ( $value ) {
				return (string) $value;
			}
		}
		return '';
	}

	// ---------------------------------------------------------------- Helpers

	/**
	 * "Invoice C 0001-00000042", or the voucher type while it has no number.
	 *
	 * @param WC_Abstract_Order $document Order or refund with a voucher.
	 */
	public static function voucher_label( $document ) {
		$type   = (string) $document->get_meta( self::META_TYPE );
		$letter = strtoupper( substr( $type, -1 ) );
		$names  = array(
			/* translators: %s: letter A, B or C. */
			'invoice'     => __( 'Invoice %s', 'fiskit-for-woocommerce' ),
			/* translators: %s: letter A, B or C. */
			'credit_note' => __( 'Credit note %s', 'fiskit-for-woocommerce' ),
			/* translators: %s: letter A, B or C. */
			'debit_note'  => __( 'Debit note %s', 'fiskit-for-woocommerce' ),
		);
		$kind   = substr( $type, 0, -2 );
		$label  = sprintf( isset( $names[ $kind ] ) ? $names[ $kind ] : '%s', $letter );
		$number = $document->get_meta( self::META_NUMBER );
		if ( '' !== (string) $number ) {
			$label .= sprintf( ' %04d-%08d', (int) $document->get_meta( self::META_POS ), (int) $number );
		}
		return $label;
	}

	private function active() {
		return 'yes' === $this->settings->get_option( 'enabled' ) && '' !== (string) $this->settings->get_option( 'api_key' );
	}

	private function api() {
		return new Fiskit_Api( $this->settings->get_option( 'api_key' ) );
	}

	private function builder() {
		return new Fiskit_Invoice_Builder( $this->settings->builder_settings() );
	}

	/**
	 * The refund's notes go on its order, where the shop owner reads them.
	 */
	private function note_target( WC_Abstract_Order $document ) {
		if ( $document instanceof WC_Order_Refund ) {
			$parent = wc_get_order( $document->get_parent_id() );
			if ( $parent ) {
				return $parent;
			}
		}
		return $document;
	}

	/**
	 * Unique per store and document, so two stores with the same FisKit account never collide.
	 */
	private function idempotency_key( $kind, $id ) {
		return 'wc-' . substr( md5( home_url() ), 0, 10 ) . '-' . $kind . '-' . $id;
	}

	/**
	 * Today in Argentina: the voucher is dated the day it is issued, and ARCA only accepts dates close
	 * to that day, so the order's own date (maybe days earlier) is not used.
	 */
	private function date( WC_Abstract_Order $document ) {
		unset( $document );
		return ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/Argentina/Buenos_Aires' ) ) )->format( 'Y-m-d' );
	}

	private function check_delay( $attempt ) {
		return min( 60, 2 + 3 * $attempt ) * MINUTE_IN_SECONDS;
	}

	private function schedule( $hook, array $args, $delay = 0 ) {
		if ( as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
			return;
		}
		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP );
		} else {
			as_enqueue_async_action( $hook, $args, self::GROUP );
		}
	}
}
