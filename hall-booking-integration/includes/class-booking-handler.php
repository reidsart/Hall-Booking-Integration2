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
        // IMPORTANT: keep the original action slug used by the form
        add_action( 'admin_post_nopriv_hbi_process_booking', array( $this, 'process_booking' ) );
        add_action( 'admin_post_hbi_process_booking', array( $this, 'process_booking' ) );
        add_action( 'init', array( $this, 'setup_mail_from' ) );
    }

    /**
     * Ensure booking emails come from booking@sandbaaihall.co.za
     */
    public function setup_mail_from() {
        add_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
        add_filter( 'wp_mail_from_name', array( $this, 'mail_from_name' ) );
    }

    public function mail_from( $original_email ) {
        return 'booking@sandbaaihall.co.za';
    }

    public function mail_from_name( $original_name ) {
        return 'Sandbaai Hall';
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
        $name           = sanitize_text_field( $_POST['hbi_name'] );
        $organization   = sanitize_text_field( $_POST['hbi_organization'] ?? '' );
        $email          = sanitize_email( $_POST['hbi_email'] );
        $phone          = sanitize_text_field( $_POST['hbi_phone'] ?? '' );
        $event_title    = sanitize_text_field( $_POST['hbi_event_title'] ?? '' );
        // FIX: respect select value exactly
        $event_privacy  = ( isset($_POST['hbi_event_privacy']) && $_POST['hbi_event_privacy'] === 'private' ) ? 'private' : 'public';
        $event_description = sanitize_textarea_field( $_POST['hbi_event_description'] ?? '' );
        $space          = sanitize_text_field( $_POST['hbi_space'] ?? '' );
        $guest_count    = intval( $_POST['hbi_guest_count'] ?? 0 );

        // Dates
        $start_date_raw = sanitize_text_field( $_POST['hbi_start_date'] );
        $multi_day      = !empty($_POST['hbi_multi_day']); // '0' or '1' from select; '0' is empty() => true? No, empty('0') is true so !empty('0') is false -> correct.
        $end_date_raw   = $multi_day ? sanitize_text_field( $_POST['hbi_end_date'] ) : $start_date_raw;

        // Require Terms agreement
        if ( empty( $_POST['hbi_agree_terms'] ) ) {
            wp_die( 'You must agree to the Hall Terms, Rules & Policies before submitting a booking. Please go back and check the box. <a href="' . esc_url( 'https://sandbaaihall.co.za/terms-rules-policies/' ) . '">View Terms</a>' );
        }

        // Prevent selecting today or past dates (start must be in the future)
        $today = date_i18n( 'Y-m-d' );
        if ( strtotime( $start_date_raw ) <= strtotime( $today ) ) {
            wp_die( 'Invalid start date. Please choose a date in the future (after ' . esc_html( $today ) . ').' );
        }

        // Times
        $event_time   = sanitize_text_field( $_POST['hbi_event_time'] ?? '' );
        $custom_start = sanitize_text_field( $_POST['hbi_custom_start'] ?? '' ); // '08'..'23'
        $custom_end   = sanitize_text_field( $_POST['hbi_custom_end'] ?? '' );   // '09'..'24'

        // Map event_time to EM-friendly HH:MM:SS
        switch ($event_time) {
            case 'Full Day':
                $start_time = '08:00:00';
                $end_time   = '23:59:59';
                break;
            case 'Morning':
                $start_time = '08:00:00';
                $end_time   = '12:00:00';
                break;
            case 'Afternoon':
                $start_time = '13:00:00';
                $end_time   = '18:00:00';
                break;
            case 'Evening':
                $start_time = '18:00:00';
                $end_time   = '23:59:59';
                break;
            case 'Custom':
                $s = is_numeric($custom_start) ? (int)$custom_start : 8;
                $e = is_numeric($custom_end)   ? (int)$custom_end   : 24;
                // Clamp and format; treat 24 as end-of-day
                $s = min(max($s, 0), 23);
                $start_time = sprintf('%02d:00:00', $s);
                $end_time   = ($e >= 24) ? '23:59:59' : sprintf('%02d:00:00', max( $e, $s+1 ));
                break;
            default:
                $start_time = '08:00:00';
                $end_time   = '23:59:59';
        }

        // Ensure dates are Y-m-d for Event Manager
        $start_date = date('Y-m-d', strtotime($start_date_raw));
        $end_date   = date('Y-m-d', strtotime($end_date_raw));

        // Dynamic tariffs and quantities from options + posted quantities
        $quantities_raw = $_POST['hbi_quantity'] ?? [];

        // Build a nested tariffs lookup from hall_tariffs (server-trust)
        $tariffs_flat   = get_option('hall_tariffs', []);
        $tariffs_option = [];
        foreach ($tariffs_flat as $t) {
            if (!isset($t['category'], $t['label'])) continue;
            $cat   = $t['category'];
            $label = $t['label'];
            $price = isset($t['price']) ? floatval($t['price']) : 0;
            $tariffs_option[$cat][$label] = $price;
        }

        // Build items from QUANTITIES, not from checked checkboxes (so Hall Hire works even when disabled)
        $items = [];
        $total = 0.0;

        foreach ($quantities_raw as $category => $labels) {
            if (!is_array($labels)) continue;
            foreach ($labels as $label => $qty_raw) {
                $qty = intval($qty_raw);
                // Only include positive quantities and known prices
                $price = $tariffs_option[$category][$label] ?? null;
                if ($qty > 0 && $price !== null) {
                    $price    = floatval($price);
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

        // Fetch deposit values from the new option (defaults preserved)
        $deposits = get_option('hall_deposits', ['main_hall_deposit'=>2000, 'crockery_deposit'=>500]);

        // Add Main Hall deposit if needed
        if ($space === "Main Hall" || $space === "Both Spaces") {
            $main_deposit = floatval($deposits['main_hall_deposit'] ?? 2000);
            $items[] = [
                'category' => 'Deposits',
                'label'    => 'Main Hall refundable deposit',
                'quantity' => 1,
                'price'    => $main_deposit,
                'subtotal' => $main_deposit,
            ];
            $total += $main_deposit;
        }

        // Determine if crockery/cutlery/glassware were selected
        $crockery_selected = false;
        foreach ($items as $item) {
            $cat = strtolower($item['category'] ?? '');
            if (
                ($cat === "crockery (each)" || $cat === "glassware (each)" || $cat === "cutlery (each)") &&
                intval($item['quantity'] ?? 0) > 0
            ) {
                $crockery_selected = true;
                break;
            }
        }
        if ($crockery_selected) {
            $crock_deposit = floatval($deposits['crockery_deposit'] ?? 500);
            $items[] = [
                'category' => 'Deposits',
                'label'    => 'Refundable deposit for crockery, cutlery, & glassware',
                'quantity' => 1,
                'price'    => $crock_deposit,
                'subtotal' => $crock_deposit,
            ];
            $total += $crock_deposit;
        }

        // Booking title
        $title = $event_title ? $event_title : ( $name . ' Booking' );

        // Step 1: Create draft Event (Event Manager compatible)
        $event_id = wp_insert_post(array(
            'post_title'     => $title,
            'post_type'      => 'event',
            'post_status'    => 'draft',
            'post_author'    => 4, // Booking Admin user
            'post_content'   => $event_description,
            'meta_input'     => array(
                '_event_start_date' => $start_date,               // Y-m-d
                '_event_end_date'   => $end_date,                 // Y-m-d
                '_event_start_time' => $start_time,               // H:i:s
                '_event_end_time'   => $end_time,                 // H:i:s
                '_event_space'      => $space,
                '_event_guest_count'=> $guest_count,
                '_event_privacy'    => $event_privacy,
                '_event_title'      => $event_title,
            ),
        ));

        // Map 'space' -> EM location meta + categories
        $space_to_location = array(
            'Main Hall'    => array('location_type' => 'location', 'location' => '1', 'category' => 'main-hall-booking'),
            'Meeting Room' => array('location_type' => 'location', 'location' => '4', 'category' => 'meeting-room-booking'),
            'Both Spaces'  => array('location_type' => 'location', 'location' => '3', 'category' => 'both-spaces-booking'),
        );

        $space_key = $space ?: '';
        if ( $event_id && isset( $space_to_location[$space_key] ) ) {
            $map = $space_to_location[$space_key];

            // EM requires linking to a Location post via _location_id
            $location_id = intval( $map['location'] );
            update_post_meta( $event_id, '_location_id', $location_id );

            // Keep plugin-specific fallbacks
            update_post_meta( $event_id, '_hbi_location_type', sanitize_text_field( $map['location_type'] ) );
            update_post_meta( $event_id, '_hbi_location', $location_id );

            // Assign taxonomy
            $categories_to_add = [ sanitize_title( $map['category'] ) ];
            if ( $event_privacy === 'private' ) {
                $categories_to_add[] = 'private-event';
            }
            if ( taxonomy_exists( 'event-categories' ) ) {
                wp_set_object_terms( $event_id, $categories_to_add, 'event-categories', true );
            } elseif ( taxonomy_exists( 'event_category' ) ) {
                wp_set_object_terms( $event_id, $categories_to_add, 'event_category', true );
            } else {
                update_post_meta( $event_id, '_event_category', $categories_to_add );
            }
        }

        // Ensure comments are disabled and save privacy flag
        if ( $event_id ) {
            wp_update_post( array( 'ID' => $event_id, 'comment_status' => 'closed' ) );
            $privacy_flag = ($event_privacy === 'private') ? 1 : 0;
            update_post_meta( $event_id, '_event_private', $privacy_flag );    // EM common key
            update_post_meta( $event_id, '_hbi_event_private', $privacy_flag ); // plugin specific
        } else {
            wp_die( 'Error saving event in Events Manager.' );
        }

        // Step 2: Create draft Invoice (CPT)
        $invoice_id = wp_insert_post( array(
            'post_type'   => 'hbi_invoice',
            'post_title'  => 'Invoice (Draft) - ' . $title,
            'post_status' => 'draft',
        ) );

        if ( ! $invoice_id ) {
            wp_die( 'Error creating invoice.' );
        }

        // Save invoice meta
        update_post_meta( $invoice_id, '_hbi_customer_name', $name );
        update_post_meta( $invoice_id, '_hbi_customer_email', $email );
        update_post_meta( $invoice_id, '_hbi_customer_phone', $phone );
        update_post_meta( $invoice_id, '_hbi_organization', $organization );
        update_post_meta( $invoice_id, '_hbi_event_title', $event_title );
        update_post_meta( $invoice_id, '_hbi_event_privacy', $event_privacy );
        update_post_meta( $invoice_id, '_hbi_event_description', $event_description );
        update_post_meta( $invoice_id, '_hbi_space', $space );
        update_post_meta( $invoice_id, '_hbi_guest_count', $guest_count );
        update_post_meta( $invoice_id, '_hbi_start_date', $start_date ); // Y-m-d
        update_post_meta( $invoice_id, '_hbi_end_date', $end_date );     // Y-m-d
        update_post_meta( $invoice_id, '_hbi_event_time', $event_time );
        update_post_meta( $invoice_id, '_hbi_custom_start', $custom_start );
        update_post_meta( $invoice_id, '_hbi_custom_end', $custom_end );
        update_post_meta( $invoice_id, '_hbi_event_id', $event_id );
        update_post_meta( $invoice_id, '_hbi_items', $items );

        // Always recalc and store total
        $total = 0.0;
        foreach ($items as $it) {
            $total += floatval($it['subtotal'] ?? 0);
        }
        update_post_meta($invoice_id, '_hbi_total', $total);

        // ---------- Invoice number generation in format YYYYMMDDNN ----------
        global $wpdb;
        $invoice_date_part = str_replace('-', '', $start_date);

        // Count existing invoices for that date (EXCLUDING this one)
        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = %s
               AND p.ID != %d
               AND pm.meta_key = %s
               AND pm.meta_value = %s",
            'hbi_invoice',
            $invoice_id,
            '_hbi_start_date',
            $start_date
        );
        $count_for_date = intval( $wpdb->get_var( $sql ) );
        $sequence = $count_for_date + 1; // now correct

        $invoice_number = $invoice_date_part . str_pad( $sequence, 2, '0', STR_PAD_LEFT );
        update_post_meta( $invoice_id, '_hbi_invoice_number', $invoice_number );

        // Update title to include invoice number
        wp_update_post( array(
            'ID'         => $invoice_id,
            'post_title' => sprintf( 'Invoice %s - %s', $invoice_number, $title ),
        ) );

        // ----------------- EMAILS: Customer + Admin -----------------
        // Build a booking summary HTML
        $invoice_summary_html = $this->build_booking_summary_html($invoice_id);

        // 1) Customer confirmation email
        $customer_to      = $email;
        $customer_subject = "Sandbaai Hall — Booking Request Received (#" . esc_html( get_post_meta($invoice_id,'_hbi_invoice_number',true) ) . ")";
        $customer_message = "<p>Dear " . esc_html($name) . ",</p>";
        $customer_message .= "<p>Thank you — we received your booking request. Below are the details:</p>";
        $customer_message .= $invoice_summary_html;
        $customer_message .= "<p>We will review and send you an invoice.</p>";
        $customer_message .= "<p>Bookings are confirmed when invoice is paid.</p>";
        $customer_headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail( $customer_to, $customer_subject, $customer_message, $customer_headers );

        // 2) Admin notification with approve link (define before sending!)
        $admin_to      = 'booking@sandbaaihall.co.za';
        $admin_subject = "New Booking Request — " . esc_html($event_title) . " (" . esc_html($start_date) . ")";
        $admin_message = "<p>A new booking request has been submitted. Summary below:</p>";
        $admin_message .= $invoice_summary_html;

        // Notice to approve booking (admin must be logged in)
        $admin_message .= "<p>Please log in to the WordPress admin to review and approve this booking.</p>";


        $admin_headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail( $admin_to, $admin_subject, $admin_message, $admin_headers );

        // Step 3: Redirect to Thank You page
        $thank_you_url = site_url( '/thank-you/?booking_id=' . $invoice_id );
        wp_safe_redirect( $thank_you_url );
        exit;
    }

    /**
     * Build HTML summary table for emails
     */
    private function build_booking_summary_html($invoice_id) {
        $name   = get_post_meta($invoice_id, '_hbi_customer_name', true);
        $email  = get_post_meta($invoice_id, '_hbi_customer_email', true);
        $space  = get_post_meta($invoice_id, '_hbi_space', true);
        $start_time = get_post_meta($invoice_id, '_event_start_time', true);
        $end_time   = get_post_meta($invoice_id, '_event_end_time', true);

        // Format nicely (drop :00 if exact hours)
        $start_fmt = $start_time ? date('H:i', strtotime($start_time)) : '';
        $end_fmt   = $end_time   ? date('H:i', strtotime($end_time))   : '';

        $items  = get_post_meta($invoice_id, '_hbi_items', true);

        $total = 0.0;
        if (is_array($items)) {
            foreach ($items as $it) {
                $total += floatval($it['subtotal'] ?? 0);
            }
        }

        $out  = "<h3>Booking Summary</h3>";
        $out .= "<p><strong>Name:</strong> " . esc_html($name) . "<br>";
        $out .= "<strong>Email:</strong> " . esc_html($email) . "<br>";
        $out .= "<strong>Space:</strong> " . esc_html($space) . "<br>";
        $out .= "<strong>Start:</strong> " . esc_html($start);
        if ($start_fmt) $out .= " " . esc_html($start_fmt);
        $out .= "<br>";
        $out .= "<strong>End:</strong> " . esc_html($end);
        if ($end_fmt) $out .= " " . esc_html($end_fmt);
        $out .= "</p>";


        if ( is_array($items) && count($items) ) {
            $out .= "<table style='width:100%;border-collapse:collapse;'><thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Subtotal</th></tr></thead><tbody>";
            foreach($items as $it) {
                $out .= "<tr>";
                $out .= "<td style='border:1px solid #ddd;padding:6px;'>" . esc_html($it['label'] ?? '') . "</td>";
                $out .= "<td style='border:1px solid #ddd;padding:6px;text-align:right;'>" . intval($it['quantity'] ?? 0) . "</td>";
                $out .= "<td style='border:1px solid #ddd;padding:6px;text-align:right;'>R " . number_format(floatval($it['price'] ?? 0),2) . "</td>";
                $out .= "<td style='border:1px solid #ddd;padding:6px;text-align:right;'>R " . number_format(floatval($it['subtotal'] ?? 0),2) . "</td>";
                $out .= "</tr>";
            }
            $out .= "</tbody></table>";
        }

        $out .= "<p><strong>Total:</strong> R " . number_format(floatval($total),2) . "</p>";

        return $out;
    }
}

// Debug shortcode: [hbi_invoice_debug id=123]
add_shortcode( 'hbi_invoice_debug', function($atts) {
    $atts = shortcode_atts( array('id' => ''), $atts );
    $id = intval( $_GET['booking_id'] ?? $atts['id'] );
    if (!$id) return '<div style="color:darkred">No invoice id supplied</div>';

    $meta = get_post_meta( $id );
    ob_start();
    echo '<h3>HBI Invoice Debug — ID: ' . esc_html($id) . '</h3>';
    echo '<pre style="white-space:pre-wrap;background:#f6f8fb;padding:12px;border:1px solid #d6e8f5;">';
    echo esc_html( print_r( $meta, true ) );
    echo '</pre>';
    return ob_get_clean();
});
