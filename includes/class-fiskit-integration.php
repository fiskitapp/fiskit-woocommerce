<?php
/**
 * Settings screen: WooCommerce → Settings → Integration → FisKit.
 *
 * @package Fiskit_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plugin settings, stored by WooCommerce as woocommerce_fiskit_settings.
 */
class Fiskit_Integration extends WC_Integration {

	public function __construct() {
		$this->id                 = 'fiskit';
		$this->method_title       = 'FisKit';
		$this->method_description = __( 'Issues the ARCA electronic invoice of every paid order, and a credit note for every refund. Create your key in the FisKit dashboard, under API Keys.', 'fiskit-for-woocommerce' );

		$this->init_form_fields();
		$this->init_settings();

		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'       => array(
				'title'   => __( 'Invoicing', 'fiskit-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Issue the invoices automatically', 'fiskit-for-woocommerce' ),
				'default' => 'yes',
			),
			'api_key'       => array(
				'title'       => __( 'API key', 'fiskit-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'sk_test_... issues test invoices without fiscal validity; sk_live_... issues real invoices. Start with a test key.', 'fiskit-for-woocommerce' ),
				'default'     => '',
			),
			'issuer'        => array(
				'title'       => __( 'Your tax status', 'fiskit-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					Fiskit_Invoice_Builder::ISSUER_MONOTRIBUTO => __( 'Monotributista: Invoice C', 'fiskit-for-woocommerce' ),
					Fiskit_Invoice_Builder::ISSUER_REGISTERED  => __( 'Registered VAT taxpayer: Invoice A or B', 'fiskit-for-woocommerce' ),
				),
				'description' => __( 'As a registered VAT taxpayer, customers who enter the CUIT of another registered taxpayer get Invoice A; everyone else gets Invoice B.', 'fiskit-for-woocommerce' ),
				'default'     => Fiskit_Invoice_Builder::ISSUER_MONOTRIBUTO,
			),
			'vat_rate'      => array(
				'title'       => __( 'VAT rate', 'fiskit-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					'21'   => '21%',
					'10.5' => '10.5%',
					'27'   => '27%',
					'5'    => '5%',
					'2.5'  => '2.5%',
					'0'    => '0%',
				),
				'description' => __( 'Only for Invoices A and B: the order total includes it.', 'fiskit-for-woocommerce' ),
				'default'     => '21',
			),
			'concept'       => array(
				'title'   => __( 'You sell', 'fiskit-for-woocommerce' ),
				'type'    => 'select',
				'options' => array(
					'products'              => __( 'Products', 'fiskit-for-woocommerce' ),
					'services'              => __( 'Services', 'fiskit-for-woocommerce' ),
					'products_and_services' => __( 'Products and services', 'fiskit-for-woocommerce' ),
				),
				'default' => 'products',
			),
			'point_of_sale' => array(
				'title'             => __( 'Point of sale', 'fiskit-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'Empty: the one set in the FisKit dashboard.', 'fiskit-for-woocommerce' ),
				'default'           => '',
				'custom_attributes' => array( 'min' => 1 ),
			),
			'trigger'       => array(
				'title'       => __( 'Invoice when the order is', 'fiskit-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					'processing' => __( 'Paid (Processing)', 'fiskit-for-woocommerce' ),
					'completed'  => __( 'Completed', 'fiskit-for-woocommerce' ),
				),
				'default'     => 'processing',
			),
			'send_email'    => array(
				'title'   => __( 'Email', 'fiskit-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Email the invoice PDF to the customer', 'fiskit-for-woocommerce' ),
				'default' => 'yes',
			),
			'document'      => array(
				'title'   => __( 'Checkout', 'fiskit-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Ask for the CUIT or DNI (optional for the customer)', 'fiskit-for-woocommerce' ),
				'default' => 'yes',
			),
			'refunds'       => array(
				'title'   => __( 'Refunds', 'fiskit-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Issue a credit note for every refund', 'fiskit-for-woocommerce' ),
				'default' => 'yes',
			),
		);
	}

	/**
	 * Only keys with the FisKit format are saved, so a typo is caught here and not at the first order.
	 *
	 * @param string $key   Field key.
	 * @param string $value Submitted value.
	 */
	public function validate_api_key_field( $key, $value ) {
		$value = trim( (string) $value );
		if ( '' !== $value && ! preg_match( '/^sk_(test|live)_[A-Za-z0-9_-]{8,}$/', $value ) ) {
			WC_Admin_Settings::add_error( __( 'The API key must start with sk_test_ or sk_live_.', 'fiskit-for-woocommerce' ) );
			return $this->get_option( 'api_key' );
		}
		return $value;
	}

	/**
	 * The settings in the shape Fiskit_Invoice_Builder expects.
	 */
	public function builder_settings() {
		return array(
			'issuer'        => $this->get_option( 'issuer' ),
			'vat_rate'      => $this->get_option( 'vat_rate' ),
			'concept'       => $this->get_option( 'concept' ),
			'point_of_sale' => $this->get_option( 'point_of_sale' ) ? (int) $this->get_option( 'point_of_sale' ) : null,
			'send_email'    => 'yes' === $this->get_option( 'send_email' ),
		);
	}
}
