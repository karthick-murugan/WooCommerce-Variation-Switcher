<?php
/*
Plugin Name: WooCommerce Variation Switcher
Description: Switch variations directly in the cart and checkout pages.
Version: 1.0
Author: Karthick M
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Woo_Variation_Switcher {

	public function __construct() {
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_scripts' ) ); // Enqueue scripts
		add_action( 'wp_footer', array( $this, 'popup_html' ) ); // Output popup HTML in footer
		add_action( 'wp_ajax_woo_variation_switcher_get_variations', array( $this, 'woo_variation_switcher_get_variations_callback' ) );
		add_action( 'wp_ajax_nopriv_woo_variation_switcher_get_variations', array( $this, 'woo_variation_switcher_get_variations_callback' ) );
		add_action( 'wp_ajax_update_variation', array( $this, 'update_variation_callback' ) );
		add_action( 'wp_ajax_nopriv_update_variation', array( $this, 'update_variation_callback' ) );

	}

	public function enqueue_scripts() {
		if ( is_checkout() || is_cart() ) {
			wp_enqueue_script( 'woo-variation-switcher-block', plugins_url( 'js/woo-variation-switcher-block.js', __FILE__ ), array( 'jquery' ), '1.0', true );
			wp_localize_script(
				'woo-variation-switcher-block',
				'wooVariationSwitcherParams',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'woo-variation-switcher-nonce' ),
				)
			);
			wp_enqueue_style( 'woo-variation-switcher-block-styles', plugin_dir_url( __FILE__ ) . 'css/style.css', array(), filemtime( plugin_dir_path( __FILE__ ) . 'css/style.css' ) );
		}
	}

	public function popup_html() {
		?>
		<div id="variation-switcher-overlay" style="display:none;">
		<div id="variation-switcher-popup">
			<div id="variation-switcher-selects-container"></div>
			<button id="update-variations-button"><?php esc_html_e( 'Update', 'woo-variation-switcher' ); ?></button>
		</div>
	</div>
		<?php
	}

	public function woo_variation_switcher_get_variations_callback() {
		check_ajax_referer( 'woo-variation-switcher-nonce', 'security' );

		if ( ! isset( $_POST['variation_id'] ) ) {
			wp_send_json_error( __( 'Variation ID is required.', 'woo-variation-switcher' ) );
		}

		$variation_id = intval( $_POST['variation_id'] );
		$variation    = wc_get_product( $variation_id );

		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			wp_send_json_error( __( 'Invalid Variation ID.', 'woo-variation-switcher' ) );
		}

		$parent_product_id = $variation->get_parent_id();
		$product           = wc_get_product( $parent_product_id );

		if ( ! $product ) {
			wp_send_json_error( __( 'Invalid Product ID.', 'woo-variation-switcher' ) );
		}

		if ( ! $product->is_type( 'variable' ) ) {
			wp_send_json_error( __( 'Product is not Variable.', 'woo-variation-switcher' ) );
		}

		$variations = $product->get_available_variations();
		if ( empty( $variations ) ) {
			wp_send_json_error( __( 'No Variations found for this Product.', 'woo-variation-switcher' ) );
		}

		$attributes           = $product->get_variation_attributes();
		$formatted_attributes = array();
		foreach ( $attributes as $attr_name => $attr_values ) {
			$formatted_attributes[ $attr_name ] = array();
			foreach ( $attr_values as $value ) {
				$formatted_attributes[ $attr_name ][] = array(
					'value' => $value,
					'label' => wc_attribute_label( $attr_name ) . ': ' . $value,
				);
			}
		}

		$cart_item = null;
		$cart      = WC()->cart->get_cart();
		foreach ( $cart as $item ) {
			if ( $item['variation_id'] === $variation_id ) {
				$cart_item = $item;
				break;
			}
		}

		if ( $cart_item ) {
			$cart_item_attributes = $cart_item['variation'];
			foreach ( $formatted_attributes as $attr_name => &$attr_options ) {
				foreach ( $attr_options as &$attr_option ) {
					if ( $cart_item_attributes[ $attr_name ] === $attr_option['value'] ) {
						$attr_option['selected'] = true;
					} else {
						$attr_option['selected'] = false;
					}
				}
			}
		}

		wp_send_json_success(
			array(
				'variations' => $variations,
				'attributes' => $formatted_attributes,
				'cart_item'  => $cart_item,
				'product_id' => $parent_product_id, // Add product_id to the response
			)
		);
		wp_die();
	}

	function update_variation_callback() {
		check_ajax_referer( 'woo-variation-switcher-nonce', 'security' );

		if ( ! isset( $_POST['variations'] ) || ! isset( $_POST['product_id'] ) ) {
			wp_send_json_error( __( 'Missing Parameters', 'woo-variation-switcher' ) );
			return;
		}

		$product_id = absint( $_POST['product_id'] );

		// Sanitize variations array
		$variations = array_map( 'sanitize_text_field', $_POST['variations'] );

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( __( 'Invalid Product ID.', 'woo-variation-switcher' ) );
			return;
		}

		if ( ! $product->is_type( 'variable' ) ) {
			wp_send_json_error( __( 'Invalid Product type', 'woo-variation-switcher' ) );
			return;
		}

		$variation_id = $product->get_matching_variation( $variations );

		if ( ! $variation_id ) {
			wp_send_json_error( __( 'Invalid Variation Selected!!!', 'woo-variation-switcher' ) );
			return;
		}

		$cart          = WC()->cart;
		$cart_item_key = null;

		// Iterate over cart items to find the product
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( $item['product_id'] === $product_id ) {
				$cart_item_key = $key;
				break;
			}
		}

		if ( $cart_item_key ) {
			$cart->remove_cart_item( $cart_item_key );
			$cart->add_to_cart( $product_id, $item['quantity'], $variation_id, $variations );

			$response = array(
				'success' => true,
				'message' => __( 'Variation updated successfully', 'woo-variation-switcher' ),
			);
			wp_send_json_success( $response );
		} else {
			wp_send_json_error( __( 'Cart item not found', 'woo-variation-switcher' ) );
		}
	}


}

new Woo_Variation_Switcher();
