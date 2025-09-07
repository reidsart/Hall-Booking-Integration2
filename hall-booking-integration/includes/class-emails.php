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
    $to      = 'booking@sandbaaihall.co.za';
    $subject = "New Hall Booking Request Submitted";

    // Build booking summary HTML (you may re-use existing hbi_render_booking_summary)
    $message  = "<p>A new booking request has been submitted. Details are below:</p>";
    $message .= hbi_render_booking_summary( $booking );

    // Draft invoice link
    if ( isset( $booking->invoice_id ) ) {
        $invoice_link = admin_url( 'post.php?post=' . intval($booking->invoice_id) . '&action=edit' );
        $message .= '<p><strong>Draft Invoice:</strong> <a href="' . esc_url( $invoice_link ) . '" target="_blank">Open draft invoice</a></p>';

        // One-click approve link (admin-post). This will require an action handler (see below).
        $nonce = wp_create_nonce( 'hbi_approve_invoice_' . intval($booking->invoice_id) );
        $approve_link = admin_url( 'admin-post.php?action=hbi_approve_invoice&invoice_id=' . intval($booking->invoice_id) . '&_wpnonce=' . $nonce );
        $message .= '<p><a href="' . esc_url( $approve_link ) . '" style="display:inline-block;background:#1e4f91;color:#fff;padding:8px 12px;border-radius:6px;text-decoration:none;">Approve & Publish Invoice</a></p>';
    }

    $message .= "<p><a href='" . admin_url( 'edit.php?post_type=event' ) . "'>View Events</a></p>";

    $headers = array( 'Content-Type: text/html; charset=UTF-8' );
    wp_mail( $to, $subject, $message, $headers );
}

    /**
     * Send HTML email wrapper
     */
    private function send_html_email( $to, $subject, $message ) {
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        wp_mail( $to, $subject, $message, $headers );
    }
}
