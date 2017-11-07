<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'kco_wc_show_snippet' ) ) {
	/**
	 * Echoes Klarna Checkout iframe snippet.
	 */
	function kco_wc_show_snippet() {
		$klarna_order = KCO_WC()->api->get_order();
		echo KCO_WC()->api->get_snippet( $klarna_order );
	}
}

if ( ! function_exists( 'kco_wc_show_order_notes' ) ) {
	/**
	 * Shows order notes field in Klarna Checkout page.
	 */
	function kco_wc_show_order_notes() {
		$order_fields = WC()->checkout()->get_checkout_fields( 'order' );
		$key = 'order_comments';
		if ( array_key_exists( $key, $order_fields ) ) {
			$order_notes_field = $order_fields[ $key ];
			woocommerce_form_field( $key, $order_notes_field, WC()->checkout()->get_value( $key ) );
		}
	}
}

if ( ! function_exists( 'kco_wc_show_extra_fields' ) ) {
	/**
	 * Shows extra fields in Klarna Checkout page.
	 */
	function kco_wc_show_extra_fields() {
		// Clear extra fields session values on reload.
		// WC()->session->__unset( 'kco_wc_extra_fields_values' );

		echo '<div id="kco-extra-fields">';

		$extra_fields_values          = WC()->session->get( 'kco_wc_extra_fields_values', array() );
		$kco_wc_extra_checkout_fields = new Klarna_Checkout_For_WooCommerce_Extra_Checkout_Fields;
		$extra_fields                 = $kco_wc_extra_checkout_fields->get_remaining_checkout_fields();

		// Billing.
		foreach ( $extra_fields['billing'] as $key => $field ) {
			if ( isset( $field['country_field'], $default_billing_fields[ $field['country_field'] ] ) ) {
				$field['country'] = WC()->checkout()->get_value( $field['country_field'] );
			}
			$key_value = array_key_exists( $key, $extra_fields_values ) ? $extra_fields_values[ $key ] : '';
			woocommerce_form_field( $key, $field, $key_value );
		}

		// Account.
		foreach ( $extra_fields['account'] as $key => $field ) {
			$key_value = array_key_exists( $key, $extra_fields_values ) ? $extra_fields_values[ $key ] : '';
			woocommerce_form_field( $key, $field, $key_value );
		}

		// Shipping.
		foreach ( $extra_fields['shipping'] as $key => $field ) {
			if ( isset( $field['country_field'], $default_shipping_fields[ $field['country_field'] ] ) ) {
				$field['country'] = WC()->checkout()->get_value( $field['country_field'] );
			}
			$key_value = array_key_exists( $key, $extra_fields_values ) ? $extra_fields_values[ $key ] : '';
			woocommerce_form_field( $key, $field, $key_value );
		}

		// Order.
		foreach ( $extra_fields['order'] as $key => $field ) {
			$key_value = array_key_exists( $key, $extra_fields_values ) ? $extra_fields_values[ $key ] : '';
			woocommerce_form_field( $key, $field, $key_value );
		}

		echo '</div>';
	}
}
