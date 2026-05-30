<?php
/**
 * WCML order language and WordPress locale switching for invoice generation.
 *
 * Duplicated from SailCom Order_Language so this plugin does not depend on SailCom.
 *
 * @package RunmyAccountsforWooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order locale helper for RMA invoice strings.
 */
class RMA_WC_Order_Language {

	/**
	 * WCML order language meta key.
	 */
	private const ORDER_LANG_META = 'wpml_language';

	/**
	 * Read language code stored on the order (WCML), with sensible fallbacks.
	 *
	 * @param WC_Order $order Order.
	 * @return string Language code, e.g. en, de, fr.
	 */
	public static function get_order_language( WC_Order $order ): string {
		$lang = $order->get_meta( self::ORDER_LANG_META );
		if ( is_string( $lang ) && '' !== $lang ) {
			return $lang;
		}

		$current = apply_filters( 'wpml_current_language', null );
		if ( is_string( $current ) && '' !== $current ) {
			return $current;
		}

		return substr( get_locale(), 0, 2 ) ?: 'en';
	}

	/**
	 * Run a callback under the order's WPML language and WordPress locale.
	 *
	 * @param WC_Order $order    Order.
	 * @param callable $callback Callback returning any value.
	 * @return mixed Callback return value.
	 */
	public static function with_order_locale( WC_Order $order, callable $callback ) {
		$lang            = self::get_order_language( $order );
		$target_locale   = self::language_to_locale( $lang );
		$previous_lang   = apply_filters( 'wpml_current_language', null );
		$previous_locale = get_locale();
		$switched_locale = false;

		do_action( 'wpml_switch_language', $lang );

		if ( $target_locale && $target_locale !== $previous_locale && function_exists( 'switch_to_locale' ) ) {
			switch_to_locale( $target_locale );
			$switched_locale = true;
		}

		try {
			return $callback();
		} finally {
			if ( is_string( $previous_lang ) && '' !== $previous_lang && $previous_lang !== $lang ) {
				do_action( 'wpml_switch_language', $previous_lang );
			}
			if ( $switched_locale && function_exists( 'restore_previous_locale' ) ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Collective invoice option for a language, falling back to English.
	 *
	 * @param string $field One of title, description, footer.
	 * @param string $lang  Language code.
	 * @return string
	 */
	public static function collective_invoice_option( string $field, string $lang ): string {
		$value = get_option( 'rma_invoice_' . $field . '_' . $lang, '' );
		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}

		return (string) get_option( 'rma_invoice_' . $field . '_en', '' );
	}

	/**
	 * @param string $lang WPML language code.
	 * @return string WordPress locale.
	 */
	private static function language_to_locale( string $lang ): string {
		$locale = apply_filters( 'wpml_locale', null, $lang );
		if ( is_string( $locale ) && '' !== $locale ) {
			return $locale;
		}

		$map = array(
			'en' => 'en_US',
			'de' => 'de_DE',
			'fr' => 'fr_FR',
		);

		return $map[ $lang ] ?? $lang;
	}
}
