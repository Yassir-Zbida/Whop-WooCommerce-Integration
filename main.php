<?php
/**
 * Plugin Name: Whop WooCommerce Integration
 * Description: Professional Whop payment gateway integration with api configurable settings
 * Version: 3.0.1
 * Text Domain: whop-woocommerce
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WHOP_WC_VERSION', '3.0.1');
define('WHOP_WC_PLUGIN_FILE', __FILE__);
define('WHOP_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WHOP_WC_PLUGIN_URL', plugin_dir_url(__FILE__));

add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Main plugin class
class Whop_WooCommerce_Plugin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'), 11);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        register_activation_hook(__FILE__, array($this, 'activate'));
    }
    
    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        $this->load_gateway_class();
        new Whop_WooCommerce_Integration();
    }
    
    public function enqueue_admin_assets($hook) {
        if ('woocommerce_page_whop-integration' !== $hook && 'toplevel_page_whop-integration' !== $hook) {
            return;
        }
        
        // Enqueue Remix Icons
        wp_enqueue_style(
            'remixicon',
            'https://cdn.jsdelivr.net/npm/remixicon@4.2.0/fonts/remixicon.css',
            array(),
            '4.2.0'
        );
        
        
        
        wp_enqueue_style(
            'whop-admin-css',
            WHOP_WC_PLUGIN_URL . 'assets/admin-style.css',
            array(),
            WHOP_WC_VERSION
        );
        
        wp_enqueue_script(
            'whop-admin-js',
            WHOP_WC_PLUGIN_URL . 'assets/admin-script.js',
            array('jquery'),
            WHOP_WC_VERSION,
            true
        );
        
        wp_localize_script('whop-admin-js', 'whopAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('whop_admin_nonce')
        ));
    }
    
    public function enqueue_frontend_assets() {
        if (is_order_received_page() || is_checkout()) {
            wp_enqueue_style(
                'remixicon',
                'https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css',
                array(),
                '3.5.0'
            );

            wp_enqueue_style(
                'whop-frontend-css',
                WHOP_WC_PLUGIN_URL . 'assets/frontend-style.css',
                array(),
                WHOP_WC_VERSION
            );
        }
    }
    
    private function load_gateway_class() {
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }
        
        require_once WHOP_WC_PLUGIN_DIR . 'includes/class-gateway.php';
    }
    
    public function activate() {
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('This plugin requires WooCommerce to be installed and active.');
        }
        
        // Create default settings
        $defaults = array(
            'api_key' => '',
            'product_id' => '',
            'enabled' => 'no',
            'test_mode' => 'yes',
            'checkout_mode' => 'link'
        );
        
        if (!get_option('whop_wc_settings')) {
            add_option('whop_wc_settings', $defaults);
        }
        
        set_transient('whop_wc_activation', true, 30);
    }
    
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><i class="ri-error-warning-line"></i> <strong>Whop WooCommerce Integration</strong> requires WooCommerce to be installed and active.</p>
        </div>
        <?php
    }
}

// Initialize plugin
Whop_WooCommerce_Plugin::get_instance();

// Main integration class
class Whop_WooCommerce_Integration {
    
    private static $instance = null;
    
    private $settings;
    
    private $default_settings = array(
        'api_key' => '',
        'product_id' => '',
        'checkout_mode' => 'link',
        'enabled' => 'no',
        'test_mode' => 'yes'
    );
    
    public function __construct() {
        self::$instance = $this;
        $saved_settings = get_option('whop_wc_settings', array());
        $this->settings = wp_parse_args($saved_settings, $this->default_settings);
        
        add_action('woocommerce_checkout_order_processed', array($this, 'generate_whop_payment_link'), 10, 3);
        add_action('woocommerce_email_before_order_table', array($this, 'add_payment_link_to_email'), 10, 4);
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
        add_action('woocommerce_thankyou', array($this, 'add_payment_link_to_thankyou_page'), 1);
        add_action('add_meta_boxes', array($this, 'add_whop_meta_box'));
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_head', array($this, 'style_admin_menu_icon'));
        add_filter('woocommerce_payment_gateways', array($this, 'add_whop_gateway'));
        
        // AJAX handlers
        add_action('wp_ajax_whop_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_whop_test_connection', array($this, 'ajax_test_connection'));
    }
    
    public function add_whop_gateway($gateways) {
        $gateways[] = 'WC_Gateway_Whop';
        return $gateways;
    }
    
    public static function instance() {
        return self::$instance;
    }
    
    private function get_setting($key, $default = '') {
        if (isset($this->settings[$key])) {
            return $this->settings[$key];
        }
        
        if (isset($this->default_settings[$key])) {
            return $this->default_settings[$key];
        }
        
        return $default;
    }
    
    public function is_direct_redirect_enabled() {
        return $this->get_setting('checkout_mode', 'link') === 'redirect';
    }
    
    private function get_api_url() {
        return 'https://api.whop.com/api/v2';
    }
    
    private function make_api_request($endpoint, $method = 'GET', $body = null) {
        $api_key = $this->get_setting('api_key');
        
        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'API key not configured');
        }
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30,
            'method' => $method
        );
        
        if ($body !== null) {
            $args['body'] = json_encode($body);
        }
        
        $url = $this->get_api_url() . $endpoint;
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if ($response_code < 200 || $response_code >= 300) {
            $error_message = isset($data['error']['message']) ? $data['error']['message'] : 'API request failed';
            return new WP_Error('api_error', $error_message, array('code' => $response_code, 'data' => $data));
        }
        
        return $data;
    }
    
    private function create_plan_with_order_amount($order_id, $amount, $currency = 'usd') {
        $product_id = $this->get_setting('product_id');
        
        if (empty($product_id)) {
            $this->log('Error: Product ID not configured', 'error');
            return false;
        }
        
        $body = array(
            'product_id' => $product_id,
            'plan_type' => 'one_time',
            'billing_period' => 0,
            'internal_notes' => 'WooCommerce Order #' . $order_id,
            'release_method' => 'buy_now',
            'visibility' => 'visible',
            'direct_link_only' => false,
            'stock' => 1,
            'initial_price' => floatval($amount),
            'currency' => strtolower($currency),
            'accepted_payment_methods' => array('card', 'paypal', 'apple_pay', 'google_pay'),
            'metadata' => array(
                'woo_order_id' => (string)$order_id,
                'created_by' => 'woocommerce_plugin',
                'plugin_version' => WHOP_WC_VERSION
            )
        );
        
        $this->log('Creating plan for Order #' . $order_id . ' - Amount: $' . $amount);
        
        $result = $this->make_api_request('/plans', 'POST', $body);
        
        if (is_wp_error($result)) {
            $this->log('Plan creation failed: ' . $result->get_error_message(), 'error');
            return false;
        }
        
        $plan_id = isset($result['id']) ? $result['id'] : null;
        
        if ($plan_id) {
            $this->log('Plan created successfully! Plan ID: ' . $plan_id);
            return $plan_id;
        }
        
        return false;
    }
    
    public function ensure_payment_session($order, $args = array()) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }
        
        if (!$order) {
            return new WP_Error('invalid_order', __('Invalid order.', 'whop-woocommerce'));
        }
        
        $args = wp_parse_args($args, array(
            'send_email' => true,
            'add_note' => true
        ));
        
        if ($order->get_payment_method() !== 'whop_payment') {
            return new WP_Error('invalid_method', __('Order is not using the Whop payment method.', 'whop-woocommerce'));
        }
        
        $existing_url = $order->get_meta('_whop_payment_url', true);
        $existing_status = $order->get_meta('_whop_payment_status', true);
        
        if ($existing_url && $existing_status !== 'completed') {
            if ($args['send_email']) {
                $this->send_customer_email($order);
            }
            
            return array(
                'url' => $existing_url,
                'plan_id' => $order->get_meta('_whop_plan_id', true),
                'checkout_id' => $order->get_meta('_whop_checkout_id', true),
                'status' => $existing_status ?: 'pending'
            );
        }
        
        $email = $order->get_billing_email();
        if (empty($email)) {
            $order->add_order_note(__('Whop payment error: Missing customer email.', 'whop-woocommerce'));
            $this->log('Order #' . $order->get_id() . ' - Missing customer email', 'error');
            return new WP_Error('missing_email', __('Customer email address is required to create a Whop checkout.', 'whop-woocommerce'));
        }
        
        $plan_id = $this->create_plan_with_order_amount($order->get_id(), $order->get_total(), $order->get_currency());
        
        if (!$plan_id) {
            $order->add_order_note(sprintf(__('Whop payment error: Failed to create plan for %s.', 'whop-woocommerce'), $order->get_formatted_order_total()));
            return new WP_Error('plan_creation_failed', __('Unable to create a Whop plan for this order.', 'whop-woocommerce'));
        }
        
        $success_url = add_query_arg(
            array('order_id' => $order->get_id(), 'key' => $order->get_order_key()),
            wc_get_endpoint_url('order-received', $order->get_id(), wc_get_checkout_url())
        );
        
        $body = array(
            'plan_id' => $plan_id,
            'redirect_url' => $success_url,
            'metadata' => array(
                'woo_order_id' => (string) $order->get_id(),
                'order_key' => $order->get_order_key(),
                'order_total' => (string) $order->get_total(),
                'order_currency' => $order->get_currency()
            )
        );
        
        $result = $this->make_api_request('/checkout_sessions', 'POST', $body);
        
        if (is_wp_error($result)) {
            $order->add_order_note(__('Whop payment error: ') . $result->get_error_message());
            $this->log('Checkout session failed: ' . $result->get_error_message(), 'error');
            return $result;
        }
        
        $payment_url = isset($result['purchase_url']) ? $result['purchase_url'] : (isset($result['url']) ? $result['url'] : null);
        
        if (!$payment_url) {
            $order->add_order_note(__('Whop payment error: No checkout URL received from API.', 'whop-woocommerce'));
            $this->log('No payment URL in checkout session response', 'error');
            return new WP_Error('missing_payment_url', __('No payment URL was returned by Whop.', 'whop-woocommerce'));
        }
        
        $order->update_meta_data('_whop_payment_url', sanitize_url($payment_url));
        $order->update_meta_data('_whop_plan_id', $plan_id);
        $order->update_meta_data('_whop_checkout_id', isset($result['id']) ? $result['id'] : '');
        $order->update_meta_data('_whop_payment_status', 'pending');
        $order->save();
        
        if ($args['add_note']) {
            $order->add_order_note(sprintf(__('Whop payment link created (Plan: %1$s, Amount: %2$s).', 'whop-woocommerce'), $plan_id, $order->get_formatted_order_total()));
        }
        
        if ($args['send_email']) {
            $this->send_customer_email($order);
        }
        
        $this->log('Payment link created successfully for Order #' . $order->get_id());
        
        return array(
            'url' => $payment_url,
            'plan_id' => $plan_id,
            'checkout_id' => isset($result['id']) ? $result['id'] : '',
            'status' => 'pending'
        );
    }
    
    public function generate_whop_payment_link($order_id, $posted_data, $order) {
        if ($order->get_payment_method() !== 'whop_payment') {
            return;
        }
        
        $result = $this->ensure_payment_session($order, array(
            'send_email' => true,
            'add_note' => true
        ));
        
        if (is_wp_error($result)) {
            $this->log('Failed to prepare Whop checkout for Order #' . $order_id . ': ' . $result->get_error_message(), 'error');
        }
    }
    
    public function add_payment_link_to_email($order, $sent_to_admin, $plain_text, $email) {
        if ($sent_to_admin || $plain_text || $order->get_payment_method() !== 'whop_payment') {
            return;
        }
        
        $url = $order->get_meta('_whop_payment_url', true);
        $status = $order->get_meta('_whop_payment_status', true);
        
        if ($url && $status !== 'completed') {
            ?>
            <div style="margin: 30px 0; padding: 30px; background: #ffffff; border: 2px solid #000000; border-radius: 12px; text-align: center;">
                <h2 style="color: #000000; margin: 0 0 15px 0; font-size: 24px;">Complete Your Payment</h2>
                <p style="color: #666666; margin: 0 0 25px 0; font-size: 16px;">Click below to securely complete your payment</p>
                <a href="<?php echo esc_url($url); ?>" 
                   target="_blank" 
                   style="display: inline-block; padding: 15px 40px; background: #000000; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 16px;">
                    Pay Now - <?php echo $order->get_formatted_order_total(); ?>
                </a>
                <p style="color: #999999; font-size: 13px; margin: 20px 0 0 0;">
                    Order #<?php echo $order->get_order_number(); ?>
                </p>
            </div>
            <?php
        }
    }
    
    private function send_customer_email($order) {
        if ($order->get_meta('_whop_email_sent', true) === 'yes') {
            return;
        }
        
        $mailer = WC()->mailer();
        $emails = $mailer->get_emails();
        
        foreach ($emails as $email) {
            if (is_a($email, 'WC_Email_Customer_On_Hold_Order')) {
                $email->trigger($order->get_id());
                $order->update_meta_data('_whop_email_sent', 'yes');
                $order->save();
                return;
            }
        }
    }
    
    public function add_payment_link_to_thankyou_page($order_id) {
        if (!$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'whop_payment') {
            return;
        }
        
        $url = $order->get_meta('_whop_payment_url', true);
        $status = $order->get_meta('_whop_payment_status', true);
        
        if ($url && $status !== 'completed' && !$order->is_paid()) {
            ?>
            <div class="whop-payment-box">
                <h2 class="whop-payment-title">
                    <i class="ri-wallet-line" aria-hidden="true"></i>
                    <?php esc_html_e('Complete your payment', 'whop-woocommerce'); ?>
                </h2>
                <p class="whop-payment-description">
                    <?php
                    printf(
                        esc_html__('Finish paying %1$s to confirm order #%2$s.', 'whop-woocommerce'),
                        $order->get_formatted_order_total(),
                        $order->get_order_number()
                    );
                    ?>
                </p>
                <p class="whop-payment-action">
                    <a href="<?php echo esc_url($url); ?>" target="_blank" class="button whop-pay-button">
                        <?php
                        printf(
                            esc_html__('Pay now - %s', 'whop-woocommerce'),
                            $order->get_formatted_order_total()
                        );
                        ?>
                    </a>
                </p>
                <?php if ($order->get_billing_email()) : ?>
                    <p class="whop-payment-meta">
                        <?php
                        printf(
                            esc_html__('We also emailed the link to %s.', 'whop-woocommerce'),
                            esc_html($order->get_billing_email())
                        );
                        ?>
                    </p>
                <?php endif; ?>
            </div>
            <?php
        }
    }
    
    public function register_webhook_endpoint() {
        register_rest_route('whop/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true'
        ));
    }
    
    public function handle_webhook($request) {
        $data = json_decode($request->get_body(), true);
        
        $this->log('Webhook received: ' . $request->get_body());
        
        if (empty($data['action'])) {
            return new WP_REST_Response(array('error' => 'Invalid webhook - no action'), 400);
        }
        
        if ($data['action'] === 'payment.succeeded' || $data['action'] === 'checkout.paid') {
            return $this->handle_payment_success($data);
        }
        
        return new WP_REST_Response(array('status' => 'ok', 'message' => 'Webhook received'), 200);
    }
    
    private function handle_payment_success($data) {
        $order_id = intval($data['data']['metadata']['woo_order_id'] ?? $data['metadata']['woo_order_id'] ?? 0);
        
        if (!$order_id) {
            $this->log('Webhook error: No order ID in webhook data', 'error');
            return new WP_REST_Response(array('error' => 'No order ID'), 400);
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log('Webhook error: Order #' . $order_id . ' not found', 'error');
            return new WP_REST_Response(array('error' => 'Order not found'), 404);
        }
        
        if ($order->get_meta('_whop_payment_status', true) === 'completed') {
            return new WP_REST_Response(array('status' => 'already_processed'), 200);
        }
        
        $order->update_meta_data('_whop_payment_status', 'completed');
        $order->update_meta_data('_whop_payment_date', current_time('mysql'));
        $order->save();
        
        $order->payment_complete();
        $order->add_order_note('Payment completed via Whop webhook');
        
        $this->log('Payment completed for Order #' . $order_id);
        
        return new WP_REST_Response(array('status' => 'success'), 200);
    }
    
    public function add_whop_meta_box() {
        add_meta_box(
            'whop_payment_info',
            'Whop Payment Information',
            array($this, 'render_whop_meta_box'),
            'shop_order',
            'side',
            'high'
        );
        
        add_meta_box(
            'whop_payment_info',
            'Whop Payment Information',
            array($this, 'render_whop_meta_box'),
            'woocommerce_page_wc-orders',
            'side',
            'high'
        );
    }
    
    public function render_whop_meta_box($post_or_order) {
        if ($post_or_order instanceof WC_Order) {
            $order = $post_or_order;
        } else {
            $order_id = $post_or_order instanceof WP_Post ? $post_or_order->ID : $post_or_order;
            $order = wc_get_order($order_id);
        }

        if (!$order) {
            echo '<p style="color: #999; font-style: italic;">Order data not available.</p>';
            return;
        }

        $url = $order->get_meta('_whop_payment_url', true);
        $plan_id = $order->get_meta('_whop_plan_id', true);
        $status = $order->get_meta('_whop_payment_status', true);
        $payment_date = $order->get_meta('_whop_payment_date', true);
        
        ?>
        <style>
            .whop-meta-box { font-size: 13px; }
            .whop-meta-row { margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #f0f0f0; }
            .whop-meta-row:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
            .whop-meta-label { font-weight: 600; color: #000; margin-bottom: 4px; }
            .whop-meta-value { color: #666; word-break: break-all; }
            .whop-status { display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
            .whop-status-pending { background: #fff3cd; color: #856404; }
            .whop-status-completed { background: #d4edda; color: #155724; }
            .whop-button { display: inline-block; padding: 8px 16px; background: #000; color: #fff; text-decoration: none; border-radius: 6px; font-size: 12px; font-weight: 600; margin-top: 10px; }
            .whop-button:hover { background: #333; color: #fff; }
        </style>
        
        <div class="whop-meta-box">
            <?php if ($url): ?>
                <div class="whop-meta-row">
                    <div class="whop-meta-label">Status</div>
                    <div class="whop-meta-value">
                        <span class="whop-status whop-status-<?php echo esc_attr($status ?: 'pending'); ?>">
                            <?php echo esc_html(ucfirst($status ?: 'pending')); ?>
                        </span>
                    </div>
                </div>
                
                <?php if ($plan_id): ?>
                <div class="whop-meta-row">
                    <div class="whop-meta-label">Plan ID</div>
                    <div class="whop-meta-value">
                        <code style="font-size: 11px;"><?php echo esc_html($plan_id); ?></code>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($payment_date): ?>
                <div class="whop-meta-row">
                    <div class="whop-meta-label">Payment Date</div>
                    <div class="whop-meta-value">
                        <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($payment_date))); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <a href="<?php echo esc_url($url); ?>" target="_blank" class="whop-button">
                    View Payment Link
                </a>
            <?php else: ?>
                <p style="color: #999; font-style: italic;">No payment link generated yet</p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function add_settings_page() {
        add_menu_page(
            'Whop Integration',
            'Whop',
            'manage_woocommerce',
            'whop-integration',
            array($this, 'render_settings_page'),
            WHOP_WC_PLUGIN_URL . 'assets/icon.svg',
            56
        );
    }

    public function style_admin_menu_icon() {
        ?>
        <style>
            #toplevel_page_whop-integration .wp-menu-image img {
                width: 20px;
                height: 20px;
                padding: 0;
                margin-top: 6px;
                filter: brightness(0) invert(1);
                opacity: 0.6;
            }

            #toplevel_page_whop-integration:hover .wp-menu-image img,
            #toplevel_page_whop-integration.wp-has-current-submenu .wp-menu-image img {
                opacity: 1;
            }
        </style>
        <?php
    }
    
    public function render_settings_page() {
        $settings = $this->settings;
        $webhook_url = rest_url('whop/v1/webhook');
        $checkout_mode = $this->get_setting('checkout_mode', 'link');
        ?>
        <div class="wrap whop-settings-wrap">
            <h1>
                <img 
                    src="<?php echo esc_url(WHOP_WC_PLUGIN_URL . 'assets/icon.svg'); ?>" 
                    alt="whop" 
                    class="whop-heading-icon" 
                    aria-hidden="true"
                    width="34"
                />
                Whop WooCommerce Integration
            </h1>
            <p class="whop-version">Version <?php echo WHOP_WC_VERSION; ?></p>
            
            <div class="whop-settings-container">
                <!-- API Configuration -->
                <div class="whop-card">
                    <div class="whop-card-header">
                        <h2><i class="ri-key-line"></i> API Configuration</h2>
                    </div>
                    <div class="whop-card-body">
                        <form id="whop-settings-form">
                            <div class="whop-form-group">
                                <label for="whop_api_key">
                                    <i class="ri-key-2-line"></i> API Key
                                    <span class="required">*</span>
                                </label>
                                <input 
                                    type="password" 
                                    id="whop_api_key" 
                                    name="api_key" 
                                    value="<?php echo esc_attr($this->get_setting('api_key')); ?>"
                                    placeholder="WHYJKO6_..."
                                    class="whop-input"
                                    required
                                />
                                <p class="whop-description">
                                    Get your API key from <a href="https://whop.com/developer" target="_blank">Whop Developer Dashboard</a>
                                </p>
                            </div>
                            
                            <div class="whop-form-group">
                                <label for="whop_product_id">
                                    <i class="ri-product-hunt-line"></i> Product ID
                                    <span class="required">*</span>
                                </label>
                                <input 
                                    type="text" 
                                    id="whop_product_id" 
                                    name="product_id" 
                                    value="<?php echo esc_attr($this->get_setting('product_id')); ?>"
                                    placeholder="prod_..."
                                    class="whop-input"
                                    required
                                />
                                <p class="whop-description">
                                    Your Whop product ID where plans will be created
                                </p>
                            </div>
                            
                            <div class="whop-form-group">
                                <label>
                                    <i class="ri-exchange-line"></i> <?php esc_html_e('Checkout experience', 'whop-woocommerce'); ?>
                                </label>
                                <div class="whop-radio-group">
                                    <label class="whop-radio-option">
                                        <input 
                                            type="radio" 
                                            name="checkout_mode" 
                                            value="link"
                                            <?php checked($checkout_mode, 'link'); ?>
                                        />
                                        <div class="whop-radio-copy">
                                            <span class="whop-radio-label"><?php esc_html_e('Show payment link', 'whop-woocommerce'); ?></span>
                                            <p class="whop-radio-desc">
                                                <?php esc_html_e('Keep customers on the WooCommerce thank you page and display a payment button they can use at any time.', 'whop-woocommerce'); ?>
                                            </p>
                                        </div>
                                    </label>
                                    <label class="whop-radio-option">
                                        <input 
                                            type="radio" 
                                            name="checkout_mode" 
                                            value="redirect"
                                            <?php checked($checkout_mode, 'redirect'); ?>
                                        />
                                        <div class="whop-radio-copy">
                                            <span class="whop-radio-label"><?php esc_html_e('Redirect to Whop checkout', 'whop-woocommerce'); ?></span>
                                            <p class="whop-radio-desc">
                                                <?php esc_html_e('Automatically send customers straight to the Whop checkout session after placing the order. They return to your thank-you page once payment is completed.', 'whop-woocommerce'); ?>
                                            </p>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="whop-form-actions">
                                <button type="button" id="whop-test-connection" class="whop-button whop-button-secondary">
                                    <i class="ri-plug-line"></i> Test Connection
                                </button>
                                <button type="submit" class="whop-button whop-button-primary">
                                    <i class="ri-save-line"></i> Save Settings
                                </button>
                            </div>
                            
                            <div id="whop-test-result" style="display: none;"></div>
                        </form>
                    </div>
                </div>
                
                <!-- Webhook Configuration -->
                <div class="whop-card">
                    <div class="whop-card-header">
                        <h2><i class="ri-webhook-line"></i> Webhook Configuration</h2>
                    </div>
                    <div class="whop-card-body">
                        <div class="whop-form-group">
                            <label><i class="ri-link"></i> Webhook URL</label>
                            <div class="whop-input-group">
                                <input 
                                    type="text" 
                                    value="<?php echo esc_url($webhook_url); ?>" 
                                    readonly 
                                    class="whop-input"
                                    id="webhook-url"
                                />
                                <button type="button" class="whop-button whop-button-secondary" onclick="copyWebhookUrl()">
                                    <i class="ri-file-copy-line"></i> Copy
                                </button>
                            </div>
                            <p class="whop-description">
                                Add this webhook URL to your Whop Dashboard:<br>
                                <strong>Developer Settings → Webhooks → Add Webhook</strong>
                            </p>
                        </div>
                        
                        <div class="whop-info-box">
                            <i class="ri-information-line"></i>
                            <div>
                                <strong>Required Events:</strong>
                                <ul>
                                    <li><code>payment.succeeded</code> - When payment is completed</li>
                                    <li><code>checkout.paid</code> - Alternative payment completion event</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Start Guide -->
                <div class="whop-card">
                    <div class="whop-card-header">
                        <h2><i class="ri-rocket-line"></i> Quick Start Guide</h2>
                    </div>
                    <div class="whop-card-body">
                        <ol class="whop-checklist">
                            <li>
                                <i class="ri-checkbox-circle-line"></i>
                                <div>
                                    <strong>Configure API Settings</strong>
                                    <p>Enter your Whop API key and Product ID above</p>
                                </div>
                            </li>
                            <li>
                                <i class="ri-checkbox-circle-line"></i>
                                <div>
                                    <strong>Test Connection</strong>
                                    <p>Click "Test Connection" to verify your credentials</p>
                                </div>
                            </li>
                            <li>
                                <i class="ri-checkbox-circle-line"></i>
                                <div>
                                    <strong>Enable Payment Gateway</strong>
                                    <p>
                                        <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=checkout&section=whop_payment'); ?>">
                                            Go to WooCommerce → Settings → Payments → Whop
                                        </a>
                                    </p>
                                </div>
                            </li>
                            <li>
                                <i class="ri-checkbox-circle-line"></i>
                                <div>
                                    <strong>Configure Webhook</strong>
                                    <p>Copy the webhook URL above and add it to your Whop dashboard</p>
                                </div>
                            </li>
                            <li>
                                <i class="ri-checkbox-circle-line"></i>
                                <div>
                                    <strong>Test Order</strong>
                                    <p>Create a test order to verify everything works correctly</p>
                                </div>
                            </li>
                        </ol>
                    </div>
                </div>
                
                <!-- How It Works -->
                <div class="whop-card">
                    <div class="whop-card-header">
                        <h2><i class="ri-lightbulb-line"></i> How It Works</h2>
                    </div>
                    <div class="whop-card-body">
                        <div class="whop-info-box">
                            <i class="ri-information-line"></i>
                            <div>
                                <p><strong>Automatic Plan Creation:</strong> Each WooCommerce order automatically creates a new Whop plan with the exact order amount. This ensures prices always match perfectly.</p>
                                
                                <p><strong>Payment Flow:</strong></p>
                                <ol>
                                    <li>Customer completes checkout with Whop payment method</li>
                                    <li>Plugin creates a Whop plan with exact order total</li>
                                    <li>Checkout session is created with payment link</li>
                                    <li>Payment link is displayed on thank you page and sent via email</li>
                                    <li>When customer pays, webhook updates WooCommerce order status</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        function copyWebhookUrl() {
            const input = document.getElementById('webhook-url');
            input.select();
            document.execCommand('copy');
            
            const button = event.target.closest('button');
            const originalHTML = button.innerHTML;
            button.innerHTML = '<i class="ri-check-line"></i> Copied!';
            button.style.background = '#28a745';
            
            setTimeout(() => {
                button.innerHTML = originalHTML;
                button.style.background = '';
            }, 2000);
        }
        </script>
        <?php
    }
    
    public function ajax_save_settings() {
        check_ajax_referer('whop_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'whop-woocommerce')), 403);
        }
        
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        $product_id = sanitize_text_field($_POST['product_id'] ?? '');
        $checkout_mode = sanitize_text_field($_POST['checkout_mode'] ?? 'link');
        
        if (!in_array($checkout_mode, array('link', 'redirect'), true)) {
            $checkout_mode = 'link';
        }
        
        if (empty($api_key) || empty($product_id)) {
            wp_send_json_error(array('message' => __('API Key and Product ID are required.', 'whop-woocommerce')));
        }
        
        $settings = get_option('whop_wc_settings', array());
        if (!is_array($settings)) {
            $settings = array();
        }

        $settings = wp_parse_args($settings, $this->default_settings);
        $settings['api_key'] = $api_key;
        $settings['product_id'] = $product_id;
        $settings['checkout_mode'] = $checkout_mode;
        
        update_option('whop_wc_settings', $settings);
        $this->settings = $settings;
        
        wp_send_json_success(array(
            'message' => __('Settings saved successfully.', 'whop-woocommerce'),
            'settings' => array(
                'checkout_mode' => $checkout_mode
            )
        ));
    }
    
    public function ajax_test_connection() {
        check_ajax_referer('whop_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'), 403);
        }
        
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        $product_id = sanitize_text_field($_POST['product_id'] ?? '');
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'API Key is required'));
        }
        
        // Test API connection
        $response = wp_remote_get($this->get_api_url() . '/products/' . $product_id, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => 'Connection failed: ' . $response->get_error_message()
            ));
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($response_code === 200) {
            $product_name = isset($body['name']) ? $body['name'] : 'Unknown';
            wp_send_json_success(array(
                'message' => 'Connection successful! Product found: ' . $product_name
            ));
        } elseif ($response_code === 401) {
            wp_send_json_error(array(
                'message' => 'Invalid API Key'
            ));
        } elseif ($response_code === 404) {
            wp_send_json_error(array(
                'message' => 'Product ID not found'
            ));
        } else {
            $error_message = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown error';
            wp_send_json_error(array(
                'message' => 'API Error: ' . $error_message
            ));
        }
    }
    
    private function log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $prefix = '[Whop WC] ';
            if ($level === 'error') {
                error_log($prefix . 'ERROR: ' . $message);
            } else {
                error_log($prefix . $message);
            }
        }
    }
}

// Activation notice
add_action('admin_notices', function() {
    if (get_transient('whop_wc_activation')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <h3><i class="ri-checkbox-circle-line"></i> Whop Integration Activated!</h3>
            <p><strong>Next steps:</strong></p>
            <ol>
                <li><a href="<?php echo admin_url('admin.php?page=whop-integration'); ?>">Configure API settings</a></li>
                <li>Test your API connection</li>
                <li><a href="<?php echo admin_url('admin.php?page=wc-settings&tab=checkout&section=whop_payment'); ?>">Enable the payment gateway</a></li>
            </ol>
        </div>
        <?php
        delete_transient('whop_wc_activation');
    }
});
