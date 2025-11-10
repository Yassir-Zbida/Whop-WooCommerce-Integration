<?php

/**
 * Fired during plugin uninstall to clean up plugin data.
 *
 * @package Whop_WooCommerce_Integration
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('whop_wc_settings');

if (function_exists('delete_site_option')) {
    delete_site_option('whop_wc_settings');
}

delete_transient('whop_wc_activation');

if (function_exists('delete_site_transient')) {
    delete_site_transient('whop_wc_activation');
}


