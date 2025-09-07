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
$tariffs_flat = get_option('hall_tariffs', []);
$tariffs_option = [];
foreach ($tariffs_flat as $tariff) {
    $cat = $tariff['category'];
    $label = $tariff['label'];
    $price = $tariff['price'];
    $tariffs_option[$cat][$label] = $price;
}
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
        
// Fetch deposit values from the new option
$deposits = get_option('hall_deposits', ['main_hall_deposit' => 2000, 'crockery_deposit' => 500]);

// Add Main Hall deposit if needed
if ($space === "Main Hall" || $space === "Both Spaces") {
    $items[] = [
        'category' => 'Deposits',
        'label'    => 'Main Hall refundable deposit',
        'quantity' => 1,
        'price'    => floatval($deposits['main_hall_deposit']),
        'subtotal' => floatval($deposits['main_hall_deposit']),
    ];
    $total += floatval($deposits['main_hall_deposit']);
}

// Determine if crockery/cutlery/glassware were selected
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
if ($crockery_selected) {
    $items[] = [
        'category' => 'Deposits',
        'label'    => 'Refundable deposit for crockery, cutlery, & glassware',
        'quantity' => 1,
        'price'    => floatval($deposits['crockery_deposit']),
        'subtotal' => floatval($deposits['crockery_deposit']),
    ];
    $total += floatval($deposits['crockery_deposit']);
}

        // Format booking title
        $title = sprintf( $event_title );

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
// Map 'space' -> location_type & location (Events Manager expects these meta keys in many setups)
$space_to_location = array(
    'Main Hall'    => array('location_type' => 'location', 'location' => '1', 'category' => 'main-hall-booking'),
    'Meeting Room' => array('location_type' => 'location', 'location' => '4', 'category' => 'meeting-room-booking'),
    'Both Spaces'  => array('location_type' => 'location', 'location' => '3', 'category' => 'both-spaces-booking'),
);

$space_key = isset($space) ? $space : '';
if ( isset( $space_to_location[$space_key] ) ) {
    $map = $space_to_location[$space_key];

// Save EM-compatible meta so EM's location logic can pick it up
// Events Manager requires linking to an actual Location post via _location_id
$location_id = intval( $map['location'] ); // must be a post ID of a Location CPT
update_post_meta( $event_id, '_location_id', $location_id );

// Keep plugin-specific fallbacks (not used by EM directly, but handy for your CPT)
update_post_meta( $event_id, '_hbi_location_type', sanitize_text_field( $map['location_type'] ) );
update_post_meta( $event_id, '_hbi_location', $location_id );

    // Assign event category (taxonomy). Try EM taxonomy 'event-categories' first, but also attempt 'event-category' fallback.
    $term_slug = sanitize_title( $map['category'] );
    if ( taxonomy_exists( 'event-categories' ) ) {
        wp_set_object_terms( $event_id, $term_slug, 'event-categories', true );
    } elseif ( taxonomy_exists( 'event_category' ) ) {
        wp_set_object_terms( $event_id, $term_slug, 'event_category', true );
    } else {
        // fallback — store as meta too
        update_post_meta( $event_id, '_event_category', $term_slug );
    }
}

// Ensure comments are disabled on event
wp_update_post( array( 'ID' => $event_id, 'comment_status' => 'closed' ) );

// Save privacy flag (from the form checkbox)
$privacy_flag = ! empty( $_POST['hbi_event_privacy'] ) ? 1 : 0;
update_post_meta( $event_id, '_event_private', $privacy_flag );   // EM common key
update_post_meta( $event_id, '_hbi_event_private', $privacy_flag ); // plugin specific
        if ( ! $event_id ) {
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

// Force-save invoice meta to avoid missing data
update_post_meta( $invoice_id, '_hbi_customer_name', $name );
update_post_meta( $invoice_id, '_hbi_customer_email', $email );
update_post_meta( $invoice_id, '_hbi_customer_phone', $phone );
update_post_meta( $invoice_id, '_hbi_organization', $organization );
update_post_meta( $invoice_id, '_hbi_event_title', $event_title );
update_post_meta( $invoice_id, '_hbi_event_privacy', $event_privacy );
update_post_meta( $invoice_id, '_hbi_event_description', $event_description );
update_post_meta( $invoice_id, '_hbi_space', $space );
update_post_meta( $invoice_id, '_hbi_guest_count', $guest_count );
update_post_meta( $invoice_id, '_hbi_start_date', $start_date );
update_post_meta( $invoice_id, '_hbi_end_date', $end_date );
update_post_meta( $invoice_id, '_hbi_event_time', $event_time );
update_post_meta( $invoice_id, '_hbi_custom_start', $custom_start );
update_post_meta( $invoice_id, '_hbi_custom_end', $custom_end );
update_post_meta( $invoice_id, '_hbi_total', $total );
update_post_meta( $invoice_id, '_hbi_event_id', $event_id );

// Save complex array (_hbi_items)
update_post_meta( $invoice_id, '_hbi_items', $items );

// Always recalculate and store the total
$total = 0;
foreach ($items as $it) {
    $total += floatval($it['subtotal'] ?? 0);
}
update_post_meta($invoice_id, '_hbi_total', $total);

// ===== DEBUG BLOCK - REMOVE AFTER TESTING =====
error_log( "HBI DEBUG: invoice_id = $invoice_id" );
error_log( "HBI DEBUG: items (raw) = " . print_r( $items, true ) );
$meta_after = get_post_meta( $invoice_id );
error_log( "HBI DEBUG: meta_after for invoice $invoice_id = " . print_r( $meta_after, true ) );
// ===== END DEBUG BLOCK =====

// ---------- Invoice number generation in format YYYYMMDDNN ----------
global $wpdb;
$invoice_date_part = str_replace('-', '', $start_date);
$sql = $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
     WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value = %s",
     'hbi_invoice',
     '_hbi_start_date',
     $start_date
);
$count_for_date = intval( $wpdb->get_var( $sql ) );
$sequence = $count_for_date + 1;
$invoice_number = $invoice_date_part . str_pad( $sequence, 2, '0', STR_PAD_LEFT );

