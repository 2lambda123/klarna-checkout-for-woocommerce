<?php // phpcs:ignore
/**
 * Plugin Name: Klarna Checkout for WooCommerce
 * Plugin URI: https://krokedil.com/klarna/
 * Description: Klarna Checkout payment gateway for WooCommerce.
 * Author: Krokedil
 * Author URI: https://krokedil.com/
 * Version: 1.11.3
 * Text Domain: klarna-checkout-for-woocommerce
 * Domain Path: /languages
 *
 * WC requires at least: 3.2.0
 * WC tested up to: 3.7.0
 *
 * Copyright (c) 2017-2019 Krokedil
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Required minimums and constants
 */
define( 'KCO_WC_VERSION', '1.11.3' );
define( 'KCO_WC_MIN_PHP_VER', '5.6.0' );
define( 'KCO_WC_MIN_WC_VER', '3.0.0' );
define( 'KCO_WC_MAIN_FILE', __FILE__ );
define( 'KCO_WC_PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'KCO_WC_PLUGIN_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'KROKEDIL_LOGGER_GATEWAY', 'kco' );
if ( ! class_exists( 'KCO' ) ) {
	/**
	 * Class KCO
	 */
	class KCO {

		/**
		 * The reference the *Singleton* instance of this class.
		 *
		 * @var $instance
		 */
		protected static $instance;

		/**
		 * Reference to API class.
		 *
		 * @var $api
		 */
		public $api;

		/**
		 * Reference to merchant URLs class.
		 *
		 * @var $merchant_urls
		 */
		public $merchant_urls;

		/**
		 * Reference to order lines class.
		 *
		 * @var $order_lines
		 */
		public $order_lines;

		/**
		 * Reference to credentials class.
		 *
		 * @var $credentials
		 */
		public $credentials;

		/**
		 * Reference to logging class.
		 *
		 * @var $log
		 */
		public $logger;

		/**
		 * Reference to order lines from order class.
		 *
		 * @var $log
		 */
		public $order_lines_from_order;

		/**
		 * Returns the *Singleton* instance of this class.
		 *
		 * @return self::$instance The *Singleton* instance.
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Private clone method to prevent cloning of the instance of the
		 * *Singleton* instance.
		 *
		 * @return void
		 */
		private function __clone() {
			wc_doing_it_wrong( __FUNCTION__, __( 'Nope' ), '1.0' );
		}

		/**
		 * Private unserialize method to prevent unserializing of the *Singleton*
		 * instance.
		 *
		 * @return void
		 */
		private function __wakeup() {
			wc_doing_it_wrong( __FUNCTION__, __( 'Nope' ), '1.0' );
		}

		/**
		 * Notices (array)
		 *
		 * @var array
		 */
		public $notices = array();

		/**
		 * Protected constructor to prevent creating a new instance of the
		 * *Singleton* via the `new` operator from outside of this class.
		 */
		protected function __construct() {
			add_action( 'admin_notices', array( $this, 'admin_notices' ), 15 );
			add_action( 'plugins_loaded', array( $this, 'init' ) );
			add_action( 'template_redirect', array( $this, 'maybe_display_kco_order_error_message' ) );

			// Add quantity button in woocommerce_order_review() function.
			add_filter( 'woocommerce_checkout_cart_item_quantity', array( $this, 'add_quantity_field' ), 10, 3 );
			$kco_options = get_option( 'woocommerce_kco_settings' );
			if ( 'yes' === $kco_options['logging'] ) {
				define( 'KROKEDIL_LOGGER_ON', true );
			}
		}

		/**
		 * Init the plugin after plugins_loaded so environment variables are set.
		 */
		public function init() {
			// Init the gateway itself.
			$this->init_gateways();
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
		}

		/**
		 * Adds plugin action links
		 *
		 * @param array $links Plugin action link before filtering.
		 *
		 * @return array Filtered links.
		 */
		public function plugin_action_links( $links ) {
			$setting_link = $this->get_setting_link();
			$plugin_links = array(
				'<a href="' . $setting_link . '">' . __( 'Settings', 'klarna-checkout-for-woocommerce' ) . '</a>',
				'<a href="http://krokedil.se/">' . __( 'Support', 'klarna-checkout-for-woocommerce' ) . '</a>',
			);

			return array_merge( $plugin_links, $links );
		}

		/**
		 * Get setting link.
		 *
		 * @since 1.0.0
		 *
		 * @return string Setting link
		 */
		public function get_setting_link() {
			$section_slug = 'kco';

			return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $section_slug );
		}

		/**
		 * Display any notices we've collected thus far (e.g. for connection, disconnection)
		 */
		public function admin_notices() {
			foreach ( (array) $this->notices as $notice_key => $notice ) {
				echo "<div class='" . esc_attr( $notice['class'] ) . "'><p>";
				echo wp_kses( $notice['message'], array( 'a' => array( 'href' => array() ) ) );
				echo '</p></div>';
			}
		}

		/**
		 * Display Klarna order error in cart page if customer have been redirected to cart because of a communication issue.
		 */
		public function maybe_display_kco_order_error_message() {
			if ( class_exists( 'WooCommerce' ) ) {
				if ( is_cart() && isset( $_GET['kco-order'] ) && 'error' === $_GET['kco-order'] ) {
					if ( isset( $_GET['reason'] ) ) {
						$reason  = sanitize_textarea_field( base64_decode( $_GET['reason'] ) );
						$text    = __( 'Klarna Checkout error', 'klarna-checkout-for-woocommerce' ) . ' %s.';
						$message = sprintf( $text, $reason );
					} else {
						$message = __( 'Klarna Checkout error. Please try again.', 'klarna-checkout-for-woocommerce' );
					}
					wc_add_notice( $message, 'error' );
				}
				if ( is_cart() && isset( $_GET['kco-order'] ) && 'missing-id' === $_GET['kco-order'] ) {
					wc_add_notice( __( 'An error occurred during communication with Klarna (Klarna order ID is missing). Please try again.', 'klarna-checkout-for-woocommerce' ), 'error' );
				}
			}
		}

		/**
		 * Initialize the gateway. Called very early - in the context of the plugins_loaded action
		 *
		 * @since 1.0.0
		 */
		public function init_gateways() {
			if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
				return;
			}

			// Classes.
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-gateway.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-api.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-api-callbacks.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-templates.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-ajax.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-order-lines.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-merchant-urls.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-credentials.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-logger.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-fields.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-confirmation.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-extra-checkout-fields.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-status.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-create-local-order-fallback.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-gdpr.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-form-fields.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-subscription.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/class-kco-order-lines-from-order.php';
			if ( is_admin() ) {
				include_once KCO_WC_PLUGIN_PATH . '/classes/admin/class-kco-admin-notices.php';
				include_once KCO_WC_PLUGIN_PATH . '/classes/admin/class-wc-klarna-banners.php';
				include_once KCO_WC_PLUGIN_PATH . '/classes/admin/class-klarna-for-woocommerce-addons.php';
			}

			// Requests.
			include_once KCO_WC_PLUGIN_PATH . '/classes/requests/class-kco-request.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/requests/checkout/post/class-kco-request-create.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/requests/checkout/post/class-kco-request-update.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/requests/checkout/get/class-kco-request-retrieve.php';

			// Helpers
			include_once KCO_WC_PLUGIN_PATH . '/classes/requests/helpers/class-kco-request-cart.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/requests/helpers/class-kco-request-countries.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/requests/helpers/class-kco-request-merchant-data.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/requests/helpers/class-kco-request-options.php';
			include_once KCO_WC_PLUGIN_PATH . '/classes/requests/helpers/class-kco-request-shipping-options.php';

			// Includes.
			include_once KCO_WC_PLUGIN_PATH . '/includes/kco-functions.php';
			include_once KCO_WC_PLUGIN_PATH . '/vendor/autoload.php';

			// Set class variables.
			$this->api                    = new KCO_API();
			$this->merchant_urls          = new KCO_Merchant_URLs();
			$this->order_lines            = new KCO_Order_Lines();
			$this->credentials            = new KCO_Credentials();
			$this->logger                 = new KCO_Logger();
			$this->order_lines_from_order = new KCO_Order_Lines_From_Order();

			load_plugin_textdomain( 'klarna-checkout-for-woocommerce', false, plugin_basename( __DIR__ ) . '/languages' );
			add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );
		}

		/**
		 * Add the gateways to WooCommerce
		 *
		 * @param  array $methods Payment methods.
		 *
		 * @return array $methods Payment methods.
		 * @since  1.0.0
		 */
		public function add_gateways( $methods ) {
			$methods[] = 'KCO_Gateway';

			return $methods;
		}

		/**
		 * Filters cart item quantity output.
		 *
		 * @param string $output HTML output.
		 * @param array  $cart_item Cart item.
		 * @param string $cart_item_key Cart item key.
		 *
		 * @return string $output
		 */
		public function add_quantity_field( $output, $cart_item, $cart_item_key ) {
			if ( 'kco' === WC()->session->get( 'chosen_payment_method' ) ) {
				foreach ( WC()->cart->get_cart() as $cart_key => $cart_value ) {
					if ( $cart_key === $cart_item_key ) {
						$_product = $cart_item['data'];

						if ( $_product->is_sold_individually() ) {
							$return_value = sprintf( '1 <input type="hidden" name="cart[%s][qty]" value="1" />', $cart_key );
						} else {
							$return_value = woocommerce_quantity_input(
								array(
									'input_name'  => 'cart[' . $cart_key . '][qty]',
									'input_value' => $cart_item['quantity'],
									'max_value'   => $_product->backorders_allowed() ? '' : $_product->get_stock_quantity(),
									'min_value'   => '1',
								),
								$_product,
								false
							);
						}

						$output = $return_value;
					}
				}
			}

			return $output;
		}

	}
	KCO::get_instance();
}

/**
 * Main instance KCO WooCommerce.
 *
 * Returns the main instance of KCO.
 *
 * @return KCO
 */
function KCO_WC() { // phpcs:ignore
	return KCO::get_instance();
}
