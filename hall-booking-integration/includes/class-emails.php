<?php
/**
 * Email handling for Hall Booking Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBI_Emails {

    public function __construct() {
        // Hook into booking creation
        add_action( 'hbi_booking_created', [ $this, 'send_booking_emails' ], 10, 1 );
    }

    /**
     * Send both customer + admin booking emails
     */
    public function send_booking_emails( $booking ) {
        $this->send_customer_email( $booking );
        $this->send_admin_email( $booking );
    }

    /**
     * Customer email
     */
    private function send_customer_email( $booking ) {
        $to      = hbi_get_booking_field( $booking, 'email' );
        $subject = "Your Hall Booking Request – Sandbaai Hall";

        $message  = "<p>Dear " . esc_html( hbi_get_booking_field( $booking, 'name' ) ) . ",</p>";
        $message .= "<p>Thank you for your booking request. Here are your details:</p>";
        $message .= hbi_render_booking_summary( $booking );
        $message .= "<p>We will review your request and confirm shortly.</p>";
        $message .= "<p>Regards,<br>Sandbaai Hall Management Committee</p>";

        $this->send_html_email( $to, $subject, $message );
    }

    /**
     * Admin email
     */
    private function send_admin_email( $booking ) {
        $to      = get_option( 'admin_email' );
        $subject = "New Hall Booking Request Submitted";

        $message  = "<p>A new booking request has been submitted. Details are below:</p>";
        $message .= hbi_render_booking_summary( $booking );
        $message .= "<p><a href='" . admin_url( 'edit.php?post_type=event' ) . "'>View in Events Manager</a></p>";

        $this->send_html_email( $to, $subject, $message );
    }

    /**
     * Send HTML email wrapper
     */
    private function send_html_email( $to, $subject, $message ) {
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        wp_mail( $to, $subject, $message, $headers );
    }
}