<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!function_exists('loadPackage')) {
    include_once plugin_dir_path(__FILE__) . '/includes/php-library/autoload.php';
}

use Yedpay\Client;
use Yedpay\Response\Success;
use Yedpay\Response\Error;

/**
 * UnionPay Payment Gateway class
 */
class WoocommerceUnionpay extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->method = 'AES-128-CBC'; // Encryption method, IT SHOULD NOT BE CHANGED

        // Woocommerce Setting
        $this->id = 'unionpayapi';
        $this->method_title = __('UnionPay API', 'tech');
        $this->method_description = __('Extends WooCommerce to Process Payments with UnionPay\'s API Method.', 'tech');
        $this->icon = WP_PLUGIN_URL . '/' . plugin_basename(dirname(__FILE__)) . '/images/logo.gif';
        $this->has_fields = false;
        $this->supports = ['products', 'refunds'];

        // Defining form fields
        $this->init_form_fields();
        // Load the settings.
        $this->init_settings();

        // Define user set variables
        $this->mode = $this->settings['unionpayapi_working_mode'];
        if ($this->mode == 'test') {
            $this->title = $this->settings['unionpayapi_title'] . ' - <b>Test Mode</b>';
        } else {
            $this->title = $this->settings['unionpayapi_title'];
        }

        $this->description = $this->settings['unionpayapi_description'];
        $this->unionpayapi_token = $this->settings['unionpayapi_token'];
        $this->unionpayapi_store_id = $this->settings['unionpayapi_store_id'];

        // Saving admin options
        if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [&$this, 'process_admin_options']);
        } else {
            add_action('woocommerce_update_options_payment_gateways', [&$this, 'process_admin_options']);
        }

        // Addition Hook
        add_action('woocommerce_receipt_' . $this->id, [&$this, 'receipt_page']);
        add_action('woocommerce_thankyou_' . $this->id, [&$this, 'thankyou_page'], 10, 1);

        add_action('init', [&$this, 'notify_handler']);
        add_action('woocommerce_init', [&$this, 'notify_handler']);
        $this->notify_url = add_query_arg('wc-api', strtolower(get_class($this)), home_url('/'));
        add_action('woocommerce_api_' . strtolower(get_class($this)), [&$this, 'notify_handler']);
    }

    /**
     * function to show fields in admin configuration form
     */
    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'tech'),
                'type' => 'checkbox',
                'label' => __('Enable UnionPay API Payment Module.', 'tech'),
                'default' => 'no'
            ],
            'unionpayapi_title' => [
                'title' => __('Title:', 'tech'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'tech'),
                'default' => __('UnionPay', 'tech')
            ],
            'unionpayapi_description' => [
                'title' => __('Description:', 'tech'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'tech'),
                'default' => __('Pay securely by UnionPay Secure Servers.', 'tech')
            ],
            'unionpayapi_token' => [
                'title' => __('Token', 'tech'),
                'type' => 'text',
                'description' => __('This is your access token from Yedpay.'),
                'default' => __('Yedpay: Your Token', 'tech')
            ],
            'unionpayapi_store_id' => [
                'title' => __('Store ID', 'tech'),
                'type' => 'text',
                'description' => __('This is your store ID from Yedpay.', 'tech'),
                'default' => __('Yedpay: Your Store_ID', 'tech')
            ],
            'unionpayapi_working_mode' => [
                'title' => __('Payment Mode'),
                'type' => 'select',
                'options' => ['live' => 'Live Mode', 'test' => 'Test/Sandbox Mode'],
                'description' => 'Live/Test Mode'
            ]
        ];
    }

    /**
     * Admin Panel Options
     * - Options for bits like 'title' and availability on a country-by-country basis
     */
    public function admin_options()
    {
        echo '<h3>' . __('UnionPay API Payment Method Configuration', 'tech') . '</h3>';
        echo '<p>' . __('UnionPay is the most popular payment gateway for online payment processing') . '</p>';
        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '<tr><td>(Module Version 1.1.0)</td></tr></table>';
    }

    /**
     *  There are no payment fields for UnionPay, but want to show the description if set.
     */
    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wptexturize($this->description)) . '<br>';
        }

        $currentUser = wp_get_current_user();
        $userId = $currentUser->ID;
    }

    /**
     * will call this method if payment gateway callback
     */
    public function notify_handler()
    {
        global $woocommerce;
        @ob_clean();

        $logger = wc_get_logger();

        // remove double slashes in string
        $_REQUEST = stripslashes_deep($_REQUEST);

        // check if required parameter is passed
        if (!isset($_REQUEST['status']) || !isset($_REQUEST['extra_parameters'])) {
            $this->error_response('parameter required!');
        }

        $status = sanitize_text_field($_REQUEST['status']);
        $extraParameters = json_decode($_REQUEST['extra_parameters'], true);
        $order_id = isset($extraParameters['order_id']) ? $extraParameters['order_id'] : null;

        if (isset($status) && $status == 'paid' && !is_null($order_id)) {
            try {
                $order = new WC_Order($order_id);
            } catch (Exception $e) {
                $logger->error('Order Not Found');
            }

            // Update Order Status
            if ($order->get_status() == 'processing') {
                $order->update_status('pending');
            }
            if ($order->get_status() == 'pending') {
                update_post_meta($order_id, 'yedpay_transaction_id', sanitize_text_field($_REQUEST['id']));
                $order->add_order_note(__($this->getTransactionInformation(), 'woocommerce'));
                $order->payment_complete();
                // $order->reduce_order_stock();
                $woocommerce->cart->empty_cart();
            }
            // return 'success';
            die('success');
        }
    }

    /**
     * function to show transaction information
     *
     * @return string
     */
    protected function getTransactionInformation()
    {
        return  'Yedpay Transaction Information:<br>
                Yedpay Transaction ID: ' . sanitize_text_field($_REQUEST['id']) . '<br>
                Company ID: ' . sanitize_text_field($_REQUEST['company_id']) . '<br>
                Barcode ID: ' . sanitize_text_field($_REQUEST['barcode_id']) . '<br>
                Status: ' . sanitize_text_field($_REQUEST['status']) . '<br>
                Amount: ' . sanitize_text_field($_REQUEST['amount']) . '<br>
                Currency: ' . sanitize_text_field($_REQUEST['currency']) . '<br>
                Charge: ' . sanitize_text_field($_REQUEST['charge']) . '<br>
                Forex: ' . sanitize_text_field($_REQUEST['forex']) . '<br>
                Paid Time: ' . sanitize_text_field($_REQUEST['paid_at']) . '<br>
                Transaction_id: ' . sanitize_text_field($_REQUEST['transaction_id']) . '<br>
                Extra Parameters: ' . $_REQUEST['extra_parameters'] . '<br>
                Created Time: ' . sanitize_text_field($_REQUEST['created_at']);
    }

    /**
     * Thank You Page
     */
    public function thankyou_page($order_id)
    {
        global $woocommerce;

        $order = new WC_Order($order_id);
        update_post_meta($order_id, 'unionpay_order_id', $order_id);
        update_post_meta($order_id, 'unionpay_payment_status', isset($_REQUEST['status']) && trim($_REQUEST['status']) != '' ? sanitize_text_field($_REQUEST['status']) : '');
        if (isset($_REQUEST['status']) && sanitize_text_field($_REQUEST['status']) == 'paid' && isset($_REQUEST['key']) && trim($_REQUEST['key']) != '') {
            $transaction_id = sanitize_text_field($_REQUEST['key']);
            $orderNote = 'UnionPay API payment completed.<br>
                        UnionPay Transaction ID: ' . $transaction_id . '<br>
                        UnionPay Order Id: ' . $order_id;

            // updating extra information in databaes corresponding to placed order.
            update_post_meta($order_id, 'unionpay_transaction_id', $transaction_id);
            $order->add_order_note(__($orderNote, 'woocommerce'));

            // Update Order Status
            if ($order->get_status() == 'processing') {
                $order->update_status('pending');
            }
            if ($order->get_status() == 'pending') {
                update_post_meta($order_id, 'yedpay_transaction_id', sanitize_text_field($_REQUEST['id']));
                $order->add_order_note(__($this->getTransactionInformation(), 'woocommerce'));
                $order->payment_complete();
                // $order->reduce_order_stock();
                $woocommerce->cart->empty_cart();
            }
        } else {
            $orderNote = 'UnionPay API payment failed.';
            $order->add_order_note(__($orderNote, 'woocommerce'));
        }
    }

    /**
     * Receipt Page
     */
    public function receipt_page($order)
    {
        echo '<p>' . __('Thank you for your order, please click the button below to pay with UnionPay.', 'tech') . '</p>';
    }

    /**
     * Process the payment and return the result
     */
    public function process_payment($order_id)
    {
        global $woocommerce;

        $order = new WC_Order($order_id);
        $extra = [
            'order_id' => $order_id
        ];

        // Change for 2.1
        if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
            $currency = $order->order_custom_fields['_order_currency'][0];

            $redirect_url = (get_option('woocommerce_thanks_page_id') != '') ? get_permalink(get_option('woocommerce_thanks_page_id')) : get_site_url() . '/';
        } else {
            $order_meta = get_post_custom($order_id);
            $currency = $order_meta['_order_currency'][0];

            $redirect_url = $this->get_return_url($order);
        }

        try {
            $client = new Client($this->operation_mode(), $this->unionpayapi_token);
            $client
                ->setCurrency($this->get_currency($currency))
                ->setGateway(2)
                ->setReturnUrl($redirect_url)
                ->setNotifyUrl($this->notify_url);

            $server_output = $client->precreate($this->unionpayapi_store_id, $order->order_total, json_encode($extra));
        } catch (Exception $e) {
            // No response or unexpected response
            $this->get_response($order);
            return;
        }

        if ($server_output instanceof Success) {
            $transaction_data = $server_output->getData();
            $links = (array) $transaction_data->_links;

            foreach ($links as $link) {
                if ($link->rel == 'checkout') {
                    $redirect_url = $link->href;
                }
            }

            return [
                    'result' => 'success',
                    'redirect' => $redirect_url
                ];
        }

        // No response or unexpected response
        $this->get_response($order);
        return;
    }

    /**
     * Returns Operation Mode
     *
     * @return string
     */
    public function operation_mode()
    {
        if ($this->mode == 'live') {
            return 'production';
        }
        return 'staging';
    }

    /**
     * function to get form post values
     *
     * @param string $name
     * @return string|void
     */
    public function get_post($name)
    {
        if (isset($_POST[$name])) {
            return sanitize_text_field($_POST[$name]);
        }
        return null;
    }

    /**
     * function to show error response
     *
     * @param string $msg
     * @param array $order
     * @return string
     */
    private function error_response($msg, $order = null)
    {
        $logger = wc_get_logger();
        $logger->warning($msg);
        if ($order) {
            $order->update_status('failed', __('Payment has been declined', 'tech'));
        }
        http_response_code(403);
        die($msg);
    }

    /**
     * function to show failed response
     *
     * @param array $order
     * @return void
     */
    public function get_response($order)
    {
        $order->add_order_note(__("UnionPay API payment failed. Couldn't connect to gateway server.", 'woocommerce'));
        wc_add_notice(__('No response from payment gateway server. Try again later or contact the site administrator.', 'woocommerce'));
    }

    /**
     * Returns Currency Index
     *
     * @param string $currency
     * @return int|void currency index
     */
    public function get_currency($currency)
    {
        if ($currency == Client::CURRENCY_HKD) {
            return Client::INDEX_CURRENCY_HKD;
        } elseif ($currency == Client::CURRENCY_RMB) {
            return Client::INDEX_CURRENCY_RMB;
        }
        return null;
    }

    /**
     * If the gateway declares 'refunds' support, this will allow it to refund.
     *
     * @param int $order_id
     * @param float $amount
     * @param string $reason
     * @return boolean|WP_Error success, fail or a WP_Error object.
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        global $woocommerce;

        $logger = wc_get_logger();

        try {
            $order = new WC_Order($order_id);
        } catch (Exception $e) {
            $logger->error('Order Not Found');
            return new WP_Error('wc-order', __('Order Not Found', 'woocommerce'));
        }

        if ($amount != $order->get_total()) {
            return new WP_Error('IllegalAmount', __('Refund amount must be equal to Order total amount.', 'woocommerce'));
        }
        if ($order->get_status() == 'refunded') {
            return new WP_Error('wc-order', __('Order has been already refunded', 'woocommerce'));
        }

        $transaction_id = get_post_meta($order_id, 'yedpay_transaction_id', true);
        if (!isset($transaction_id)) {
            return new WP_Error('Error', __('Yedpay Transaction ID not found', 'woocommerce'));
        }

        try {
            $client = new Client($this->operation_mode(), $this->unionpayapi_token);
            $server_output = $client->refund($transaction_id);
        } catch (Exception $e) {
            // No response or unexpected response
            $message = "UnionPay Refund failed. Couldn't connect to gateway server.";
            $order->add_order_note(__($message, 'woocommerce'));
            $logger->error($e->getMessage());
            return new WP_Error('Error', $message);
        }

        if ($server_output instanceof Success) {
            $refund_data = $server_output->getData();

            if (isset($refund_data->status) && $refund_data->status == 'refunded') {
                update_post_meta($order_id, 'yedpay_transaction_id', sanitize_text_field($_REQUEST['id']));
                $order->add_order_note(__($this->getRefundInformation($refund_data), 'woocommerce'));
                return true;
            }
        } elseif ($server_output instanceof Error) {
            $message = 'UnionPay Refund failed. ' .
                        'Error Code: ' . $server_output->getErrorCode() . '. ' .
                        'Error Message: ' . $server_output->getMessage();
            $order->add_order_note(__($message, 'woocommerce'));
            $logger->error($message);
            return new WP_Error('Error', $message);
        }

        $message = 'UnionPay Refund failed, please contact Yedpay.';
        $order->add_order_note(__($message, 'woocommerce'));
        return new WP_Error('Error', $message);
    }

    /**
     * function to show refund information
     *
     * @param array $refund_data
     * @return string
     */
    protected function getRefundInformation($refund_data)
    {
        return  'UnionPay API Refund Completed.<br>
                Yedpay Refund Information:<br>
                Yedpay Transaction ID: ' . $refund_data->id . '<br>
                Company ID: ' . $refund_data->company_id . '<br>
                Barcode ID: ' . $refund_data->barcode_id . '<br>
                Status: ' . $refund_data->status . '<br>
                Amount: ' . $refund_data->amount . '<br>
                Currency: ' . $refund_data->currency . '<br>
                Charge: ' . $refund_data->charge . '<br>
                Forex: ' . $refund_data->forex . '<br>
                Paid Time: ' . $refund_data->paid_at . '<br>
                Transaction_id: ' . $refund_data->transaction_id . '<br>
                Extra Parameters: ' . $refund_data->extra_parameters . '<br>
                Created Time: ' . $refund_data->created_at . '<br>
                Refund Time: ' . $refund_data->refunded_at;
    }
}
