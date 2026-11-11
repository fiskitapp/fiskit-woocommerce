<?php
/**
 * Rules of the invoice builder, without WordPress.
 *
 * @package Fiskit_WooCommerce
 */

use PHPUnit\Framework\TestCase;

final class InvoiceBuilderTest extends TestCase {

	private function order( array $overrides = array() ) {
		return $overrides + array(
			'number'                    => '1005',
			'date'                      => '2026-11-11',
			'total'                     => 12100,
			'name'                      => 'Juan Pérez',
			'email'                     => 'juan@example.com',
			'address'                   => 'Av. Corrientes 1234, CABA',
			'document'                  => '',
			'receiver_vat_condition_id' => null,
			'lines'                     => array(
				array(
					'description' => 'Remera',
					'quantity'    => 2,
					'total'       => 10000,
				),
				array(
					'description' => 'Envío',
					'quantity'    => 1,
					'total'       => 2100,
				),
			),
		);
	}

	public function test_monotributista_issues_invoice_c_with_the_total_only() {
		$body = ( new Fiskit_Invoice_Builder( array( 'issuer' => 'monotributo' ) ) )->invoice( $this->order() );

		$this->assertSame( 'invoice_c', $body['voucher_type'] );
		$this->assertSame( array( 'total' => 12100.0 ), $body['amounts'] );
		$this->assertSame( 'products', $body['concept'] );
		$this->assertArrayNotHasKey( 'document_type', $body['receiver'] );
		$this->assertSame( 'juan@example.com', $body['receiver']['email'] );
		$this->assertSame( array( 'description' => 'Remera', 'quantity' => 2.0, 'unit_price' => 5000.0 ), $body['items'][0] );
		$this->assertSame( 'Order #1005', $body['notes'] );
		$this->assertArrayNotHasKey( 'point_of_sale', $body );
	}

	public function test_registered_issuer_gives_invoice_a_to_a_registered_cuit() {
		$builder = new Fiskit_Invoice_Builder( array( 'issuer' => 'registered', 'vat_rate' => '21' ) );
		$body    = $builder->invoice( $this->order( array( 'document' => '30-71234567-1', 'receiver_vat_condition_id' => 1 ) ) );

		$this->assertSame( 'invoice_a', $body['voucher_type'] );
		$this->assertSame( array( 'total' => 12100.0, 'vat_rate' => '21' ), $body['amounts'] );
		$this->assertSame( 'cuit', $body['receiver']['document_type'] );
		$this->assertSame( 30712345671, $body['receiver']['document_number'] );
		$this->assertSame( 1, $body['receiver']['vat_condition_id'] );
	}

	public function test_registered_issuer_gives_invoice_b_to_everyone_else() {
		$builder = new Fiskit_Invoice_Builder( array( 'issuer' => 'registered' ) );

		$monotributista = $builder->invoice( $this->order( array( 'document' => '20301234567', 'receiver_vat_condition_id' => 6 ) ) );
		$this->assertSame( 'invoice_b', $monotributista['voucher_type'] );
		$this->assertSame( 6, $monotributista['receiver']['vat_condition_id'] );

		$with_dni = $builder->invoice( $this->order( array( 'document' => '30.123.456' ) ) );
		$this->assertSame( 'invoice_b', $with_dni['voucher_type'] );
		$this->assertSame( 'dni', $with_dni['receiver']['document_type'] );
		$this->assertSame( 5, $with_dni['receiver']['vat_condition_id'] );

		$anonymous = $builder->invoice( $this->order() );
		$this->assertSame( 'invoice_b', $anonymous['voucher_type'] );
		$this->assertArrayNotHasKey( 'document_type', $anonymous['receiver'] );
	}

	public function test_services_carry_the_period_and_the_point_of_sale_is_optional() {
		$builder = new Fiskit_Invoice_Builder( array( 'concept' => 'services', 'point_of_sale' => 3 ) );
		$body    = $builder->invoice( $this->order() );

		$this->assertSame( 'services', $body['concept'] );
		$this->assertSame( array( 'from' => '2026-11-11', 'to' => '2026-11-11', 'payment_due_date' => '2026-11-11' ), $body['service_period'] );
		$this->assertSame( 3, $body['point_of_sale'] );
	}

	public function test_without_email_setting_the_pdf_is_not_sent() {
		$body = ( new Fiskit_Invoice_Builder( array( 'send_email' => false ) ) )->invoice( $this->order() );
		$this->assertArrayNotHasKey( 'email', $body['receiver'] );
	}

	public function test_credit_note_references_the_invoice_with_the_same_letter_and_receiver() {
		$builder = new Fiskit_Invoice_Builder( array( 'issuer' => 'registered', 'vat_rate' => '10.5' ) );
		$body    = $builder->credit_note(
			array(
				'voucher_type'  => 'invoice_b',
				'point_of_sale' => '2',
				'number'        => '42',
				'receiver'      => array( 'document_type' => 'dni', 'document_number' => 30123456, 'vat_condition_id' => 5 ),
			),
			array( 'amount' => 500, 'date' => '2026-11-12', 'reason' => 'Talle equivocado' )
		);

		$this->assertSame( 'credit_note_b', $body['voucher_type'] );
		$this->assertSame( array( 'total' => 500.0, 'vat_rate' => '10.5' ), $body['amounts'] );
		$this->assertSame( array( array( 'voucher_type' => 'invoice_b', 'point_of_sale' => 2, 'number' => 42 ) ), $body['associated_vouchers'] );
		$this->assertSame( 'dni', $body['receiver']['document_type'] );
		$this->assertSame( 'Refund: Talle equivocado', $body['notes'] );
	}

	public function test_documents_are_read_and_cuits_checked() {
		$this->assertSame( array( 'type' => 'cuit', 'number' => 20301234567 ), Fiskit_Invoice_Builder::parse_document( '20-30123456-7' ) );
		$this->assertSame( array( 'type' => 'dni', 'number' => 1234567 ), Fiskit_Invoice_Builder::parse_document( '1.234.567' ) );
		$this->assertNull( Fiskit_Invoice_Builder::parse_document( '123' ) );
		$this->assertNull( Fiskit_Invoice_Builder::parse_document( '' ) );

		$this->assertTrue( Fiskit_Invoice_Builder::valid_cuit( '30-71234567-1' ) );
		$this->assertTrue( Fiskit_Invoice_Builder::valid_cuit( '20-47898454-6' ) );
		$this->assertFalse( Fiskit_Invoice_Builder::valid_cuit( '30-71234567-2' ) );
		$this->assertFalse( Fiskit_Invoice_Builder::valid_cuit( '1234' ) );
	}
}
