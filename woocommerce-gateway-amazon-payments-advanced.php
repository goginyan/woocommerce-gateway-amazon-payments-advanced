<?php
/**
 * Main class and core functions.
 *
 * @package WC_Gateway_Amazon_Pay
 */

/*
 * Plugin Name: WooCommerce Amazon Pay Gateway
 * Plugin URI: https://woocommerce.com/products/pay-with-amazon/
 * Description: Amazon Pay is embedded directly into your existing web site, and all the buyer interactions with Amazon Pay and Login with Amazon take place in embedded widgets so that the buyer never leaves your site. Buyers can log in using their Amazon account, select a shipping address and payment method, and then confirm their order. Requires an Amazon Pay seller account and supports USA, UK, Germany, France, Italy, Spain, Luxembourg, the Netherlands, Sweden, Portugal, Hungary, Denmark, and Japan.
 * Version: 1.8.5
 * Author: WooCommerce
 * Author URI: https://woocommerce.com
 *
 * Text Domain: woocommerce-gateway-amazon-payments-advanced
 * Domain Path: /languages/
 * WC tested up to: 3.5
 * WC requires at least: 2.6
 *
 * Copyright: © 2009-2017 WooCommerce.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Woo: 238816:9865e043bbbe4f8c9735af31cb509b53
 */

/**
 * Required functions
 */
if ( ! function_exists( 'woothemes_queue_update' ) ) {
	require_once( 'woo-includes/woo-functions.php' );
}

define( 'WC_AMAZON_PAY_VERSION', '1.8.5' );

/**
 * Plugin updates
 */
woothemes_queue_update( plugin_basename( __FILE__ ), '9865e043bbbe4f8c9735af31cb509b53', '238816' );

/**
 * Amazon Pay main class
 */
class WC_Amazon_Payments_Advanced {

	/**
	 * Plugin's version.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public $version;

	/**
	 * Plugin's absolute path.
	 *
	 * @var string
	 */
	public $path;

	/**
	 * Plugin's URL.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public $plugin_url;

	/**
	 * Amazon Pay settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Reference ID
	 *
	 * @var string
	 */
	private $reference_id;


	/**
	 * Access token
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Amazon Pay Gateway
	 *
	 * @var WC_Gateway_Amazon_Payments_Advanced
	 */
	private $gateway;

	/**
	 * WC logger instance.
	 *
	 * @var WC_Logger
	 */
	private $logger;

	/**
	 * Order admin handler instance.
	 *
	 * @since 1.6.0
	 * @var WC_Amazon_Payments_Advanced_Order_Admin
	 */
	private $order_admin;

	/**
	 * Amazon Pay compat handler.
	 *
	 * @since 1.6.0
	 * @var WC_Amazon_Payments_Advanced_Compat
	 */
	private $compat;

	/**
	 * IPN handler.
	 *
	 * @since 1.8.0
	 * @var WC_Amazon_Payments_Advanced_IPN_Handler
	 */
	public $ipn_handler;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->version    = WC_AMAZON_PAY_VERSION;
		$this->path       = untrailingslashit( plugin_dir_path( __FILE__ ) );
		$this->plugin_url = untrailingslashit( plugins_url( '/', __FILE__ ) );

