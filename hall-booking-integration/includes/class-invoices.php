<?php
/**
 * Invoices Class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBI_Invoices {

    public function __construct() {
        add_action( 'init', array( $this, 'register_invoice_cpt' ) );
        add_action( 'save_post_hbi_invoice', array( $this, 'assign_invoice_number' ), 10, 3 );
    }

    /**
     * Register the Invoice CPT
     */
    public function register_invoice_cpt() {
        $labels = array(
            'name'               => 'Invoices',
            'singular_name'      => 'Invoice',
            'add_new'            => 'Add Invoice',
            'add_new_item'       => 'Add New Invoice',
            'edit_item'          => 'Edit Invoice',
            'new_item'           => 'New Invoice',
            'view_item'          => 'View Invoice',
            'search_items'       => 'Search Invoices',
            'not_found'          => 'No invoices found',
            'not_found_in_trash' => 'No invoices found in Trash',
            'all_items'          => 'All Invoices',
        );

        $args = array(
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'supports'           => array( 'title', 'editor' ),
            'capability_type'    => 'post',
            'menu_icon'          => 'dashicons-media-spreadsheet',
        );

        register_post_type( 'hbi_invoice', $args );
    }

    /**
     * Assign sequential invoice numbers
     */
    public function assign_invoice_number( $post_id, $post, $update ) {
        if ( $post->post_type !== 'hbi_invoice' ) {
            return;
        }

        // Only assign number if not already set
        $invoice_number = get_post_meta( $post_id, '_hbi_invoice_number', true );
        if ( ! empty( $invoice_number ) ) {
            return;
        }

        // Get last invoice number
        $last_number = get_option( 'hbi_last_invoice_number', 0 );
        $new_number  = intval( $last_number ) + 1;

        // Save to post and option
        update_post_meta( $post_id, '_hbi_invoice_number', $new_number );
        update_option( 'hbi_last_invoice_number', $new_number );
    }

    /**
     * Generate PDF Invoice using TCPDF
     */
    public static function generate_pdf( $invoice_id ) {
        if ( ! class_exists( 'TCPDF' ) ) {
            require_once HBI_PLUGIN_DIR . 'tcpdf/tcpdf.php'; // adjust path if different
        }

        $invoice_number = get_post_meta( $invoice_id, '_hbi_invoice_number', true );
        $customer_name  = get_post_meta( $invoice_id, '_hbi_customer_name', true );
        $customer_email = get_post_meta( $invoice_id, '_hbi_customer_email', true );
        $customer_phone = get_post_meta( $invoice_id, '_hbi_customer_phone', true );
        $start_date     = get_post_meta( $invoice_id, '_hbi_start_date', true );
        $end_date       = get_post_meta( $invoice_id, '_hbi_end_date', true );
        $hours          = get_post_meta( $invoice_id, '_hbi_hours', true );
        $custom_hours   = get_post_meta( $invoice_id, '_hbi_custom_hours', true );
        $tariffs        = get_post_meta( $invoice_id, '_hbi_tariffs', true );
        $notes          = get_post_meta( $invoice_id, '_hbi_notes', true );

        $pdf = new TCPDF();
        $pdf->SetCreator('Hall Booking Integration');
        $pdf->SetAuthor('Sandbaai Hall Management Committee');
        $pdf->SetTitle('Invoice ' . $invoice_number);
        $pdf->SetMargins(20, 20, 20);
        $pdf->AddPage();

        // Logo + header
        $logo_path = HBI_PLUGIN_DIR . 'assets/logo.png'; // you can drop your logo here
        if ( file_exists( $logo_path ) ) {
            $pdf->Image( $logo_path, 15, 10, 30 );
        }
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 15, 'Sandbaai Hall Management Committee', 0, 1, 'C');

        $pdf->SetFont('helvetica', '', 12);
        $pdf->Ln(10);
        $pdf->Cell(0, 10, 'Invoice #' . $invoice_number, 0, 1);

        // Customer info
        $pdf->Cell(0, 8, 'Customer: ' . $customer_name, 0, 1);
        $pdf->Cell(0, 8, 'Email: ' . $customer_email, 0, 1);
        $pdf->Cell(0, 8, 'Phone: ' . $customer_phone, 0, 1);

        $pdf->Ln(5);
        $pdf->Cell(0, 8, 'Booking: ' . $start_date . ( $end_date ? ' - ' . $end_date : '' ), 0, 1);
        $pdf->Cell(0, 8, 'Hours: ' . ( $hours === 'custom' ? $custom_hours : ucfirst($hours) ), 0, 1);

        $pdf->Ln(5);
        $pdf->Cell(0, 8, 'Tariffs:', 0, 1);
        if ( is_array( $tariffs ) ) {
            foreach ( $tariffs as $item => $qty ) {
                $pdf->Cell(0, 8, ucfirst($item) . ': ' . $qty, 0, 1);
            }
        }

        if ( ! empty( $notes ) ) {
            $pdf->Ln(5);
            $pdf->MultiCell(0, 8, 'Notes: ' . $notes);
        }

        $upload_dir = wp_upload_dir();
        $file_path  = $upload_dir['basedir'] . '/invoices/invoice-' . $invoice_number . '.pdf';

        // Ensure folder exists
        if ( ! file_exists( dirname( $file_path ) ) ) {
            wp_mkdir_p( dirname( $file_path ) );
        }

        $pdf->Output( $file_path, 'F' );

        return $upload_dir['baseurl'] . '/invoices/invoice-' . $invoice_number . '.pdf';
    }
}