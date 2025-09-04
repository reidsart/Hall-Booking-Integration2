<?php
/**
 * Booking Handler Class
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
        if ( ! isset( $_POST['hbi_name'], $_POST['hbi_email'], $_POST['hbi_start_date'] ) ) {
            wp_die( 'Invalid submission.' );
        }

        // Sanitize input
        $name       = sanitize_text_field( $_POST['hbi_name'] );
        $email      = sanitize_email( $_POST['hbi_email'] );
        $phone      = sanitize_text_field( $_POST['hbi_phone'] );
        $start_date = sanitize_text_field( $_POST['hbi_start_date'] );
        $end_date   = ! empty( $_POST['hbi_multi_day'] ) ? sanitize_text_field( $_POST['hbi_end_date'] ) : '';
        $hours      = sanitize_text_field( $_POST['hbi_hours'] );
        $custom_hours = ( $hours === 'custom' && ! empty( $_POST['hbi_custom_hours'] ) )
            ? sanitize_text_field( $_POST['hbi_custom_hours'] )
            : '';

        $tariffs    = isset( $_POST['hbi_tariff'] ) ? array_map( 'sanitize_text_field', $_POST['hbi_tariff'] ) : array();
        $notes      = sanitize_textarea_field( $_POST['hbi_notes'] );

        // Format booking title
        $title = sprintf( 'Booking: %s (%s)', $name, $start_date );

        /**
         * Step 1: Create draft Event in Events Manager
         * -------------------------------------------
         * Category slug: hall-bookings
         */
        $event_id = em_event_save( array(
            'event_name'        => $title,
            'event_start_date'  => $start_date,
            'event_end_date'    => ! empty( $end_date ) ? $end_date : $start_date,
            'event_start_time'  => '00:00:00',
            'event_end_time'    => '23:59:59',
            'event_notes'       => $notes,
            'post_status'       => 'draft',
            'categories'        => array( 'hall-bookings' ),
        ), 'insert' );

        if ( ! $event_id ) {
            wp_die( 'Error saving event in Events Manager.' );
        }

        /**
         * Step 2: Create draft Invoice (CPT)
         */
        $invoice_id = wp_insert_post( array(
            'post_type'   => 'hbi_invoice',
            'post_title'  => 'Invoice (Draft) - ' . $title,
            'post_status' => 'draft',
            'meta_input'  => array(
                '_hbi_customer_name'   => $name,
                '_hbi_customer_email'  => $email,
                '_hbi_customer_phone'  => $phone,
                '_hbi_start_date'      => $start_date,
                '_hbi_end_date'        => $end_date,
                '_hbi_hours'           => $hours,
                '_hbi_custom_hours'    => $custom_hours,
                '_hbi_tariffs'         => $tariffs,
                '_hbi_notes'           => $notes,
                '_hbi_event_id'        => $event_id,
            ),
        ) );

        if ( ! $invoice_id ) {
            wp_die( 'Error creating invoice.' );
        }

        /**
         * Step 3: Redirect to Thank You page
         */
        $thank_you_url = site_url( '/thank-you/?booking_id=' . $invoice_id );
        wp_safe_redirect( $thank_you_url );
        exit;
    }
}