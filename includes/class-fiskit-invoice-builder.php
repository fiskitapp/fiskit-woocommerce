<?php
/**
 * Builds the FisKit request bodies from plain order data. It does not touch WordPress, so the rules
 * (which voucher, which receiver, which amounts) are tested on their own.
 *
 * @package Fiskit_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order data in, request body out.
 */
class Fiskit_Invoice_Builder {

	const ISSUER_MONOTRIBUTO = 'monotributo';
	const ISSUER_REGISTERED  = 'registered';

	/** VAT condition ids (RG 5616). */
	const VAT_REGISTERED     = 1;
	const VAT_FINAL_CONSUMER = 5;

	/**
	 * Settings: issuer (monotributo|registered), vat_rate ("21"), concept (products|services|products_and_services),
	 * point_of_sale (int|null) and send_email (bool).
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * @param array $settings See $settings.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings + array(
			'issuer'        => self::ISSUER_MONOTRIBUTO,
			'vat_rate'      => '21',
			'concept'       => 'products',
			'point_of_sale' => null,
			'send_email'    => true,
		);
	}

	/**
	 * Reads the document the customer typed at checkout: 11 digits is a CUIT, 7 or 8 a DNI.
	 *
	 * @param string $raw As typed, with or without dashes, dots or spaces.
	 * @return array|null array( 'type' => 'cuit'|'dni', 'number' => int ), or null when there is none.
	 */
	public static function parse_document( $raw ) {
		$digits = preg_replace( '/\D/', '', (string) $raw );
		if ( 11 === strlen( $digits ) ) {
			return array( 'type' => 'cuit', 'number' => (int) $digits );
		}
		if ( strlen( $digits ) >= 7 && strlen( $digits ) <= 8 ) {
			return array( 'type' => 'dni', 'number' => (int) $digits );
		}
		return null;
	}

	/**
	 * Whether the document is a valid CUIT (its check digit matches).
	 *
	 * @param string $raw As typed.
	 */
	public static function valid_cuit( $raw ) {
		$digits = preg_replace( '/\D/', '', (string) $raw );
		if ( 11 !== strlen( $digits ) ) {
			return false;
		}
		$weights = array( 5, 4, 3, 2, 7, 6, 5, 4, 3, 2 );
		$sum     = 0;
		foreach ( $weights as $i => $weight ) {
			$sum += (int) $digits[ $i ] * $weight;
		}
		$check = 11 - ( $sum % 11 );
		$check = 11 === $check ? 0 : ( 10 === $check ? 9 : $check );
		return (int) $digits[10] === $check;
	}

	/**
	 * The invoice of an order.
	 *
	 * @param array $order id, number, date (Y-m-d), total, name, email, address, document (raw string),
	 *                     receiver_vat_condition_id (from the registry, when the document is a CUIT) and
	 *                     lines: array of array( 'description', 'quantity', 'total' ).
	 * @return array Request body for POST /v1/invoices.
	 */
	public function invoice( array $order ) {
		$document  = self::parse_document( isset( $order['document'] ) ? $order['document'] : '' );
		$condition = isset( $order['receiver_vat_condition_id'] ) ? (int) $order['receiver_vat_condition_id'] : null;

		$letter = 'c';
		if ( self::ISSUER_REGISTERED === $this->settings['issuer'] ) {
			$letter = ( $document && 'cuit' === $document['type'] && self::VAT_REGISTERED === $condition ) ? 'a' : 'b';
		}

		$body = array(
			'voucher_type' => 'invoice_' . $letter,
			'concept'      => $this->settings['concept'],
			'amounts'      => $this->amounts( $order['total'], $letter ),
			'receiver'     => $this->receiver( $order, $document, $condition ),
			'items'        => $this->items( $order['lines'] ),
			/* translators: %s: order number. */
			'notes'        => sprintf( __( 'Order #%s', 'fiskit-for-woocommerce' ), $order['number'] ),
		);
		return $this->finish( $body, $order['date'] );
	}

	/**
	 * The credit note that returns (part of) an invoice.
	 *
	 * @param array $invoice What the invoice stored on the order: voucher_type, point_of_sale, number and receiver.
	 * @param array $refund  id, date (Y-m-d), amount and reason.
	 * @return array Request body for POST /v1/invoices.
	 */
	public function credit_note( array $invoice, array $refund ) {
		$letter = substr( $invoice['voucher_type'], -1 );
		$body   = array(
			'voucher_type'        => 'credit_note_' . $letter,
			'concept'             => $this->settings['concept'],
			'amounts'             => $this->amounts( $refund['amount'], $letter ),
			'receiver'            => $invoice['receiver'],
			'associated_vouchers' => array(
				array(
					'voucher_type'  => $invoice['voucher_type'],
					'point_of_sale' => (int) $invoice['point_of_sale'],
					'number'        => (int) $invoice['number'],
				),
			),
			'notes'               => '' !== (string) $refund['reason']
				/* translators: %s: refund reason. */
				? sprintf( __( 'Refund: %s', 'fiskit-for-woocommerce' ), $refund['reason'] )
				: __( 'Refund', 'fiskit-for-woocommerce' ),
		);
		return $this->finish( $body, $refund['date'] );
	}

	/**
	 * The voucher type a credit note for this invoice would have.
	 *
	 * @param string $voucher_type For example invoice_b.
	 */
	public static function credit_note_type( $voucher_type ) {
		return 'credit_note_' . substr( $voucher_type, -1 );
	}

	private function amounts( $total, $letter ) {
		$amounts = array( 'total' => round( (float) $total, 2 ) );
		if ( 'c' !== $letter ) {
			$amounts['vat_rate'] = (string) $this->settings['vat_rate'];
		}
		return $amounts;
	}

	private function receiver( array $order, $document, $condition ) {
		$receiver = array();
		if ( $document ) {
			$receiver['document_type']    = $document['type'];
			$receiver['document_number']  = $document['number'];
			$receiver['vat_condition_id'] = $condition ? $condition : self::VAT_FINAL_CONSUMER;
		}
		foreach ( array( 'name', 'address' ) as $field ) {
			if ( ! empty( $order[ $field ] ) ) {
				$receiver[ $field ] = mb_substr( trim( $order[ $field ] ), 0, 200 );
			}
		}
		if ( $this->settings['send_email'] && ! empty( $order['email'] ) ) {
			$receiver['email'] = $order['email'];
		}
		return $receiver;
	}

	private function items( array $lines ) {
		$items = array();
		foreach ( $lines as $line ) {
			$quantity = max( 1, (float) $line['quantity'] );
			$items[]  = array(
				'description' => mb_substr( trim( $line['description'] ), 0, 200 ),
				'quantity'    => $quantity,
				'unit_price'  => round( (float) $line['total'] / $quantity, 2 ),
			);
		}
		return $items;
	}

	/**
	 * Adds what depends on the settings and the concept, and drops empty values.
	 */
	private function finish( array $body, $date ) {
		if ( ! empty( $this->settings['point_of_sale'] ) ) {
			$body['point_of_sale'] = (int) $this->settings['point_of_sale'];
		}
		if ( 'products' !== $this->settings['concept'] ) {
			// Services are billed for the day of the sale, payable that same day
			$body['service_period'] = array(
				'from'             => $date,
				'to'               => $date,
				'payment_due_date' => $date,
			);
		}
		return array_filter(
			$body,
			function ( $value ) {
				return array() !== $value && null !== $value && '' !== $value;
			}
		);
	}
}