		include_once( $this->path . '/includes/class-wc-amazon-payments-advanced-api.php' );
		include_once( $this->path . '/includes/class-wc-amazon-payments-advanced-compat.php' );
		include_once( $this->path . '/includes/class-wc-amazon-payments-advanced-ipn-handler.php' );

		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_links' ) );
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'wp_loaded', array( $this, 'init_handlers' ), 11 );
		add_action( 'wp_footer', array( $this, 'maybe_hide_standard_checkout_button' ) );
		add_action( 'wp_footer', array( $this, 'maybe_hide_amazon_buttons' ) );
		add_action( 'woocommerce_thankyou_amazon_payments_advanced', array( $this, 'logout_from_amazon' ) );
		add_filter( 'woocommerce_ajax_get_endpoint', array( $this, 'filter_ajax_endpoint' ), 10, 2 );

		// REST API support.
		add_action( 'rest_api_init', array( $this, 'rest_api_register_routes' ), 11 );
		add_filter( 'woocommerce_rest_prepare_shop_order', array( $this, 'rest_api_add_amazon_ref_info' ), 10, 2 );

		// IPN handler.
		$this->ipn_handler = new WC_Amazon_Payments_Advanced_IPN_Handler();

		// Admin notices.
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'wp_ajax_amazon_pay_dismiss_notice', array( $this, 'ajax_dismiss_notice' ) );
	}

	/**
	 * Maybe hide standard WC checkout button on the cart, if enabled
	 */
	public function maybe_hide_standard_checkout_button() {
		if ( 'yes' === $this->settings['enabled'] && 'yes' === $this->settings['hide_standard_checkout_button'] ) {
			?>
				<style type="text/css">
					.woocommerce a.checkout-button,
					.woocommerce input.checkout-button,
					.cart input.checkout-button,
					.cart a.checkout-button,
					.widget_shopping_cart a.checkout {
						display: none !important;
					}
				</style>
			<?php
		}
	}

	/**
	 * Maybe hides Amazon Pay buttons on cart or checkout pages if hide button mode
	 * is enabled.
	 *
	 * @since 1.6.0
	 */
	public function maybe_hide_amazon_buttons() {
		$hide_button_mode_enabled = (
			'yes' === $this->settings['enabled']
			&&
			'yes' === $this->settings['hide_button_mode']
		);
		if ( ! $hide_button_mode_enabled ) {
			return;
		}

		?>
		<style type="text/css">
		.wc-amazon-payments-advanced-info, #pay_with_amazon {
			display: none;
		}
		</style>
		<?php
	}

	/**
	 * Plugin page links
	 *
	 * @since 1.0.0
	 * @version 1.7.3
	 *
	 * @param array $links Array links.
	 */
	public function plugin_links( $links ) {
		$plugin_links = array(
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=amazon_payments_advanced' ) . '">' . __( 'Settings', 'woocommerce-gateway-amazon-payments-advanced' ) . '</a>',
			'<a href="https://docs.woocommerce.com/">' . __( 'Support', 'woocommerce-gateway-amazon-payments-advanced' ) . '</a>',
			'<a href="https://docs.woocommerce.com/document/amazon-payments-advanced/">' . __( 'Docs', 'woocommerce-gateway-amazon-payments-advanced' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Init.
	 *
	 * @since 1.6.0
	 */
	public function init() {
		if ( ! is_woocommerce_active() ) {
			return;
		}

		$this->settings     = WC_Amazon_Payments_Advanced_API::get_settings();
		$this->reference_id = WC_Amazon_Payments_Advanced_API::get_reference_id();
		$this->access_token = WC_Amazon_Payments_Advanced_API::get_access_token();

		$this->maybe_display_declined_notice();
		$this->maybe_attempt_to_logout();

		$this->compat = new WC_Amazon_Payments_Advanced_Compat();
		$this->compat->load_compats();

		$this->load_plugin_textdomain();
		$this->init_order_admin();
		$this->init_gateway();
	}

	/**
	 * Maybe display declined notice.
	 *
	 * @since 1.7.1
	 * @version 1.7.1
	 */
	public function maybe_display_declined_notice() {
		if ( ! empty( $_GET['amazon_declined'] ) ) {
			wc_add_notice( __( 'There was a problem with previously declined transaction. Please retry placing the order again.', 'woocommerce-gateway-amazon-payments-advanced' ), 'error' );
		}
	}

	/**
	 * Maybe the request to logout from Amazon.
	 *
	 * @since 1.6.0
	 */
	public function maybe_attempt_to_logout() {
		if ( ! empty( $_GET['amazon_payments_advanced'] ) && ! empty( $_GET['amazon_logout'] ) ) {
			$this->logout_from_amazon();
		}
	}

	/**
	 * Logout from Amazon by removing Amazon related session and logout too
	 * from app widget.
	 *
	 * @since 1.6.0
	 */
	public function logout_from_amazon() {
		unset( WC()->session->amazon_reference_id );
		unset( WC()->session->amazon_access_token );

		$this->reference_id = '';
		$this->access_token = '';

		if ( is_order_received_page() && 'yes' === $this->settings['enable_login_app'] ) {
			?>
			<script>
			( function( $ ) {
				$( document ).on( 'wc_amazon_pa_login_ready', function() {
					amazon.Login.logout();
				} );
			} )(jQuery)
			</script>
			<?php
		}
	}

	/**
	 * Load translations.
	 *
	 * @since 1.6.0
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'woocommerce-gateway-amazon-payments-advanced', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Init admin handler.
	 *
	 * @since 1.6.0
	 */
	public function init_order_admin() {
		include_once( $this->path . '/includes/class-wc-amazon-payments-advanced-order-admin.php' );

		$this->order_admin = new WC_Amazon_Payments_Advanced_Order_Admin();
		$this->order_admin->add_meta_box();
		$this->order_admin->add_ajax_handler();
	}

	/**
	 * Init gateway
	 */
	public function init_gateway() {

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		include_once( $this->path . '/includes/class-wc-gateway-amazon-payments-advanced.php' );
		include_once( $this->path . '/includes/class-wc-gateway-amazon-payments-advanced-privacy.php' );

		$subscriptions_installed = class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' );
		$subscriptions_enabled   = empty( $this->settings['subscriptions_enabled'] ) || 'yes' == $this->settings['subscriptions_enabled'];

		// Check for Subscriptions 2.0, and load support if found.
		if ( $subscriptions_installed && $subscriptions_enabled ) {

			include_once( $this->path . '/includes/class-wc-gateway-amazon-payments-advanced-subscriptions.php' );

			$this->gateway = new WC_Gateway_Amazon_Payments_Advanced_Subscriptions();

		} else {

			$this->gateway = new WC_Gateway_Amazon_Payments_Advanced();

		}

		add_filter( 'woocommerce_payment_gateways',  array( $this, 'add_gateway' ) );
	}

	/**
	 * Load handlers for cart and orders after WC Cart is loaded.
	 */
	public function init_handlers() {
		// Disable if no seller ID.
		if ( ! apply_filters( 'woocommerce_amazon_payments_init', true ) || empty( $this->settings['seller_id'] ) || 'no' == $this->settings['enabled'] ) {
			return;
		}

		// Login app actions.
		if ( 'yes' === $this->settings['enable_login_app'] ) {

			// Login app widget.
			add_action( 'wp_head', array( $this, 'init_amazon_login_app_widget' ) );

		}

		if ( 'button' === $this->settings['cart_button_display_mode'] ) {

			add_action( 'woocommerce_proceed_to_checkout', array( $this, 'checkout_button' ), 25 );

		} elseif ( 'banner' === $this->settings['cart_button_display_mode'] ) {

			add_action( 'woocommerce_before_cart', array( $this, 'checkout_message' ), 5 );

		}

		add_action( 'wp_enqueue_scripts', array( $this, 'scripts' ) );
		add_action( 'woocommerce_checkout_init', array( $this, 'checkout_init' ) );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'update_amazon_widgets_fragment' ) );
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'force_standard_mode_refresh_with_zero_order_total' ) );

		// When transaction is declined with reason code 'InvalidPaymentMethod',
		// the customer will be promopted with read-only address widget and need
		// to fix the chosen payment method. Coupon form should be disabled in
		// this state.
		//
		// @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/244.
		// @see https://pay.amazon.com/de/developer/documentation/lpwa/201953810#sync-invalidpaymentmethod.
		if ( WC()->session && 'InvalidPaymentMethod' === WC()->session->amazon_declined_code ) {
			remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form' );
		}
	}

	/**
	 * Initialize Amazon Pay UI during checkout.
	 *
	 * @param WC_Checkout $checkout Checkout object.
	 */
	public function checkout_init( $checkout ) {

		/**
		 * Make sure this is checkout initiated from front-end where cart exsits.
		 *
		 * @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/238
		 */
		if ( ! WC()->cart ) {
			return;
		}

		// Are we using the login app?
		$enable_login_app = ( 'yes' === $this->settings['enable_login_app'] );

		// Disable Amazon Pay for zero-total checkouts using the standard button.
		if ( ! WC()->cart->needs_payment() && ! $enable_login_app ) {

			// Render a placeholder widget container instead, in the event we
			// need to populate it later.
			add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'placeholder_widget_container' ) );

			// Render a placeholder checkout message container, in the event we
			// need to populate it later.
			add_action( 'woocommerce_before_checkout_form', array( $this, 'placeholder_checkout_message_container' ), 5 );

			return;

		}

		add_action( 'woocommerce_before_checkout_form', array( $this, 'checkout_message' ), 5 );
		add_action( 'before_woocommerce_pay', array( $this, 'checkout_message' ), 5 );

		// Don't try to render the Amazon widgets if we don't have the prerequisites
		// for each mode.
		if ( ( ! $enable_login_app && empty( $this->reference_id ) ) || ( $enable_login_app && empty( $this->access_token ) ) ) {
			return;
		}

		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'payment_widget' ), 20 );
		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'address_widget' ), 10 );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'remove_gateways' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'capture_shipping_address_for_zero_order_total' ) );

		// The default checkout form uses the billing email for new account creation
		// Let's hijack that field for the Amazon-based checkout.
		if ( apply_filters( 'woocommerce_pa_hijack_checkout_fields', true ) ) {
			$this->hijack_checkout_fields( $checkout );
		}
	}

	/**
	 * Hijack checkout fields during checkout.
	 *
	 * @since 1.6.0
	 *
	 * @param WC_Checkout $checkout WC_Checkout instance.
	 */
	public function hijack_checkout_fields( $checkout ) {
		$has_billing_fields = (
			isset( $checkout->checkout_fields['billing'] )
			&&
			is_array( $checkout->checkout_fields['billing'] )
		);

		if ( $has_billing_fields ) {
			$this->hijack_checkout_field_account( $checkout );
		}

		// During an Amazon checkout, the standard billing and shipping fields need to be
		// "removed" so that we don't trigger a false negative on form validation -
		// they can be empty since we're using the Amazon widgets.
		$this->hijack_checkout_field_billing( $checkout );
		$this->hijack_checkout_field_shipping( $checkout );
	}

	/**
	 * Alter account checkout field.
	 *
	 * @since 1.7.0
	 *
	 * @param WC_Checkout $checkout WC_Checkout instance.
	 */
	private function hijack_checkout_field_account( $checkout ) {
		$billing_fields_to_copy = array(
			'billing_first_name' => '',
			'billing_last_name'  => '',
			'billing_email'      => '',
		);

		$billing_fields_to_merge = array_intersect_key( $checkout->checkout_fields['billing'], $billing_fields_to_copy );

		/**
		 * WC 3.0 changes a bit a way to retrieve fields.
		 *
		 * @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/217
		 */
		$checkout_fields = version_compare( WC_VERSION, '3.0', '>=' )
			? $checkout->get_checkout_fields()
			: $checkout->checkout_fields;

		/**
		 * Before 3.0.0, account fields may not set at all if guest checkout is
		 * disabled with account and password generated automatically.
		 *
		 * @see https://github.com/woocommerce/woocommerce/blob/2.6.14/includes/class-wc-checkout.php#L92-L132
		 * @see https://github.com/woocommerce/woocommerce/blob/master/includes/class-wc-checkout.php#L187-L197
		 * @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/228
		 */
		$checkout_fields['account'] = ! empty( $checkout_fields['account'] )
			? $checkout_fields['account']
			: array();

		$checkout_fields['account'] = array_merge( $billing_fields_to_merge, $checkout_fields['account'] );

		if ( isset( $checkout_fields['account']['billing_email']['class'] ) ) {
			$checkout_fields['account']['billing_email']['class'] = array();
		}

		$checkout->checkout_fields = $checkout_fields;
	}

	/**
	 * Hijack billing checkout field.
	 *
	 * @since 1.7.0
	 *
	 * @param WC_Checkout $checkout WC_Checkout instance.
	 */
	private function hijack_checkout_field_billing( $checkout ) {
		$checkout_fields = version_compare( WC_VERSION, '3.0', '>=' )
			? $checkout->get_checkout_fields()
			: $checkout->checkout_fields;

		$checkout_fields['billing']  = array();
		$checkout->checkout_fields = $checkout_fields;
	}

	/**
	 * Hijack shipping checkout field.
	 *
	 * @since 1.7.0
	 *
	 * @param WC_Checkout $checkout WC_Checkout instance.
	 */
	private function hijack_checkout_field_shipping( $checkout ) {
		$checkout_fields = version_compare( WC_VERSION, '3.0', '>=' )
			? $checkout->get_checkout_fields()
			: $checkout->checkout_fields;

		$checkout_fields['shipping']  = array();
		$checkout->checkout_fields = $checkout_fields;
	}

	/**
	 * Checkout Button
	 *
	 * Triggered from the 'woocommerce_proceed_to_checkout' action.
	 */
	public function checkout_button() {
		$subscriptions_installed = class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' );
		$subscriptions_enabled   = empty( $this->settings['subscriptions_enabled'] ) || 'yes' == $this->settings['subscriptions_enabled'];
		$cart_contains_sub       = class_exists( 'WC_Subscriptions_Cart' ) ? WC_Subscriptions_Cart::cart_contains_subscription() : false;

		if ( $subscriptions_installed && ! $subscriptions_enabled && $cart_contains_sub ) {
			return;
		}

		echo '<div id="pay_with_amazon"></div>';
	}

	/**
	 * Checkout Message
	 */
	public function checkout_message() {
		$subscriptions_installed = class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' );
		$subscriptions_enabled   = empty( $this->settings['subscriptions_enabled'] ) || 'yes' == $this->settings['subscriptions_enabled'];
		$cart_contains_sub       = class_exists( 'WC_Subscriptions_Cart' ) ? WC_Subscriptions_Cart::cart_contains_subscription() : false;

		if ( $subscriptions_installed && ! $subscriptions_enabled && $cart_contains_sub ) {
			return;
		}

		echo '<div class="wc-amazon-checkout-message wc-amazon-payments-advanced-populated">';

		if ( empty( $this->reference_id ) && empty( $this->access_token ) ) {
			echo '<div class="woocommerce-info info wc-amazon-payments-advanced-info"><div id="pay_with_amazon"></div> ' . apply_filters( 'woocommerce_amazon_pa_checkout_message', __( 'Have an Amazon account?', 'woocommerce-gateway-amazon-payments-advanced' ) ) . '</div>';
		} else {
			$logout_url = $this->get_amazon_logout_url();
			echo '<div class="woocommerce-info info">' . apply_filters( 'woocommerce_amazon_pa_checkout_logout_message', __( 'You\'re logged in with your Amazon Account.', 'woocommerce-gateway-amazon-payments-advanced' ) ) . ' <a href="' . esc_url( $logout_url ) . '" id="amazon-logout">' . __( 'Log out &raquo;', 'woocommerce-gateway-amazon-payments-advanced' ) . '</a></div>';
		}

		echo '</div>';

	}

	/**
	 * Add Amazon gateway to WC.
	 *
	 * @param array $methods List of payment methods.
	 *
	 * @return array List of payment methods.
	 */
	public function add_gateway( $methods ) {
		$methods[] = $this->gateway;

		return $methods;
	}

	/**
	 * Init Amazon login app widget.
	 */
	public function init_amazon_login_app_widget() {
		?>
		<script>
			window.onAmazonLoginReady = function() {
				amazon.Login.setClientId( "<?php echo esc_js( $this->settings['app_client_id'] ); ?>" );
				jQuery( document ).trigger( 'wc_amazon_pa_login_ready' );
			};
		</script>
		<?php
	}

	/**
	 * Add scripts
	 */
	public function scripts() {

		$enqueue_scripts = is_cart() || is_checkout() || is_checkout_pay_page();

		if ( ! apply_filters( 'woocommerce_amazon_pa_enqueue_scripts', $enqueue_scripts ) ) {
			return;
		}

		$css_suffix = '.min.css';
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$css_suffix = '.css';
		}
		$js_suffix = '.min.js';
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$js_suffix = '.js';
		}

		$type = ( 'yes' == $this->settings['enable_login_app'] ) ? 'app' : 'standard';

		wp_enqueue_style( 'amazon_payments_advanced', plugins_url( 'assets/css/style' . $css_suffix, __FILE__ ), array(), $this->version );
		wp_enqueue_script( 'amazon_payments_advanced_widgets', WC_Amazon_Payments_Advanced_API::get_widgets_url(), array(), $this->version, true );
		wp_enqueue_script( 'amazon_payments_advanced', plugins_url( 'assets/js/amazon-' . $type . '-widgets' . $js_suffix, __FILE__ ), array(), $this->version, true );

		$redirect_page = is_cart() ? add_query_arg( 'amazon_payments_advanced', 'true', get_permalink( wc_get_page_id( 'checkout' ) ) ) : add_query_arg( array( 'amazon_payments_advanced' => 'true', 'amazon_logout' => false ) );

		$params = array(
			'seller_id'             => $this->settings['seller_id'],
			'reference_id'          => $this->reference_id,
			'redirect'              => esc_url_raw( $redirect_page ),
			'is_checkout_pay_page'  => is_checkout_pay_page(),
			'is_checkout'           => is_checkout(),
			'access_token'          => $this->access_token,
			'logout_url'            => esc_url_raw( $this->get_amazon_logout_url() ),
			'render_address_widget' => apply_filters( 'woocommerce_amazon_show_address_widget', WC()->cart->needs_shipping() ),
		);

		if ( 'yes' == $this->settings['enable_login_app'] ) {

			$params['button_type']     = $this->settings['button_type'];
			$params['button_color']    = $this->settings['button_color'];
			$params['button_size']     = $this->settings['button_size'];
			$params['button_language'] = $this->settings['button_language'];
			$params['checkout_url']    = esc_url_raw( get_permalink( wc_get_page_id( 'checkout' ) ) );

		}

		if ( WC()->session->amazon_declined_code ) {
			$params['declined_code'] = WC()->session->amazon_declined_code;
			unset( WC()->session->amazon_declined_code );
		}

		if ( WC()->session->amazon_declined_with_cancel_order ) {
			$order                           = wc_get_order( WC()->session->amazon_declined_order_id );
			$params['declined_redirect_url'] = add_query_arg(
				array(
					'amazon_payments_advanced' => 'true',
					'amazon_logout'            => 'true',
					'amazon_declined'          => 'true',
				),
				$order->get_cancel_order_url()
			);

			unset( WC()->session->amazon_declined_order_id );
			unset( WC()->session->amazon_declined_with_cancel_order );
		}

		if ( class_exists( 'WC_Subscriptions_Cart' ) ) {

			$cart_contains_subscription      = WC_Subscriptions_Cart::cart_contains_subscription() || wcs_cart_contains_renewal();
			$change_payment_for_subscription = isset( $_GET['change_payment_method'] ) && wcs_is_subscription( absint( $_GET['change_payment_method'] ) );
			$params['is_recurring']          = $cart_contains_subscription || $change_payment_for_subscription;

			// No need to make billing agreement if automatic payments is turned off.
			if ( 'yes' === get_option( 'woocommerce_subscriptions_turn_off_automatic_payments' ) ) {
				unset( $params['is_recurring'] );
			}
		}

		$params = array_map( 'esc_js', apply_filters( 'woocommerce_amazon_pa_widgets_params', $params ) );

		wp_localize_script( 'amazon_payments_advanced', 'amazon_payments_advanced_params', $params );

		do_action( 'wc_amazon_pa_scripts_enqueued', $type, $params );
	}

	/**
	 * Output an empty placeholder widgets container
	 */
	public function placeholder_widget_container() {
		?>
		<div id="amazon_customer_details"></div>
		<?php
	}

	/**
	 * Output an empty placeholder checkout message container
	 */
	public function placeholder_checkout_message_container() {
		?>
		<div class="wc-amazon-checkout-message"></div>
		<?php
	}

	/**
	 * Output the address widget HTML
	 */
	public function address_widget() {
		// Skip showing address widget for carts with virtual products only
		$show_address_widget = apply_filters( 'woocommerce_amazon_show_address_widget', WC()->cart->needs_shipping() );
		$hide_css_style      = ( ! $show_address_widget ) ? 'display: none;' : '';
		?>
		<div id="amazon_customer_details" class="wc-amazon-payments-advanced-populated">
			<div class="col2-set">
				<div class="col-1" style="<?php echo $hide_css_style; ?>">
					<h3><?php esc_html_e( 'Shipping Address', 'woocommerce-gateway-amazon-payments-advanced' ); ?></h3>
					<div id="amazon_addressbook_widget"></div>
					<?php if ( ! empty( $this->reference_id ) ) : ?>
						<input type="hidden" name="amazon_reference_id" value="<?php echo esc_attr( $this->reference_id ); ?>" />
					<?php endif; ?>
					<?php if ( ! empty( $this->access_token ) ) : ?>
						<input type="hidden" name="amazon_access_token" value="<?php echo esc_attr( $this->access_token ); ?>" />
					<?php endif; ?>
				</div>
		<?php
	}

	/**
	 * Output the payment method widget HTML
	 */
	public function payment_widget() {
		$checkout = WC_Checkout::instance();
		?>
				<div class="col-2">
					<h3><?php _e( 'Payment Method', 'woocommerce-gateway-amazon-payments-advanced' ); ?></h3>
					<div id="amazon_wallet_widget"></div>
					<?php if ( ! empty( $this->reference_id ) ) : ?>
						<input type="hidden" name="amazon_reference_id" value="<?php echo esc_attr( $this->reference_id ); ?>" />
					<?php endif; ?>
					<?php if ( ! empty( $this->access_token ) ) : ?>
						<input type="hidden" name="amazon_access_token" value="<?php echo esc_attr( $this->access_token ); ?>" />
					<?php endif; ?>
				</div>
				<div id="amazon_consent_widget" style="display: none;"></div>

		<?php if ( ! is_user_logged_in() && $checkout->enable_signup ) : ?>

			<?php if ( $checkout->enable_guest_checkout ) : ?>

				<p class="form-row form-row-wide create-account">
					<input class="input-checkbox" id="createaccount" <?php checked( ( true === $checkout->get_value( 'createaccount' ) || ( true === apply_filters( 'woocommerce_create_account_default_checked', false ) ) ), true ) ?> type="checkbox" name="createaccount" value="1" /> <label for="createaccount" class="checkbox"><?php _e( 'Create an account?', 'woocommerce-gateway-amazon-payments-advanced' ); ?></label>
				</p>

			<?php endif; ?>

			<?php do_action( 'woocommerce_before_checkout_registration_form', $checkout ); ?>

			<?php if ( ! empty( $checkout->checkout_fields['account'] ) ) : ?>

				<div class="create-account">

					<h3><?php _e( 'Create Account', 'woocommerce-gateway-amazon-payments-advanced' ); ?></h3>
					<p><?php _e( 'Create an account by entering the information below. If you are a returning customer please login at the top of the page.', 'woocommerce-gateway-amazon-payments-advanced' ); ?></p>

					<?php foreach ( $checkout->checkout_fields['account'] as $key => $field ) : ?>

						<?php woocommerce_form_field( $key, $field, $checkout->get_value( $key ) ); ?>

					<?php endforeach; ?>

					<div class="clear"></div>

				</div>

			<?php endif; ?>

			<?php do_action( 'woocommerce_after_checkout_registration_form', $checkout ); ?>

		<?php endif; ?>
			</div>
		</div>

		<?php
	}

	/**
	 * Render the Amazon Pay widgets when an order is updated to require
	 * payment, and the Amazon gateway is available.
	 *
	 * @param array $fragments Fragments.
	 *
	 * @return array
	 */
	public function update_amazon_widgets_fragment( $fragments ) {

		$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();

		if ( WC()->cart->needs_payment() ) {

			ob_start();

			$this->checkout_message();

			$fragments['.wc-amazon-checkout-message:not(.wc-amazon-payments-advanced-populated)'] = ob_get_clean();

			if ( array_key_exists( 'amazon_payments_advanced', $available_gateways ) ) {

				ob_start();

				$this->address_widget();

				$this->payment_widget();

				$fragments['#amazon_customer_details:not(.wc-amazon-payments-advanced-populated)'] = ob_get_clean();
			}
		}

		return $fragments;

	}

	/**
	 * Remove all gateways except amazon
	 *
	 * @param array $gateways List of payment methods.
	 *
	 * @return array List of payment methods.
	 */
	public function remove_gateways( $gateways ) {

		foreach ( $gateways as $gateway_key => $gateway ) {
			if ( 'amazon_payments_advanced' !== $gateway_key ) {
				unset( $gateways[ $gateway_key ] );
			}
		}

		return $gateways;
	}

	/**
	 * Capture full shipping address in the case of a $0 order, using the "login app"
	 * version of the API.
	 *
	 * @version 1.7.1
	 *
	 * @param int $order_id Order ID.
	 */
	public function capture_shipping_address_for_zero_order_total( $order_id ) {
		$order = wc_get_order( $order_id );

		/**
		 * Complete address data is only available without a confirmed order if
		 * we're using the login app.
		 *
		 * @see Getting the Shipping Address section at https://payments.amazon.com/documentation/lpwa/201749990
		 */
		if ( ( $order->get_total() > 0 ) || empty( $this->reference_id ) || ( 'yes' !== $this->settings['enable_login_app'] ) || empty( $this->access_token ) ) {
			return;
		}

		// Get FULL address details and save them to the order.
		$order_details = $this->gateway->get_amazon_order_details( $this->reference_id );

		if ( $order_details ) {
			$this->gateway->store_order_address_details( $order, $order_details );
		}
	}

	/**
	 * Helper method to get a sanitized version of the site name.
	 *
	 * @return string
	 */
	public static function get_site_name() {
		// Get site setting for blog name.
		$site_name = get_bloginfo( 'name' );

		// Decode HTML entities.
		$site_name = wp_specialchars_decode( $site_name, ENT_QUOTES );

		// ASCII-ify accented characters.
		$site_name = remove_accents( $site_name );

		// Remove non-printable characters.
		$site_name = preg_replace( '/[[:^print:]]/', '', $site_name );

		// Clean up leading/trailing whitespace.
		$site_name = trim( $site_name );

		return $site_name;

	}

	/**
	 * Force a page refresh when an order is updated to have a zero total and
	 * we're not using the "login app" mode.
	 *
	 * This ensures that the standard WC checkout form is rendered.
	 *
	 * @param WC_Cart $cart Cart object.
	 */
	public function force_standard_mode_refresh_with_zero_order_total( $cart ) {
		// Avoid constant reload loop in the event we've forced a checkout refresh.
		if ( ! is_ajax() ) {
			unset( WC()->session->reload_checkout );
		}

		// Login app mode can handle zero-total orders.
		if ( 'yes' === $this->settings['enable_login_app'] ) {
			return;
		}

		if ( ! $this->gateway->is_available() ) {
			return;
		}

		// Get the previous cart total.
		$previous_total = WC()->session->wc_amazon_previous_total;

		// Store the current total.
		WC()->session->wc_amazon_previous_total = $cart->total;

		// If the total is non-zero, and we don't know what the previous total was, bail.
		if ( is_null( $previous_total ) || $cart->needs_payment() ) {
			return;
		}

		// This *wasn't* as zero-total order, but is now.
		if ( $previous_total > 0 ) {
			// Force reload, re-rendering standard WC checkout form.
			WC()->session->reload_checkout = true;
		}
	}

	/**
	 * Write a message to log if we're in "debug" mode.
	 *
	 * @since 1.6.0
	 *
	 * @param string $context Context for the log.
	 * @param string $message Log message.
	 */
	public function log( $context, $message ) {
		if ( empty( $this->settings['debug'] ) ) {
			return;
		}

		if ( 'yes' !== $this->settings['debug'] ) {
			return;
		}

		if ( ! is_a( $this->logger, 'WC_Logger' ) ) {
			$this->logger = new WC_Logger();
		}

		$log_message = $context . ' - ' . $message;

		$this->logger->add( 'woocommerce-gateway-amazon-payments-advanced', $log_message );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( $log_message );
		}
	}

	/**
	 * Sanitize log message.
	 *
	 * Used to sanitize logged HTTP response message.
	 *
	 * @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/133
	 * @since 1.6.0
	 *
	 * @param mixed $message Log message.
	 *
	 * @return string Sanitized log message.
	 */
	public function sanitize_remote_response_log( $message ) {
		if ( ! is_a( $message, 'SimpleXMLElement' ) ) {
			return (string) $message;
		}

		if ( ! is_callable( array( $message, 'asXML' ) ) ) {
			return '';
		}

		$message = $message->asXML();

		// Sanitize response message.
		$patterns    = array();
		$patterns[0] = '/(<Buyer>)(.+)(<\/Buyer>)/ms';
		$patterns[1] = '/(<PhysicalDestination>)(.+)(<\/PhysicalDestination>)/ms';
		$patterns[2] = '/(<BillingAddress>)(.+)(<\/BillingAddress>)/ms';
		$patterns[3] = '/(<SellerNote>)(.+)(<\/SellerNote>)/ms';
		$patterns[4] = '/(<AuthorizationBillingAddress>)(.+)(<\/AuthorizationBillingAddress>)/ms';
		$patterns[5] = '/(<SellerAuthorizationNote>)(.+)(<\/SellerAuthorizationNote>)/ms';
		$patterns[6] = '/(<SellerCaptureNote>)(.+)(<\/SellerCaptureNote>)/ms';
		$patterns[7] = '/(<SellerRefundNote>)(.+)(<\/SellerRefundNote>)/ms';

		$replacements    = array();
		$replacements[0] = '$1 REMOVED $3';
		$replacements[1] = '$1 REMOVED $3';
		$replacements[2] = '$1 REMOVED $3';
		$replacements[3] = '$1 REMOVED $3';
		$replacements[4] = '$1 REMOVED $3';
		$replacements[5] = '$1 REMOVED $3';
		$replacements[6] = '$1 REMOVED $3';
		$replacements[7] = '$1 REMOVED $3';

		return preg_replace( $patterns, $replacements, $message );
	}

	/**
	 * Sanitize logged request.
	 *
	 * Used to sanitize logged HTTP request message.
	 *
	 * @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/133
	 * @since 1.6.0
	 *
	 * @param string $message Log message from stringified array structure.
	 *
	 * @return string Sanitized log message
	 */
	public function sanitize_remote_request_log( $message ) {
		$patterns    = array();
		$patterns[0] = '/(AWSAccessKeyId=)(.+)(&)/ms';
		$patterns[0] = '/(SellerNote=)(.+)(&)/ms';
		$patterns[1] = '/(SellerAuthorizationNote=)(.+)(&)/ms';
		$patterns[2] = '/(SellerCaptureNote=)(.+)(&)/ms';
		$patterns[3] = '/(SellerRefundNote=)(.+)(&)/ms';

		$replacements    = array();
		$replacements[0] = '$1REMOVED$3';
		$replacements[1] = '$1REMOVED$3';
		$replacements[2] = '$1REMOVED$3';
		$replacements[3] = '$1REMOVED$3';

		return preg_replace( $patterns, $replacements, $message );
	}

	/**
	 * Register REST API route for /orders/<order-id>/amazon-payments-advanced/.
	 *
	 * @since 1.6.0
	 */
	public function rest_api_register_routes() {
		// Check to make sure WC is activated and its REST API were loaded
		// first.
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		if ( ! isset( WC()->api ) ) {
			return;
		}
		if ( ! is_a( WC()->api, 'WC_API' ) ) {
			return;
		}

		require_once( $this->path . '/includes/class-wc-amazon-payments-advanced-rest-api-controller.php' );

		WC()->api->WC_Amazon_Payments_Advanced_REST_API_Controller = new WC_Amazon_Payments_Advanced_REST_API_Controller();
		WC()->api->WC_Amazon_Payments_Advanced_REST_API_Controller->register_routes();
	}

	/**
	 * Add Amazon reference information in order item response.
	 *
	 * @since 1.6.0
	 *
	 * @param WP_REST_Response $response Response object.
	 * @param WP_Post          $post     Post object.
	 *
	 * @return WP_REST_Response REST response
	 */
	public function rest_api_add_amazon_ref_info( $response, $post ) {
		if ( 'amazon_payments_advanced' === $response->data['payment_method'] ) {
			$response->data['amazon_reference'] = array(

				'amazon_reference_state'     => WC_Amazon_Payments_Advanced_API::get_order_ref_state( $post->ID, 'amazon_reference_state' ),
				'amazon_reference_id'        => get_post_meta( $post->ID, 'amazon_reference_id', true ),
				'amazon_authorization_state' => WC_Amazon_Payments_Advanced_API::get_order_ref_state( $post->ID, 'amazon_authorization_state' ),
				'amazon_authorization_id'    => get_post_meta( $post->ID, 'amazon_authorization_id', true ),
				'amazon_capture_state'       => WC_Amazon_Payments_Advanced_API::get_order_ref_state( $post->ID, 'amazon_capture_state' ),
				'amazon_capture_id'          => get_post_meta( $post->ID, 'amazon_capture_id', true ),
				'amazon_refund_ids'          => get_post_meta( $post->ID, 'amazon_refund_id', false ),
			);
		}

		return $response;
	}

	/**
	 * Get Amazon logout URL.
	 *
	 * @since 1.6.0
	 *
	 * @return string Amazon logout URL
	 */
	public function get_amazon_logout_url() {
		return add_query_arg(
			array(
				'amazon_payments_advanced' => 'true',
				'amazon_logout'            => 'true',
			),
			get_permalink( wc_get_page_id( 'checkout' ) )
		);
	}

	/**
	 * Filter Ajax endpoint so it carries the query string after buyer is
	 * redirected from Amazon.
	 *
	 * Commit 75cc4f91b534ce3114d19da80586bacd083bb5a8 from WC 3.2 replaces the
	 * REQUEST_URI with `/` so that query string from current URL is not carried.
	 * This plugin hooked into checkout related actions/filters that might be
	 * called from Ajax request and expecting some parameters from query string.
	 *
	 * @since 1.8.0
	 *
	 * @param string $url     Ajax URL.
	 * @param string $request Request type. Expecting only 'checkout'.
	 *
	 * @return string URL.
	 */
	public function filter_ajax_endpoint( $url, $request ) {
		if ( 'checkout' !== $request ) {
			return $url;
		}

		if ( ! empty( $_GET['amazon_payments_advanced'] ) ) {
			$url = add_query_arg( 'amazon_payments_advanced', $_GET['amazon_payments_advanced'], $url );
		}
		if ( ! empty( $_GET['access_token'] ) ) {
			$url = add_query_arg( 'access_token', $_GET['access_token'], $url );
		}

		return $url;
	}

	/**
	 * Output admin notices (if any).
	 *
	 * @since 1.8.0
	 * @version 1.8.0
	 */
	public function admin_notices() {
		foreach ( $this->get_admin_notices() as $notice ) {
			if ( 'yes' !== get_option( $notice['dismiss_action'], 'yes' ) ) {
				continue;
			}
			?>
			<div class="notice notice-warning is-dismissible <?php echo esc_attr( $notice['class'] ); ?>">
				<p>
				<?php
				echo wp_kses( $notice['text'], array(
					'a'      => array( 'href' => array(), 'title' => array() ),
					'strong' => array(),
					'em'     => array(),
				) );
				?>
				</p>
				<script type="application/javascript">
				( function( $ ) {
					$( '.<?php echo esc_js( $notice['class'] ); ?>' ).on( 'click', '.notice-dismiss', function() {
						jQuery.post( "<?php echo admin_url( 'admin-ajax.php' ); ?>", {
							action: "amazon_pay_dismiss_notice",
							dismiss_action: "<?php echo esc_js( $notice['dismiss_action'] ); ?>",
							nonce: "<?php echo esc_js( wp_create_nonce( 'amazon_pay_dismiss_notice' ) ); ?>"
						} );
					} );
				} )( jQuery );
				</script>
			</div>
			<?php
		}
	}

	/**
	 * AJAX handler for dismiss notice action.
	 *
	 * @since 1.8.0
	 * @version 1.8.0
	 */
	public function ajax_dismiss_notice() {
		check_ajax_referer( 'amazon_pay_dismiss_notice', 'nonce' );
		foreach ( $this->get_admin_notices() as $notice ) {
			if ( $notice['dismiss_action'] === $_POST['dismiss_action'] ) {
				update_option( $notice['dismiss_action'], 'no' );
				break;
			}
		}
		wp_die();
	}

	/**
	 * Get admin notices.
	 *
	 * @since 1.8.0
	 * @version 1.8.0
	 *
	 * @return array Array of notices.
	 */
	protected function get_admin_notices() {
		$notices = array();

		if ( class_exists( 'WooCommerce_Germanized' ) && 'yes' === get_option( 'woocommerce_gzd_checkout_stop_order_cancellation' ) ) {
			$notices[] = array(
				'dismiss_action' => 'amazon_pay_dismiss_germanized_notice',
				'class'          => 'amazon-pay-wc-germanized-notice',
				'text'           => sprintf( __( '<a href="%s">Disallow cancellation</a> is enabled in WooCommerce Germanized and will cause an issue in Amazon Pay\'s checkout.', 'woocommerce-gateway-amazon-payments-advanced' ), admin_url( 'admin.php?page=wc-settings&tab=germanized' ) ),
			);
		}

		if ( ! WC_Amazon_Payments_Advanced_API::is_region_supports_shop_currency() ) {
			$notices[] = array(
				'dismiss_action' => 'amazon_pay_dismiss_currency_notice',
				'class'          => 'amazon-pay-currency-notice',
				'text'           => sprintf( __( 'Your shop currency <strong>%1$s</strong> does not match with Amazon payment region <strong>%2$s</strong>.', 'woocommerce-gateway-amazon-payments-advanced' ), get_woocommerce_currency(), WC_Amazon_Payments_Advanced_API::get_region_label() ),
			);
		}

		return $notices;
	}
}

/**
 * Return instance of WC_Amazon_Payments_Advanced.
 *
 * @since 1.6.0
 *
 * @return WC_Amazon_Payments_Advanced
 */
function wc_apa() {
	static $plugin;

	if ( ! isset( $plugin ) ) {
		$plugin = new WC_Amazon_Payments_Advanced();
	}

	return $plugin;
}

/**
 * Get order property with compatibility for WC lt 3.0.
 *
 * @since 1.7.0
 *
 * @param WC_Order $order Order object.
 * @param string   $key   Order property.
 *
 * @return mixed Value of order property.
 */
function wc_apa_get_order_prop( $order, $key ) {
	switch ( $key ) {
		case 'order_currency':
			return is_callable( array( $order, 'get_currency' ) ) ? $order->get_currency() : $order->get_order_currency();
			break;
		default:
			$getter = array( $order, 'get_' . $key );
			return is_callable( $getter ) ? call_user_func( $getter ) : $order->{ $key };
	}
}

// Provides backward compatibility.
$GLOBALS['wc_amazon_payments_advanced'] = wc_apa();
