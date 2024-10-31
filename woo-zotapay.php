<?php
/*
Plugin Name: Purple eCommerce Gateway for ZotaPay
Description: Extends WooCommerce by Adding the ZotaPay Gateway.
Version: 1.0
Author: PURPLE
Author URI: www.prpl.io/?zotapay_plugin
*/

// Include our Gateway Class and register Payment Gateway with WooCommerce
add_action( 'plugins_loaded', 'wc_zotapay_init', 0 );
function wc_zotapay_init() {
    // If the parent WC_Payment_Gateway class doesn't exist
    // it means WooCommerce is not installed on the site
    // so do nothing
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

    // If we made it this far, then include our Gateway Class
    include_once( 'woocommerce-zotapay-gateway.php' );

    // Now that we have successfully included our class,
    // Lets add it too WooCommerce
    add_filter( 'woocommerce_payment_gateways', 'wc_add_zotapay_gateway' );
    function wc_add_zotapay_gateway( $methods ) {
        $methods[] = 'WC_ZotaPay_GateWay';
        return $methods;
    }
}

// Add custom action links
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_zotapay_action_links' );
function wc_zotapay_action_links( $links ) {
    $plugin_links = array(
        '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout' ) . '">' . __( 'Settings', 'wc-ztp-gateway' ) . '</a>',
    );

    // Merge our new link with the default ones
    return array_merge( $plugin_links, $links );
}
