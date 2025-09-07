<?php
/**
 * Template Tags (Helper Functions)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get booking field with fallback
 */
function hbi_get_booking_field( $booking, $field, $default = '' ) {
    if ( isset( $booking->$field ) && ! empty( $booking->$field ) ) {
        return $booking->$field;
    }
    return $default;
}

/**
 * Format booking hours for display
 */
function hbi_format_booking_hours( $booking ) {
    $hours_type   = hbi_get_booking_field( $booking, 'hours_type', '' );
    $custom_hours = hbi_get_booking_field( $booking, 'custom_hours', '' );

    if ( $hours_type === 'custom' && ! empty( $custom_hours ) ) {
        return $custom_hours;
    }

    return $hours_type;
}

/**
 * Get human-readable tariff description
 */
function hbi_get_tariff_label( $tariff_id ) {
    global $wpdb;

    $table = $wpdb->prefix . 'hbi_tariffs';
    $tariff = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $tariff_id ) );

    if ( $tariff ) {
        return $tariff->name . ' (' . wc_price( $tariff->price ) . ')';
    }

    return 'Unknown tariff';
}

/**
 * Build booking summary as HTML (for thank you page / email)
 */
function hbi_render_booking_summary( $booking ) {
    ob_start(); ?>
    <div class="hbi-booking-summary">
        <h3>Booking Summary</h3>
        <ul>
            <li><strong>Name:</strong> <?php echo esc_html( hbi_get_booking_field( $booking, 'name' ) ); ?></li>
            <li><strong>Email:</strong> <?php echo esc_html( hbi_get_booking_field( $booking, 'email' ) ); ?></li>
            <li><strong>Phone:</strong> <?php echo esc_html( hbi_get_booking_field( $booking, 'phone' ) ); ?></li>
            <li><strong>Date:</strong> <?php echo esc_html( hbi_get_booking_field( $booking, 'date' ) ); ?></li>
            <li><strong>Hours:</strong> <?php echo esc_html( hbi_format_booking_hours( $booking ) ); ?></li>
            <li><strong>Tariff:</strong> <?php echo esc_html( hbi_get_tariff_label( $booking->tariff_id ) ); ?></li>
        </ul>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Display "Custom Hours" correctly in emails
 */
function hbi_email_hours( $booking ) {
    $hours = hbi_format_booking_hours( $booking );
    return ! empty( $hours ) ? $hours : 'N/A';
}

add_filter( 'the_content', 'hbi_event_public_display_filter', 20 );

function hbi_event_public_display_filter( $content ) {
    if ( ! is_singular( 'event' ) ) {
        return $content;
    }

    global $post;

    // Admins / editors should see everything
    if ( current_user_can( 'edit_post', $post->ID ) ) {
        return $content;
    }

    // If event is private, replace content with "Private Event" text
    $is_private = get_post_meta( $post->ID, '_event_private', true );
    if ( $is_private ) {
        return '<p class="hbi-private-event" style="color:gray;font-style:italic;">Private Event</p>';
    }

    return $content;
}
