<?php
/**
 * Admin Class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBI_Admin {

    public function __construct() {
        // Add meta box to Invoice CPT
        add_action( 'add_meta_boxes', array( $this, 'register_invoice_metabox' ) );
        add_action( 'admin_post_hbi_generate_invoice', array( $this, 'generate_invoice_action' ) );
        add_action( 'admin_post_hbi_resend_invoice', array( $this, 'resend_invoice_action' ) );
    }

    /**
     * Register Invoice Admin Metabox
     */
    public function register_invoice_metabox() {
        add_meta_box(
            'hbi_invoice_actions',
            'Invoice Actions',
            array( $this, 'render_invoice_metabox' ),
            'hbi_invoice',
            'side',
            'high'
        );
    }

    /**
     * Render Invoice Actions Metabox
     */
    public function render_invoice_metabox( $post ) {
        $invoice_number = get_post_meta( $post->ID, '_hbi_invoice_number', true );
        $pdf_url        = get_post_meta( $post->ID, '_hbi_pdf_url', true );

        echo '<p><strong>Invoice Number:</strong> ' . esc_html( $invoice_number ) . '</p>';

        if ( $pdf_url ) {
            echo '<p><a href="' . esc_url( $pdf_url ) . '" target="_blank">View Current PDF</a></p>';
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'hbi_generate_invoice_' . $post->ID, 'hbi_nonce' );
        echo '<input type="hidden" name="action" value="hbi_generate_invoice">';
        echo '<input type="hidden" name="invoice_id" value="' . intval( $post->ID ) . '">';
        submit_button( 'Generate PDF', 'primary', 'submit', false );
        echo '</form>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;">';
        wp_nonce_field( 'hbi_resend_invoice_' . $post->ID, 'hbi_nonce' );
        echo '<input type="hidden" name="action" value="hbi_resend_invoice">';
        echo '<input type="hidden" name="invoice_id" value="' . intval( $post->ID ) . '">';
        submit_button( 'Resend Invoice (New #)', 'secondary', 'submit', false );
        echo '</form>';
    }

    /**
     * Generate invoice PDF (first time approval)
     */
    public function generate_invoice_action() {
        if ( ! isset( $_POST['invoice_id'], $_POST['hbi_nonce'] ) ) {
            wp_die( 'Invalid request' );
        }

        $invoice_id = intval( $_POST['invoice_id'] );
        if ( ! wp_verify_nonce( $_POST['hbi_nonce'], 'hbi_generate_invoice_' . $invoice_id ) ) {
            wp_die( 'Security check failed' );
        }

        $pdf_url = HBI_Invoices::generate_pdf( $invoice_id );

        update_post_meta( $invoice_id, '_hbi_pdf_url', $pdf_url );

        wp_redirect( admin_url( 'post.php?post=' . $invoice_id . '&action=edit&hbi_message=generated' ) );
        exit;
    }

    /**
     * Resend invoice (new number + new PDF)
     */
    public function resend_invoice_action() {
        if ( ! isset( $_POST['invoice_id'], $_POST['hbi_nonce'] ) ) {
            wp_die( 'Invalid request' );
        }

        $invoice_id = intval( $_POST['invoice_id'] );
        if ( ! wp_verify_nonce( $_POST['hbi_nonce'], 'hbi_resend_invoice_' . $invoice_id ) ) {
            wp_die( 'Security check failed' );
        }

        // Reset invoice number so it generates a new one
        delete_post_meta( $invoice_id, '_hbi_invoice_number' );

        // Save post again to trigger new number assignment
        wp_update_post( array( 'ID' => $invoice_id ) );

        $pdf_url = HBI_Invoices::generate_pdf( $invoice_id );

        update_post_meta( $invoice_id, '_hbi_pdf_url', $pdf_url );

        wp_redirect( admin_url( 'post.php?post=' . $invoice_id . '&action=edit&hbi_message=resend' ) );
        exit;
    }
}