<?php
/**
 * Plugin Name: LeanCart Bulk Price Changer
 * Plugin URI:  https://findsleepdoctors.com/
 * Description: Apply bulk price increases or decreases by category or across all products. Offers percentage or fixed-amount options, a preview, and one-click application.
 * Version:     1.0.0
 * Author:      LeanCart
 * Author URI:  https://leancart.dev
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bulk-price-changer
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'LEANCART_BULK_PRICE_CHANGER_DEMO_SITE' ) ) {
	define( 'LEANCART_BULK_PRICE_CHANGER_DEMO_SITE', 'https://findsleepdoctors.com' );
}

require_once __DIR__ . '/includes/class-leancart-framework.php';

final class LeanCart_Bulk_Price_Changer {

	const SLUG = 'bulk-price-changer';

	public static function init() {
		LeanCart_Framework::boot( array(
			'slug'    => self::SLUG,
			'name'    => 'LeanCart Bulk Price Changer',
			'version' => '1.0.0',
			'file'    => __FILE__,
			'site'    => LEANCART_BULK_PRICE_CHANGER_DEMO_SITE,
		) );

		// WooCommerce may load after these plugins; defer the check to runtime.
		add_action( 'admin_notices', array( __CLASS__, 'wc_notice' ) );

		load_plugin_textdomain( 'bulk-price-changer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		add_action( 'leancart_' . self::SLUG . '_settings_fields', array( __CLASS__, 'fields' ) );

		// WooCommerce may load after this plugin; hooks are registered once WC is ready.
		if ( LeanCart_Framework::is_wc() ) {
			self::hooks();
		} else {
			add_action( 'woocommerce_loaded', array( __CLASS__, 'hooks' ) );
		}
	}

	public static function wc_notice() {
		if ( LeanCart_Framework::is_wc() ) { return; }
		echo '<div class="notice notice-warning"><p><strong>' . esc_html( 'LeanCart Bulk Price Changer' ) . '</strong>: ';
		esc_html_e( 'This plugin requires WooCommerce to be active.', 'bulk-price-changer' );
		echo '</p></div>';
	}

	public static function fields() {
		LeanCart_Framework::field_checkbox( 'backup', __('Create backup', 'bulk-price-changer'), __('Store old prices as metadata before making changes.', 'bulk-price-changer') );
	}


	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_page' ) );
	}

	public static function admin_page() {
		add_submenu_page( 'woocommerce', __( 'Bulk price', 'bulk-price-changer' ), __( 'Bulk price', 'bulk-price-changer' ), 'manage_woocommerce', 'lc-bulk-price', array( __CLASS__, 'render' ) );
	}

	private static function products( $cat ) {
		$args = array( 'limit' => -1, 'status' => 'publish' );
		if ( $cat ) { $args['product_category_id'] = array( $cat ); }
		return wc_get_products( $args );
	}

	private static function change_price( $product, $mode, $value, $backup ) {
		$changed = false;
		foreach ( array( 'regular', 'sale' ) as $type ) {
			$getter = 'get_' . $type . '_price';
			$setter = 'set_' . $type . '_price';
			$old = (float) $product->$getter();
			if ( $old <= 0 ) { continue; }
			if ( $backup ) { update_post_meta( $product->get_id(), '_lc_old_' . $type . '_price', $old ); }
			$new = 'percent' === $mode ? $old * ( 1 + $value / 100 ) : $old + $value;
			$product->$setter( max( 0, round( $new, 2 ) ) );
			$changed = true;
		}
		if ( $changed ) { $product->save(); }
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die(); }
		$done = 0;
		if ( isset( $_POST['lc_apply'] ) || isset( $_POST['lc_rollback'] ) ) {
			if ( ! check_admin_referer( 'lc_bulk_price', '_lc_nonce' ) ) { return; }
			if ( isset( $_POST['lc_rollback'] ) ) {
				$ids = get_posts( array( 'post_type' => array( 'product', 'product_variation' ), 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1, 'meta_key' => '_lc_old_regular_price' ) );
				foreach ( $ids as $pid ) {
					$product = wc_get_product( $pid );
					if ( ! $product ) { continue; }
					$old = get_post_meta( $pid, '_lc_old_regular_price', true );
					if ( '' !== $old ) { $product->set_regular_price( $old ); }
					$sale = get_post_meta( $pid, '_lc_old_sale_price', true );
					if ( '' !== $sale ) { $product->set_sale_price( $sale ); }
					$product->save();
					delete_post_meta( $pid, '_lc_old_regular_price' );
					delete_post_meta( $pid, '_lc_old_sale_price' );
					$done++;
				}
			} else {
				$cat      = isset( $_POST['lc_cat'] ) && is_scalar( $_POST['lc_cat'] ) ? absint( wp_unslash( $_POST['lc_cat'] ) ) : 0;
				$mode_raw = isset( $_POST['lc_mode'] ) && is_scalar( $_POST['lc_mode'] ) ? sanitize_key( wp_unslash( $_POST['lc_mode'] ) ) : '';
				$mode     = in_array( $mode_raw, array( 'percent', 'fixed' ), true ) ? $mode_raw : 'percent';
				$value    = isset( $_POST['lc_value'] ) && is_scalar( $_POST['lc_value'] ) ? (float) wp_unslash( $_POST['lc_value'] ) : 0;
				$backup   = 'yes' === LeanCart_Framework::setting( 'backup', 'yes' );
				foreach ( self::products( $cat ) as $product ) {
					self::change_price( $product, $mode, $value, $backup );
					if ( $product->is_type( 'variable' ) ) {
						foreach ( $product->get_children() as $vid ) {
							$variation = wc_get_product( $vid );
							if ( $variation ) { self::change_price( $variation, $mode, $value, $backup ); }
						}
					}
					$done++;
				}
			}
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( '%d products updated.', 'bulk-price-changer' ), $done ) ) . '</p></div>';
		}
		$cat = isset( $_POST['lc_cat'] ) && is_scalar( $_POST['lc_cat'] ) ? absint( wp_unslash( $_POST['lc_cat'] ) ) : 0;
		$count = count( self::products( $cat ) );
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) { $terms = array(); }
		echo '<div class="wrap"><h1>' . esc_html__( 'Bulk price change', 'bulk-price-changer' ) . '</h1><form method="post">';
		wp_nonce_field( 'lc_bulk_price', '_lc_nonce' );
		echo '<p><label>Category <select name="lc_cat"><option value="0">All products</option>';
		foreach ( $terms as $term ) {
			echo '<option value="' . esc_attr( $term->term_id ) . '" ' . selected( $cat, $term->term_id, false ) . '>' . esc_html( $term->name ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label>Method <select name="lc_mode"><option value="percent">Percentage (%)</option><option value="fixed">Fixed amount</option></select></label> ';
		echo '<label>Value <input type="number" step="0.01" name="lc_value" value="10" /></label></p>';
		echo '<p><em>Products affected: ' . esc_html( $count ) . '</em></p>';
		submit_button( __( 'Apply', 'bulk-price-changer' ), 'primary', 'lc_apply' );
		submit_button( __( 'Undo', 'bulk-price-changer' ), 'secondary', 'lc_rollback' );
		echo '</form></div>';
	}


}

LeanCart_Bulk_Price_Changer::init();

