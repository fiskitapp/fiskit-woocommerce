<?php
/**
 * Plugin Name:          FisKit for WooCommerce
 * Plugin URI:           https://fiskit.com.ar
 * Description:          Issues the ARCA (ex AFIP) electronic invoice of every paid order and a credit note for every refund. The customer gets the PDF by email.
 * Version:              0.1.1
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 * Author:               FisKit
 * Author URI:           https://fiskit.com.ar
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Text Domain:          fiskit-for-woocommerce
 * Domain Path:          /languages
 *
 * @package Fiskit_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'FISKIT_WC_VERSION', '0.1.1' );
define( 'FISKIT_WC_FILE', __FILE__ );

// Compatible with High-Performance Order Storage and with the block checkout
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FISKIT_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', FISKIT_WC_FILE, true );
		}
	}
);

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'fiskit-for-woocommerce', false, dirname( plugin_basename( FISKIT_WC_FILE ) ) . '/languages' );
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Integration' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'FisKit for WooCommerce needs WooCommerce to be active.', 'fiskit-for-woocommerce' ) . '</p></div>';
				}
			);
			return;
		}

		require_once __DIR__ . '/includes/class-fiskit-invoice-builder.php';
		require_once __DIR__ . '/includes/class-fiskit-api.php';
		require_once __DIR__ . '/includes/class-fiskit-integration.php';
		require_once __DIR__ . '/includes/class-fiskit-orders.php';

		add_filter(
			'woocommerce_integrations',
			function ( $integrations ) {
				$integrations[] = 'Fiskit_Integration';
				return $integrations;
			}
		);

		// WooCommerce builds the integrations on init; the hooks need the settings it loaded
		add_action(
			'woocommerce_init',
			function () {
				$integrations = WC()->integrations ? WC()->integrations->get_integrations() : array();
				if ( isset( $integrations['fiskit'] ) ) {
					( new Fiskit_Orders( $integrations['fiskit'] ) )->register();
				}
			},
			5
		);

		add_filter(
			'plugin_action_links_' . plugin_basename( FISKIT_WC_FILE ),
			function ( $links ) {
				$url = admin_url( 'admin.php?page=wc-settings&tab=integration&section=fiskit' );
				array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'fiskit-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
