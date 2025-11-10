<?php
/**
 * Whop Payment Gateway Class
 * 
 * @package Whop_WooCommerce
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Whop extends WC_Payment_Gateway {
    
    public $instructions;
    
    public function __construct() {
        $this->id = 'whop_payment';
        $this->icon = WHOP_WC_PLUGIN_URL . 'assets/whop-logo.svg';
        $this->has_fields = false;
        $this->method_title = 'Whop Payment';
        $this->method_description = 'Accept payments securely via Whop with automatic plan creation';
        
        $this->supports = array(
            'products'
        );
        
        $this->init_form_fields();
        $this->init_settings();
        
        $this->title = $this->get_option('title', 'Pay Securely via Whop');
        $this->description = $this->get_option('description', 'Secure payment processing via Whop');
        $this->instructions = $this->get_option('instructions', 'Check your email for your payment link.');
        $this->enabled = $this->get_option('enabled', 'no');
        
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
    }
    
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Enable Whop Payment Gateway',
                'default' => 'no'
            ),
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'description' => 'Payment method title that customers see during checkout',
                'default' => 'Pay Securely via Whop',
                'desc_tip' => true
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'textarea',
                'description' => 'Payment method description that customers see during checkout',
                'default' => 'Secure payment processing via Whop. You will receive a payment link via email.',
                'desc_tip' => true
            ),
            'instructions' => array(
                'title' => 'Instructions',
                'type' => 'textarea',
                'description' => 'Instructions shown on the thank you page',
                'default' => 'Check your email for your secure payment link. Complete payment to confirm your order.',
                'desc_tip' => true
            ),
            'api_status' => array(
                'title' => 'API Status',
                'type' => 'title',
                'description' => $this->get_api_status_html()
            )
        );
    }
    
    private function get_api_status_html() {
        $settings = get_option('whop_wc_settings', array());
        $api_key = isset($settings['api_key']) ? $settings['api_key'] : '';
        $product_id = isset($settings['product_id']) ? $settings['product_id'] : '';
        
        if (empty($api_key) || empty($product_id)) {
            return '<div style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin-top: 10px;">
                <strong>⚠️ Configuration Required</strong><br>
                Please <a href="' . admin_url('admin.php?page=whop-integration') . '">configure your API settings</a> before enabling this gateway.
            </div>';
        }
        
        return '<div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin-top: 10px;">
            <strong>✓ API Configured</strong><br>
            API credentials are set. <a href="' . admin_url('admin.php?page=whop-integration') . '">Manage settings</a>
        </div>';
    }
    
    public function admin_options() {
        ?>
        <h2><?php echo esc_html($this->get_method_title()); ?></h2>
        <p><?php echo esc_html($this->get_method_description()); ?></p>
        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
        <?php
    }
    
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wc_add_notice('Unable to process payment. Please try again.', 'error');
            return array('result' => 'fail');
        }
        
        // Check if API is configured
        $settings = get_option('whop_wc_settings', array());
        if (empty($settings['api_key']) || empty($settings['product_id'])) {
            $order->add_order_note('Payment failed: Whop API not configured');
            wc_add_notice('Payment gateway is not properly configured. Please contact support.', 'error');
            return array('result' => 'fail');
        }
        
        // Mark order as on-hold (awaiting payment)
        $order->update_status('on-hold', 'Awaiting Whop payment confirmation');
        
        // Reduce stock levels
        wc_reduce_stock_levels($order_id);
        
        // Remove cart
        WC()->cart->empty_cart();
        
        // Add order note
        $order->add_order_note('Customer initiated Whop payment. Awaiting payment link generation.');
        
        $integration = Whop_WooCommerce_Integration::instance();
        
        if ($integration && $integration->is_direct_redirect_enabled()) {
            $session = $integration->ensure_payment_session($order, array(
                'send_email' => true,
                'add_note' => true
            ));
            
            if (is_wp_error($session) || empty($session['url'])) {
                $message = is_wp_error($session) ? $session->get_error_message() : __('Unable to start Whop checkout session.', 'whop-woocommerce');
                wc_add_notice($message, 'error');
                $order->add_order_note('Whop checkout redirect failed: ' . $message);
                
                return array('result' => 'fail');
            }
            
            return array(
                'result' => 'success',
                'redirect' => $session['url']
            );
        }
        
        // Return success and redirect to order received page
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }
    
    public function thankyou_page($order_id) {
        $instructions = trim((string) $this->instructions);

        if ($instructions === '') {
            return;
        }

        if (!apply_filters('whop_wc_display_thankyou_instructions', false, $instructions, $order_id, $this)) {
            return;
        }

        echo '<div class="whop-instructions">';
        echo wp_kses_post(wpautop(wptexturize($instructions)));
        echo '</div>';
    }
    
    public function email_instructions($order, $sent_to_admin, $plain_text = false) {
        if ($this->instructions && !$sent_to_admin && $this->id === $order->get_payment_method() && $order->has_status('on-hold')) {
            echo wp_kses_post(wpautop(wptexturize($this->instructions)) . PHP_EOL);
        }
    }
    
    public function validate_fields() {
        // Check if API is configured before allowing payment
        $settings = get_option('whop_wc_settings', array());
        if (empty($settings['api_key']) || empty($settings['product_id'])) {
            wc_add_notice('Whop payment is temporarily unavailable. Please choose another payment method or contact support.', 'error');
            return false;
        }
        
        return true;
    }
}
