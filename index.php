<?php

/*
  Plugin Name: Woo-UnionPay-API
  Description: Extends WooCommerce to Process Payments with UnionPay's API Method.
  Version: 1.1.1
  Plugin URI: https://wordpress.org/plugins/woo-unionpay-api/
  Author: Yedpay
  Author URI: https://www.yedpay.com/
  Developer: Sourabh Tejawat
  Developer URI:
  License: Under GPL2
  Note: Under Development
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

register_uninstall_hook(__FILE__, 'unionpay_uninstall');

// remove stored setting when plugin uninstall
function unionpay_uninstall()
{
    delete_option('woocommerce_unionpayapi_settings');
}

add_action('plugins_loaded', 'woocommerce_tech_unionpayapi_init', 0);

function woocommerce_tech_unionpayapi_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    /**
     * UnionPay Payment Gateway class
     */
    include_once plugin_dir_path(__FILE__) . '/WoocommerceUnionpay.php';

    /**
     * Add this Gateway to WooCommerce
     */
    function woocommerce_add_tech_unionpayapi_gateway($methods)
    {
        $methods[] = 'WoocommerceUnionpay';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_tech_unionpayapi_gateway');
}
