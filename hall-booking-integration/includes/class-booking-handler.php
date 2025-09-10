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
        // Keep the original action slug used by the form
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
        // Respect the select value exactly
        $event_privacy  = ( isset($_POST['hbi_event_privacy']) && $_POST['hbi_event_privacy'] === 'private' ) ? 'private' : 'public';
        $event_description = sanitize_textarea_field( $_POST['hbi_event_description'] ?? '' );
        $space          = sanitize_text_field( $_POST['hbi_space'] ?? '' );
        $guest_count    = intval( $_POST['hbi_guest_count'] ?? 0 );

        // Dates
        $start_date_raw = sanitize_text_field( $_POST['hbi_start_date'] );
        $multi_day      = (isset($_POST['hbi_multi_day']) && $_POST['hbi_multi_day'] == '1') ? true : false;
        $end_date_raw   = $multi_day ? sanitize_text_field( $_POST['hbi_end_date'] ?? $start_date_raw ) : $start_date_raw;

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

        // --- Dynamic tariffs and quantities ---
        // We rely on posted quantities (hbi_quantity) so Hall Hire rows are included even when checkboxes are disabled.
        $quantities_raw = $_POST['hbi_quantity'] ?? [];

        // Build a tariffs lookup from hall_tariffs (server-trust)
        $tariffs_flat   = get_option('hall_tariffs', []);
        $tariffs_option = [];
        foreach ($tariffs_flat as $tariff) {
            if (!isset($tariff['category'], $tariff['label'])) continue;
            $cat   = $tariff['category'];
            $label = $tariff['label'];
            $price = isset($tariff['price']) ? floatval($tariff['price']) : 0;
            $tariffs_option[$cat][$label] = $price;
        }

        // Build items from quantities only (more reliable)
        $items = [];
        $total = 0.0;

        foreach ($quantities_raw as $category => $labels) {
            if (!is_array($labels)) continue;
            foreach ($labels as $label => $qty_raw) {
                $qty = intval($qty_raw);
                $price = isset($tariffs_option[$category][$label]) ? floatval($tariffs_option[$category][$label]) : null;
                if ($qty > 0 && $price !== null) {
                    $subtotal = $qty * $price;
                    $items[] = array(
                        'category' => $category,
                        'label'    => $label,
                        'quantity' => $qty,
                        'price'    => $price,
                        'subtotal' => $subtotal,
                    );
                    $total += $subtotal;
                }
            }
        }
        
        // --- START: Capture Spotlights & Sound + Kitchen Hire checkboxes ---
        if (!empty($_POST['hbi_tariff_checkbox']) && is_array($_POST['hbi_tariff_checkbox'])) {
            foreach ($_POST['hbi_tariff_checkbox'] as $category => $labels) {
                if (in_array($category, ['Spotlights & Sound', 'Kitchen Hire'])) {
                    foreach ($labels as $label => $val) {
                        if ($val) {
                            $price = isset($tariffs_option[$category][$label]) ? floatval($tariffs_option[$category][$label]) : 0;
                            $items[] = array(
                                'category' => $category,
                                'label'    => $label,
                                'quantity' => 1,
                                'price'    => $price,
                                'subtotal' => $price,
                            );
                            $total += $price;
                        }
                    }
                }
            }
        }
        // --- END ---
        // Fetch deposit values
        $deposits = get_option('hall_deposits', ['main_hall_deposit' => 2000, 'crockery_deposit' => 500]);

        // Add Main Hall deposit if needed
        if ($space === "Main Hall" || $space === "Both Spaces") {
            $main_deposit = floatval($deposits['main_hall_deposit'] ?? 2000);
            if ($main_deposit > 0) {
                $items[] = array(
                    'category' => 'Deposits',
                    'label'    => 'Main Hall refundable deposit',
                    'quantity' => 1,
                    'price'    => $main_deposit,
                    'subtotal' => $main_deposit,
                );
                $total += $main_deposit;
            }
        }

        // crockery deposit
        $crockery_selected = false;
        foreach ($items as $it) {
            $cat = strtolower($it['category'] ?? '');
            if ( ($cat === "crockery (each)" || $cat === "glassware (each)" || $cat === "cutlery (each)") && intval($it['quantity'] ?? 0) > 0 ) {
                $crockery_selected = true;
                break;
            }
        }
        if ($crockery_selected) {
            $crock_deposit = floatval($deposits['crockery_deposit'] ?? 500);
            if ($crock_deposit > 0) {
                $items[] = array(
                    'category' => 'Deposits',
                    'label'    => 'Refundable deposit for crockery, cutlery, & glassware',
                    'quantity' => 1,
                    'price'    => $crock_deposit,
                    'subtotal' => $crock_deposit,
                );
                $total += $crock_deposit;
            }
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
            $location_id = intval( $map['location'] );
            update_post_meta( $event_id, '_location_id', $location_id );
            update_post_meta( $event_id, '_hbi_location_type', sanitize_text_field( $map['location_type'] ) );
            update_post_meta( $event_id, '_hbi_location', $location_id );

            $categories_to_add = [ sanitize_title( $map['category'] ) ];
            if ( $event_privacy === 'private' ) $categories_to_add[] = 'private-event';
            if ( taxonomy_exists( 'event-categories' ) ) {
                wp_set_object_terms( $event_id, $categories_to_add, 'event-categories', true );
            } elseif ( taxonomy_exists( 'event_category' ) ) {
                wp_set_object_terms( $event_id, $categories_to_add, 'event_category', true );
            } else {
                update_post_meta( $event_id, '_event_category', $categories_to_add );
            }
        }

        if ( $event_id ) {
            wp_update_post( array( 'ID' => $event_id, 'comment_status' => 'closed' ) );
            $privacy_flag = ($event_privacy === 'private') ? 1 : 0;
            update_post_meta( $event_id, '_event_private', $privacy_flag );
            update_post_meta( $event_id, '_hbi_event_private', $privacy_flag );
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

        // Save invoice meta (including times)
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

        // Save the times into invoice meta (so invoice/email can show them)
        update_post_meta( $invoice_id, '_hbi_start_time', $start_time ); // H:i:s
        update_post_meta( $invoice_id, '_hbi_end_time', $end_time );     // H:i:s

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

        // Count existing invoices for that date (excluding this one)
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
        // Default invoice status
        update_post_meta( $invoice_id, '_hbi_status', 'unpaid' );

        // Update title to include invoice number
        wp_update_post( array(
            'ID'         => $invoice_id,
            'post_title' => sprintf( 'Invoice %s - %s', $invoice_number, $title ),
        ) );

        // Build a booking summary HTML (re-usable)
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

        // 2) Admin notification (no one-click approve link)
        $admin_to      = 'booking@sandbaaihall.co.za';
        $admin_subject = "New Booking Request — " . esc_html($event_title) . " (" . esc_html($start_date) . ")";
        $admin_message = "<p>A new booking request has been submitted. Summary below:</p>";
        $admin_message .= $invoice_summary_html;
        $admin_message .= "<p>Please log in to the WordPress admin to review and approve this booking.</p>";
        $admin_headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail( $admin_to, $admin_subject, $admin_message, $admin_headers );

        // Redirect to Thank You page
        $thank_you_url = site_url( '/thank-you/?booking_id=' . $invoice_id );
        wp_safe_redirect( $thank_you_url );
        exit;
    }

    /**
     * Build HTML summary table for emails (uses invoice meta; falls back to linked event meta)
     */
    public function build_booking_summary_html($invoice_id) {
        $name   = get_post_meta($invoice_id, '_hbi_customer_name', true);
        $email  = get_post_meta($invoice_id, '_hbi_customer_email', true);
        $space  = get_post_meta($invoice_id, '_hbi_space', true);
        $start  = get_post_meta($invoice_id, '_hbi_start_date', true);
        $end    = get_post_meta($invoice_id, '_hbi_end_date', true);

        // Try invoice times first; if missing, fall back to linked event meta
        $start_time = get_post_meta($invoice_id, '_hbi_start_time', true);
        $end_time   = get_post_meta($invoice_id, '_hbi_end_time', true);
        if (empty($start_time) || empty($end_time)) {
            $linked_event = get_post_meta($invoice_id, '_hbi_event_id', true);
            if ($linked_event) {
                $ev_start = get_post_meta($linked_event, '_event_start_time', true);
                $ev_end = get_post_meta($linked_event, '_event_end_time', true);
                if (!$start_time && $ev_start) $start_time = $ev_start;
                if (!$end_time && $ev_end) $end_time = $ev_end;
            }
        }

        // Format times for display (H:i)
        $start_fmt = $start_time ? date('H:i', strtotime($start_time)) : '';
        $end_fmt   = $end_time   ? date('H:i', strtotime($end_time))   : '';

        $items  = get_post_meta($invoice_id, '_hbi_items', true);
        if ( ! is_array($items) ) $items = array();

        // Recalculate total from items array for always-correct output
        $total = 0;
        foreach ($items as $it) {
            $total += floatval($it['subtotal'] ?? 0);
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

new HBI_Booking_Handler();
