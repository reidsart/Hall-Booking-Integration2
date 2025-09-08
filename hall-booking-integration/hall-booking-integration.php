<?php
/**
 * Plugin Name: Hall Booking Integration
 * Description: Custom booking and invoicing system for Sandbaai Hall, integrated with Events Manager.
 * Version: 1.0.0
 * Author: Christopher Reid
 * Author URI: https://reidsart.com
 * License: GPL2
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin paths
define( 'HBI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HBI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Includes
require_once HBI_PLUGIN_DIR . 'includes/class-booking-form.php';
require_once HBI_PLUGIN_DIR . 'includes/class-booking-handler.php';
require_once HBI_PLUGIN_DIR . 'includes/class-invoices.php';
require_once HBI_PLUGIN_DIR . 'includes/class-admin.php';
require_once HBI_PLUGIN_DIR . 'includes/template-tags.php';
require_once HBI_PLUGIN_DIR . 'includes/class-emails.php';

// Assets
function hbi_enqueue_assets() {
    wp_enqueue_style(
        'hbi-styles',
        HBI_PLUGIN_URL . 'assets/css/hall-booking.css',
        array(),
        '1.0.0'
    );

    wp_enqueue_script(
        'hbi-scripts',
        HBI_PLUGIN_URL . 'assets/js/hall-booking.js',
        array( 'jquery' ),
        '1.0.0',
        true
    );

    wp_localize_script(
        'hbi-scripts',
        'hbi_ajax',
        array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'site_url' => site_url(),
        )
    );
}
add_action( 'wp_enqueue_scripts', 'hbi_enqueue_assets' );

add_filter('em_event_output', function($output, $event, $format){
    // Get "private-event" category ID
    $private_category = get_term_by('slug', 'private-event', 'event-categories');
    if ($private_category) {
        $private_category_id = $private_category->term_id;
    } else {
        return $output;
    }

    // Get array of event category IDs
    $event_categories = $event->get_categories();

    // If this event is private, mask output everywhere
    if (is_array($event_categories) && in_array($private_category_id, $event_categories)) {
        $space = esc_html($event->output('#_LOCATION'));
        $start = esc_html($event->output('#_24STARTTIME'));
        $end = esc_html($event->output('#_24ENDTIME'));
        $title = 'Private Event';
        $output = "<div class='private-event'><strong>{$title}</strong><br>Space: {$space}<br>Time: {$start} - {$end}</div>";
    }

    return $output;
}, 10, 3);

// Initialize classes
add_action( 'plugins_loaded', function() {
    new HBI_Booking_Form();
    new HBI_Booking_Handler();
    new HBI_Invoices();
    new HBI_Admin();
    new HBI_Emails();
});
