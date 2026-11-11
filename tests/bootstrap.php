<?php
/**
 * Loads the WordPress-independent classes with the few WordPress functions they use.
 *
 * @package Fiskit_WooCommerce
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}

require_once __DIR__ . '/../includes/class-fiskit-invoice-builder.php';
