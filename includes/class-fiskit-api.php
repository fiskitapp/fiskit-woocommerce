<?php
/**
 * Minimal FisKit API client on top of the WordPress HTTP API.
 *
 * @package Fiskit_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the FisKit API. Every method returns the decoded response or a WP_Error whose code is the
 * API error code (parameter_invalid, invoice_rejected...) or "network".
 */
class Fiskit_Api {

	const DEFAULT_BASE_URL = 'https://api.fiskit.com.ar';

	/** @var string */
	private $api_key;

	/** @var string */
	private $base_url;

	/**
	 * @param string $api_key sk_test_... or sk_live_...
	 */
	public function __construct( $api_key ) {
		$this->api_key = (string) $api_key;
		/**
		 * Filters the API address, for example to point it to a mock while testing.
		 *
		 * @param string $base_url https://api.fiskit.com.ar
		 */
		$this->base_url = rtrim( apply_filters( 'fiskit_woocommerce_api_base_url', self::DEFAULT_BASE_URL ), '/' );
	}

	/**
	 * Whether the key issues real invoices.
	 */
	public function is_live() {
		return 0 === strpos( $this->api_key, 'sk_live_' );
	}

	/**
	 * Issues a voucher. Repeating the call with the same key and body returns the same invoice.
	 *
	 * @param array  $body            Request body.
	 * @param string $idempotency_key Unique per order or refund.
	 * @return array|WP_Error The invoice; its status may be pending (finished in the background).
	 */
	public function create_invoice( array $body, $idempotency_key ) {
		return $this->request( 'POST', '/v1/invoices', $body, $idempotency_key );
	}

	/**
	 * @param string $id Invoice id.
	 * @return array|WP_Error
	 */
	public function get_invoice( $id ) {
		return $this->request( 'GET', '/v1/invoices/' . rawurlencode( $id ) );
	}

	/**
	 * @param string $id Invoice id.
	 * @return string|WP_Error The PDF bytes.
	 */
	public function get_pdf( $id ) {
		return $this->request( 'GET', '/v1/invoices/' . rawurlencode( $id ) . '/pdf', null, null, true );
	}

	/**
	 * @param int $cuit 11 digits.
	 * @return array|WP_Error Name, address and receiver_vat_condition_id of the CUIT.
	 */
	public function get_taxpayer( $cuit ) {
		return $this->request( 'GET', '/v1/taxpayers/' . rawurlencode( (string) $cuit ) );
	}

	/**
	 * Checks the key against the API without issuing anything: looking up an invoice that cannot exist
	 * answers 404 to a valid key and 401 to an invalid one.
	 *
	 * @return true|WP_Error
	 */
	public function check_key() {
		$result = $this->get_invoice( '00000000-0000-0000-0000-000000000000' );
		if ( is_wp_error( $result ) && 'resource_missing' !== $result->get_error_code() ) {
			return $result;
		}
		return true;
	}

	private function request( $method, $path, $body = null, $idempotency_key = null, $binary = false ) {
		$headers = array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Accept'        => $binary ? 'application/pdf' : 'application/json',
			'User-Agent'    => 'fiskit-woocommerce/' . FISKIT_WC_VERSION . '; ' . home_url(),
		);
		$args    = array(
			'method'  => $method,
			'timeout' => 60,
			'headers' => $headers,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		if ( $idempotency_key ) {
			$args['headers']['Idempotency-Key'] = $idempotency_key;
		}

		$response = wp_remote_request( $this->base_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'network', $response->get_error_message(), array( 'status' => 0 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		if ( $status >= 200 && $status < 300 ) {
			return $binary ? $raw : json_decode( $raw, true );
		}

		$payload = json_decode( $raw, true );
		$error   = isset( $payload['error'] ) && is_array( $payload['error'] ) ? $payload['error'] : array();
		return new WP_Error(
			isset( $error['code'] ) ? $error['code'] : 'http_' . $status,
			isset( $error['message'] ) ? $error['message'] : 'HTTP ' . $status,
			array(
				'status'           => $status,
				'param'            => isset( $error['param'] ) ? $error['param'] : null,
				'request_id'       => isset( $error['request_id'] ) ? $error['request_id'] : null,
				'authority_errors' => isset( $error['authority_errors'] ) ? $error['authority_errors'] : array(),
			)
		);
	}

	/**
	 * Whether a failure is temporary (ARCA or the network) and the same call can be repeated later.
	 *
	 * @param WP_Error $error From any method.
	 */
	public static function is_temporary( WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = isset( $data['status'] ) ? (int) $data['status'] : 0;
		return 0 === $status || 408 === $status || 429 === $status || $status >= 500;
	}
}