update_post_meta( $invoice_id, '_hbi_invoice_number', $invoice_number );

// Update title to include invoice number
wp_update_post( array(
    'ID'         => $invoice_id,
    'post_title' => sprintf( 'Invoice %s - %s', $invoice_number, $title ),
) );

// Debug confirm
$all_meta = get_post_meta( $invoice_id );
error_log( "HBI DEBUG: Invoice $invoice_id meta after save: " . print_r( $all_meta, true ) );

// ----------------- EMAILS: Customer + Admin -----------------

// Build a booking summary HTML (simple)
function hbi_build_booking_summary_html($invoice_id) {
    $name   = get_post_meta($invoice_id, '_hbi_customer_name', true);
    $email  = get_post_meta($invoice_id, '_hbi_customer_email', true);
    $space  = get_post_meta($invoice_id, '_hbi_space', true);
    $start  = get_post_meta($invoice_id, '_hbi_start_date', true);
    $end    = get_post_meta($invoice_id, '_hbi_end_date', true);
    $items  = get_post_meta($invoice_id, '_hbi_items', true);
    // Recalculate total from items array for always-correct output
    $total = 0;
        if (is_array($items)) {
         foreach ($items as $it) {
                $total += floatval($it['subtotal'] ?? 0);
            }
        }
    $out  = "<h3>Booking Summary</h3>";
    $out .= "<p><strong>Name:</strong> " . esc_html($name) . "<br>";
    $out .= "<strong>Email:</strong> " . esc_html($email) . "<br>";
    $out .= "<strong>Space:</strong> " . esc_html($space) . "<br>";
    $out .= "<strong>Start:</strong> " . esc_html($start) . "<br>";
    $out .= "<strong>End:</strong> " . esc_html($end) . "</p>";

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

$invoice_summary_html = hbi_build_booking_summary_html($invoice_id);

// 1) Customer confirmation email
$customer_to = $email;
$customer_subject = "Sandbaai Hall — Booking Request Received (#" . esc_html( get_post_meta($invoice_id,'_hbi_invoice_number',true) ) . ")";
$customer_message = "<p>Dear " . esc_html($name) . ",</p>";
$customer_message .= "<p>Thank you — we received your booking request. Below are the details:</p>";
$customer_message .= $invoice_summary_html;
$customer_message .= "<p>We will review and send you a final invoice once the booking is approved by an admin.</p>";
$customer_headers = array('Content-Type: text/html; charset=UTF-8');
wp_mail( $customer_to, $customer_subject, $customer_message, $customer_headers );

if ( ! wp_mail( $admin_to, $admin_subject, $admin_message, $admin_headers ) ) {
    error_log( 'HBI: wp_mail returned false when sending admin email' );
} else {
    error_log( 'HBI: admin email sent (wp_mail returned true).' );
}

// 2) Admin notification with approve link
$admin_to = 'booking@sandbaaihall.co.za';
$admin_subject = "New Booking Request — " . esc_html($event_title) . " (" . esc_html($start_date) . ")";
$admin_message = "<p>A new booking request has been submitted. Summary below:</p>";
$admin_message .= $invoice_summary_html;

// Create a nonce-protected approve link (admin must be logged in)
$nonce = wp_create_nonce( 'hbi_approve_invoice_' . $invoice_id );
$approve_url = admin_url( 'admin-post.php?action=hbi_approve_invoice&invoice_id=' . $invoice_id . '&_wpnonce=' . $nonce );

$admin_message .= '<p><a href="' . esc_url( $approve_url ) . '" style="display:inline-block;padding:10px 14px;background:#1e73be;color:#fff;border-radius:6px;text-decoration:none;">Approve & Generate Invoice PDF</a></p>';

$admin_headers = array('Content-Type: text/html; charset=UTF-8');
wp_mail( $admin_to, $admin_subject, $admin_message, $admin_headers );

// ----------------- END EMAILS -----------------

// Step 3: Redirect to Thank You page
$thank_you_url = site_url( '/thank-you/?booking_id=' . $invoice_id );
wp_safe_redirect( $thank_you_url );
exit;
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
