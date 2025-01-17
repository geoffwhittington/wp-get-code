<?php


if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Get Code Payment Gateway.
 *
 * Provides a GetCode Payment Gateway, mainly for testing purposes.
 */
add_action('plugins_loaded', 'init_get_code_gateway_class');

function init_get_code_gateway_class(){
    class WC_Gateway_Get_Code_Payment extends WC_Payment_Gateway {

        public $domain;

        /**
         * Constructor for the gateway.
         */
        public function __construct() {

            $this->domain = 'get_code_payment';

            $this->id                 = 'get_code';
            $this->icon               = apply_filters('woocommerce_get_code_gateway_icon', '');
            $this->has_fields         = false;
            $this->method_title       = __( 'GetCode Checkout', $this->domain );
            $this->method_description = __( 'Allows payments with Get Code gateway.', $this->domain );

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title        = $this->get_option( 'title' );
            $this->description  = $this->get_option( 'description' );
            $this->instructions = $this->get_option( 'instructions', $this->description );
            $this->order_status = $this->get_option( 'order_status', 'completed' );

            // Actions
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_thankyou_'. $this->id, array( $this, 'thankyou_page' ) );

            // Get Codeer Emails
            add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
        }

        /**
         * Initialise Gateway Settings Form Fields.
         */
        public function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __( 'Enable/Disable', $this->domain ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable Get Code Payment', $this->domain ),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title'       => __( 'Title', $this->domain ),
                    'type'        => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', $this->domain ),
                    'default'     => __( 'Get Code Payment', $this->domain ),
                    'desc_tip'    => true,
                ),
                'order_status' => array(
                    'title'       => __( 'Order Status', $this->domain ),
                    'type'        => 'select',
                    'class'       => 'wc-enhanced-select',
                    'description' => __( 'Choose whether status you wish after checkout.', $this->domain ),
                    'default'     => 'wc-completed',
                    'desc_tip'    => true,
                    'options'     => wc_get_order_statuses()
                ),
                'description' => array(
                    'title'       => __( 'Description', $this->domain ),
                    'type'        => 'textarea',
                    'description' => __( 'Payment method description that the get_codeer will see on your checkout.', $this->domain ),
                    'default'     => __('Payment Information', $this->domain),
                    'desc_tip'    => true,
                ),
                'instructions' => array(
                    'title'       => __( 'Instructions', $this->domain ),
                    'type'        => 'textarea',
                    'description' => __( 'Instructions that will be added to the thank you page and emails.', $this->domain ),
                    'default'     => '',
                    'desc_tip'    => true,
                ),
            );
        }

        /**
         * Output for the order received page.
         */
        public function thankyou_page() {
            if ( $this->instructions )
                echo wpautop( wptexturize( $this->instructions ) );
        }

        /**
         * Add content to the WC emails.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         */
        public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
            if ( $this->instructions && ! $sent_to_admin && 'get_code' === $order->payment_method && $order->has_status( 'on-hold' ) ) {
                echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
            }
        }

        public function payment_fields(){

            if ( $description = $this->get_description() ) {
                echo wpautop( wptexturize( $description ) );
            }

?>
            <div id="get-code-button-checkout">
                <button id="init-code-checkout-app" style="width: 200px; height: 60px; background: black; border-radius: 5px; color: white;"  onclick="initCodeCheckoutApp()">Proceed</button>
            </div>
            <?php
        }

        /**
         * Process the payment and return the result.
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment( $order_id ) {

            $order = wc_get_order( $order_id );

            $status = 'wc-' === substr( $this->order_status, 0, 3 ) ? substr( $this->order_status, 3 ) : $this->order_status;

            // Set order status
            $order->update_status( $status, __( 'Checkout with get_code payment. ', $this->domain ) );

            // Reduce stock levels
            $order->reduce_order_stock();

            // Remove cart
            WC()->cart->empty_cart();

            // Return thankyou redirect
            return array(
                'result'    => 'success',
                'redirect'  => $this->get_return_url( $order )
            );
        }
    }
}

add_filter( 'woocommerce_payment_gateways', 'add_get_code_gateway_class' );
function add_get_code_gateway_class( $methods ) {
    $methods[] = 'WC_Gateway_Get_Code_Payment'; 
    return $methods;
}

add_action('woocommerce_checkout_process', 'process_get_code_payment');
function process_get_code_payment(){
    // Verify the nonce for security
    check_ajax_referer(GET_CODE_NONCE, 'code_checkout_nonce');

    if($_POST['payment_method'] != 'get_code') {
        return;
    }

    $data = array(
        'tx_intent'    => !empty($_POST['tx_intent']) ? sanitize_text_field($_POST['tx_intent']) : null,
    );

    $result = verify_purchase_callback($data);

    if (empty($result)) {
        wp_send_json_error(['order not found']);
    }

    return true;
}

/**
 * Update the order meta with field value
 */
add_action( 'woocommerce_checkout_update_order_meta', 'get_code_payment_update_order_meta' );
function get_code_payment_update_order_meta( $order_id ) {

    if($_POST['payment_method'] != 'get_code')
        return;

    // echo "<pre>";
    // print_r($_POST);
    // echo "</pre>";
    // exit();

    update_post_meta( $order_id, 'tx_intent_id', $_POST['tx_intent'] );
}

/**
 * Display field value on the order edit page
 */
add_action( 'woocommerce_admin_order_data_after_billing_address', 'get_code_checkout_field_display_admin_order_meta', 10, 1 );
function get_code_checkout_field_display_admin_order_meta($order){
    $method = get_post_meta( $order->get_id(), '_payment_method', true );
    if($method != 'get_code')
        return;

    $intent_id = get_post_meta( $order->get_id(), 'tx_intent_id', true );

    echo '<p><strong>'. esc_html( 'Tx Intent' ) .': </strong> ' . esc_html($intent_id) . '</p>';
}