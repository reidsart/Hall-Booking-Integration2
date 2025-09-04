<?php
/**
 * Booking Handler Class - For Dynamic Tariff Form
 * Handles booking submissions, processes dynamic tariffs, quantities, and generates invoice/event posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBI_Booking_Handler {

    public function __construct() {
        // Handle logged-in and guest submissions
        add_action( 'admin_post_nopriv_hbi_process_booking', array( $this, 'process_booking' ) );
        add_action( 'admin_post_hbi_process_booking', array( $this, 'process_booking' ) );
    }

    /**
     * Process the booking form submission
     */
    public function process_booking() {
        // Basic validation
        if ( ! isset( $_POST['hbi_name'], $_POST['hbi_email'], $_POST['hbi_start_date'] ) ) {
            wp_die( 'Invalid submission.' );
        }

        // Sanitize input
        $name         = sanitize_text_field( $_POST['hbi_name'] );
        $organization = sanitize_text_field( $_POST['hbi_organization'] ?? '' );
        $email        = sanitize_email( $_POST['hbi_email'] );
        $phone        = sanitize_text_field( $_POST['hbi_phone'] ?? '' );
        $event_title  = sanitize_text_field( $_POST['hbi_event_title'] ?? '' );
        $event_privacy= !empty($_POST['hbi_event_privacy']) ? 'private' : 'public';
        $event_description = sanitize_textarea_field( $_POST['hbi_event_description'] ?? '' );
        $space        = sanitize_text_field( $_POST['hbi_space'] ?? '' );
        $guest_count  = intval( $_POST['hbi_guest_count'] ?? 0 );

        // Dates
        $start_date = sanitize_text_field( $_POST['hbi_start_date'] );
        $multi_day  = !empty($_POST['hbi_multi_day']);
        $end_date   = $multi_day ? sanitize_text_field( $_POST['hbi_end_date'] ) : $start_date;

        // Times
        $event_time   = sanitize_text_field( $_POST['hbi_event_time'] ?? '' );
        $custom_start = sanitize_text_field( $_POST['hbi_custom_start'] ?? '' );
        $custom_end   = sanitize_text_field( $_POST['hbi_custom_end'] ?? '' );

        // Dynamic tariffs and quantities
        $tariffs_raw   = $_POST['hbi_tariff'] ?? [];
        $quantities_raw= $_POST['hbi_quantity'] ?? [];

        // Build items array
        $items = [];
        $total = 0;
        $tariffs_option = get_option('hall_tariffs', []);
        // For each selected item, get category/label, quantity, and price
        foreach ($tariffs_raw as $category => $labels) {
            foreach ($labels as $label => $checked) {
                $qty = isset($quantities_raw[$category][$label]) ? intval($quantities_raw[$category][$label]) : 1;
                $price = isset($tariffs_option[$category][$label]) ? floatval($tariffs_option[$category][$label]) : 0;
                if ($qty > 0) {
                    $subtotal = $qty * $price;
                    $items[] = [
                        'category' => $category,
                        'label'    => $label,
                        'quantity' => $qty,
                        'price'    => $price,
                        'subtotal' => $subtotal,
                    ];
                    $total += $subtotal;
                }
            }
        }

        // Add deposit items if needed
        $main_hall_deposit_price = null;
        $crockery_deposit_price = null;
        foreach ($tariffs_option as $cat => $tariff_items) {
            foreach ($tariff_items as $label => $price) {
                if (stripos($label, 'deposit') !== false) {
                    if (stripos($label, 'main hall') !== false) {
                        $main_hall_deposit_price = $price;
                    } elseif (stripos($label, 'crockery') !== false) {
                        $crockery_deposit_price = $price;
                    }
                }
            }
        }
        // Deposit conditions (same as JS)
        if ($main_hall_deposit_price !== null && ($space === "Main Hall" || $space === "Both Spaces")) {
            $items[] = [
                'category' => 'Deposits',
                'label'    => 'Main Hall refundable deposit',
                'quantity' => 1,
                'price'    => floatval($main_hall_deposit_price),
                'subtotal' => floatval($main_hall_deposit_price),
            ];
            $total += floatval($main_hall_deposit_price);
        }
        $crockery_selected = false;
        foreach ($items as $item) {
            $cat = strtolower($item['category']);
            if (
                ($cat === "crockery (each)" || $cat === "glassware (each)" || $cat === "cutlery (each)") &&
                $item['quantity'] > 0
            ) {
                $crockery_selected = true;
                break;
            }
        }
        if ($crockery_deposit_price !== null && $crockery_selected) {
            $items[] = [
                'category' => 'Deposits',
                'label'    => 'Refundable deposit for crockery, cutlery, & glassware',
                'quantity' => 1,
                'price'    => floatval($crockery_deposit_price),
                'subtotal' => floatval($crockery_deposit_price),
            ];
            $total += floatval($crockery_deposit_price);
        }

        // Format booking title
        $title = sprintf( 'Booking: %s (%s)', $name, $start_date );

        // Step 1: Create draft Event in Events Manager (or WP event CPT)
$event_id = wp_insert_post(array(
    'post_title'     => $title,
    'post_type'      => 'event',
    'post_status'    => 'draft',
    'post_content'   => $event_description,
    'meta_input'     => array(
        '_event_start_date' => $start_date,
        '_event_end_date'   => $end_date,
        '_event_start_time' => ($event_time === 'Custom' && $custom_start) ? $custom_start.':00:00' : '08:00:00',
        '_event_end_time'   => ($event_time === 'Custom' && $custom_end) ? $custom_end.':00:00' : '23:59:59',
        '_event_space'      => $space,
        '_event_guest_count'=> $guest_count,
        '_event_privacy'    => $event_privacy,
        '_event_title'      => $event_title,
    ),
));

        if ( ! $event_id ) {
            wp_die( 'Error saving event in Events Manager.' );
        }

        // Step 2: Create draft Invoice (CPT)
        $invoice_id = wp_insert_post( array(
            'post_type'   => 'hbi_invoice',
            'post_title'  => 'Invoice (Draft) - ' . $title,
            'post_status' => 'draft',
            'meta_input'  => array(
                '_hbi_customer_name'   => $name,
                '_hbi_customer_email'  => $email,
                '_hbi_customer_phone'  => $phone,
                '_hbi_organization'    => $organization,
                '_hbi_event_title'     => $event_title,
                '_hbi_event_privacy'   => $event_privacy,
                '_hbi_event_description'=> $event_description,
                '_hbi_space'           => $space,
                '_hbi_guest_count'     => $guest_count,
                '_hbi_start_date'      => $start_date,
                '_hbi_end_date'        => $end_date,
                '_hbi_event_time'      => $event_time,
                '_hbi_custom_start'    => $custom_start,
                '_hbi_custom_end'      => $custom_end,
                '_hbi_items'           => $items,
                '_hbi_total'           => $total,
                '_hbi_event_id'        => $event_id,
            ),
        ) );

        if ( ! $invoice_id ) {
            wp_die( 'Error creating invoice.' );
        }

        // Step 3: Redirect to Thank You page
        $thank_you_url = site_url( '/thank-you/?booking_id=' . $invoice_id );
        wp_safe_redirect( $thank_you_url );
        exit;
    }
}
