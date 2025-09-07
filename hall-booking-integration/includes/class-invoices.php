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
        add_filter( 'the_content', array( $this, 'render_invoice_content' ) );
        add_action( 'admin_post_hbi_approve_invoice', array( $this, 'handle_admin_approve_invoice' ) );
        add_action( 'add_meta_boxes', array( $this, 'register_invoice_meta_box' ) );
        add_action( 'add_meta_boxes', array( $this, 'register_invoice_details_box' ) );
        add_action( 'save_post_hbi_invoice', array( $this, 'save_invoice_details' ) );
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
 * Generate PDF Invoice using TCPDF - FIXED VERSION
 */
public static function generate_pdf( $invoice_id ) {
    error_log("=== HBI PDF: generate_pdf() called for invoice ID: $invoice_id ===");
    
    if ( ! $invoice_id ) {
        error_log("HBI PDF: No invoice ID provided, returning empty");
        return '';
    }

    // ensure invoice number assigned
    $invoice_number = get_post_meta( $invoice_id, '_hbi_invoice_number', true );
    if ( empty( $invoice_number ) ) {
        $handler = new self();
        $handler->assign_invoice_number( $invoice_id, get_post( $invoice_id ), true );
        $invoice_number = get_post_meta( $invoice_id, '_hbi_invoice_number', true );
    }

    // Load TCPDF
    if ( ! class_exists( 'TCPDF' ) ) {
        $tcpdf_path = HBI_PLUGIN_DIR . 'tcpdf/tcpdf.php';
        if ( file_exists( $tcpdf_path ) ) {
            require_once $tcpdf_path;
        } else {
            error_log( "HBI PDF: TCPDF not found at {$tcpdf_path}" );
            return ''; // TCPDF not installed
        }
    }

    // Gather invoice data
    $customer_name  = get_post_meta( $invoice_id, '_hbi_customer_name', true );
    $customer_email = get_post_meta( $invoice_id, '_hbi_customer_email', true );

    // items: try both meta keys so we are robust
    $items = get_post_meta( $invoice_id, '_hbi_items', true );
    if ( empty( $items ) ) {
        $items = get_post_meta( $invoice_id, '_hbi_invoice_items', true );
    }
    if ( ! is_array( $items ) ) $items = array();

    // Separate regular items from deposits
    $regular_items = array();
    $deposit_items = array();
    
    foreach ( $items as $item ) {
        $category = strtolower( $item['category'] ?? '' );
        $label = strtolower( $item['label'] ?? '' );
        
        // Check if this is a deposit item
        if ( strpos( $category, 'deposit' ) !== false || 
             strpos( $label, 'deposit' ) !== false ||
             strpos( $label, 'refundable' ) !== false ) {
            $deposit_items[] = $item;
        } else {
            $regular_items[] = $item;
        }
    }

    // Calculate subtotal from regular items only
    $subtotal = 0;
    foreach ( $regular_items as $item ) {
        $subtotal += floatval( $item['subtotal'] ?? ( (floatval($item['quantity'] ?? 0) * floatval($item['price'] ?? 0)) ) );
    }

    // Calculate deposit total from deposit items
    $deposit_total = 0;
    foreach ( $deposit_items as $item ) {
        $deposit_total += floatval( $item['subtotal'] ?? ( (floatval($item['quantity'] ?? 0) * floatval($item['price'] ?? 0)) ) );
    }

    // Default bank details + terms (can be overridden by options)
    $bank_details = get_option( 'hbi_bank_details',
        "Bank: FNB (First National Bank)\n" .
        "Account Name: Sandbaai Hall Management Committee\n" .
        "Account Number: 62711043068\n" .
        "Branch Code: 200412\n" .
        "Email proof of payment to booking@sandbaaihall.co.za\n" .
        "Reference: Use your Invoice Number"
    );

    $terms = get_option( 'hbi_terms',
       "<em>*Payment is due within 10 days of event date." .
       "**Main Hall bookings are confirmed after payment of deposit." .
        "***Refundable deposits will be returned within 7 working days after inspection, provided no damages or losses occurred." .
        "****Cancellations must be made in writing. Refer to the <a href=\"https://sandbaaihall.co.za/terms-rules-policies/\" target=\"_blank\">Hall Terms, Rules and Policies</a> for details.</em>"
    );

    // Build HTML for PDF (compact & styled to stay on one page)
    $html  = '<style>
        body { font-family: DejaVu Sans, sans-serif; font-size:10px; }
        h1 { font-size:14px; margin-bottom:6px; }
        .meta { font-size:10px; }
        table.items { width:100%; border-collapse:collapse; font-size:10px; }
        table.items th { background:#f2f2f2; padding:6px; border:1px solid #ddd; text-align:left; }
        table.items td { padding:6px; border:1px solid #ddd; vertical-align:top; }
        td.amount { text-align:right; }
        .spacer { height:12px; border:none; }
        table.deposits { width:100%; border-collapse:collapse; margin-top:8px; font-size:10px; }
        table.deposits td { padding:6px; border:1px solid #ddd; }
        .grand { font-size:12px; font-weight:bold; text-align:right; margin-top:12px; }
        .small { font-size:9px; color:#444; }
    </style>';

    // header area (left: hall info, right: invoice meta)
    $html .= '<table width="100%"><tr>';
    $html .= '<td width="60%">';
    $html .= '<div style="font-size:16px;font-weight:bold;">SANDBAAI HALL / SANDBAAISAAL</div>';
    $html .= '<div class="small">Sandbaaisaalbestuurskomitee (SBBK)<br/>Cnr Jimmy Smith Str & Main Road, Sandbaai 7200<br/>bookings@sandbaaihall.co.za</div>';
    $html .= '</td>';
    $html .= '<td width="40%" style="text-align:right;">';
    $html .= '<div style="font-size:16px;font-weight:bold;">INVOICE</div>';
    $html .= '<div class="meta">Invoice #: ' . esc_html( $invoice_number ) . '<br/>' . 'Date: ' . date_i18n( 'd/m/Y' ) . '</div>';
    $html .= '</td>';
    $html .= '</tr></table><br>';

    $html .= '<hr style="margin:8px 0;">';

    // customer / event: show customer contact and event info if set on invoice meta
    $event_title  = get_post_meta( $invoice_id, '_hbi_event_title', true );
    $start_date   = get_post_meta( $invoice_id, '_hbi_start_date', true );
    $end_date     = get_post_meta( $invoice_id, '_hbi_end_date', true );
    $space        = get_post_meta( $invoice_id, '_hbi_space', true );
    $customer_org = get_post_meta( $invoice_id, '_hbi_organization', true );
    $customer_phone = get_post_meta( $invoice_id, '_hbi_customer_phone', true );

    $html .= '<table width="100%"><tr>';
    $html .= '<td width="50%"><strong>BILL TO</strong><br/>' .
             esc_html( $customer_org ) . '<br/>' .
             esc_html( $customer_name ) . '<br/>' .
             esc_html( $customer_email ) . '<br/>' .
             esc_html( $customer_phone ) . '</td>';
    $html .= '<td width="50%"><strong>EVENT</strong><br/>' .
             esc_html( $event_title ) . '<br/>' .
             esc_html( $start_date ) . ' – ' . esc_html( $end_date ) . '<br/>' .
             esc_html( $space ) . '</td>';
    $html .= '</tr></table>';

    $html .= '<br/>';
    
    // REGULAR ITEMS table (tariffs only)
    $html .= '<br><table class="items" cellpadding="0" cellspacing="0">';
    $html .= '<thead><tr><th style="width:56%;"><strong>DESCRIPTION</strong></th><th style="width:12%;"><strong>QTY</strong></th><th style="width:16%;"><strong>RATE</strong></th><th style="width:16%;"><strong>SUBTOTAL</strong></th></tr></thead>';
    $html .= '<tbody>';

    // Display regular line items only
    foreach ( $regular_items as $item ) {
        $label = esc_html( $item['label'] ?? '' );
        $qty   = intval( $item['quantity'] ?? 0 );
        $price = floatval( $item['price'] ?? 0 );
        $subtotal_line = floatval( $item['subtotal'] ?? ($qty * $price) );

        $html .= '<tr>';
        $html .= '<td style="width:56%;">' . $label . '</td>';
        $html .= '<td style="width:12%;text-align:center;">' . $qty . '</td>';
        $html .= '<td style="width:16%;" class="amount">R ' . number_format( $price, 2 ) . '</td>';
        $html .= '<td style="width:16%;" class="amount">R ' . number_format( $subtotal_line, 2 ) . '</td>';
        $html .= '</tr>';
    }
    
    $html .= '<tr><td colspan="4" class="spacer" style="border:none;"></td></tr>';
    $html .= '</tbody><tfoot>';
    $html .= '<tr><td colspan="3" style="text-align:right;"><strong>SUBTOTAL</strong></td><td class="amount"><strong>R ' . number_format( $subtotal, 2 ) . '</strong></td></tr>';
    $html .= '</tfoot></table>';

    // DEPOSITS section (only if there are deposit items)
    if ( ! empty( $deposit_items ) ) {
        $html .= '<h4 style="margin-top:10px;">REFUNDABLE DEPOSITS</h4>';
        $html .= '<table class="deposits" cellpadding="0" cellspacing="0">';
        $html .= '<tbody>';
        
        foreach ( $deposit_items as $item ) {
            $label = esc_html( $item['label'] ?? '' );
            $item_total = floatval( $item['subtotal'] ?? ( (floatval($item['quantity'] ?? 0) * floatval($item['price'] ?? 0)) ) );
            
            $html .= '<tr><td style="width:84%;">' . $label . '</td><td class="amount" style="width:16%;">R ' . number_format( $item_total, 2 ) . '</td></tr>';
        }
        
        $html .= '</tbody>';
        $html .= '<tfoot><tr><td style="text-align:right;"><strong>TOTAL DEPOSITS</strong></td><td class="amount"><strong>R ' . number_format( $deposit_total, 2 ) . '</strong></td></tr></tfoot>';
        $html .= '</table>';
    }

    // Calculate GRAND TOTAL = subtotal + deposits
    $grand_total = $subtotal + $deposit_total;
    $html .= '<div class="grand">GRAND TOTAL: R ' . number_format( $grand_total, 2 ) . '</div>';

    // bank details + proof of payment
    $html .= '<br/>';
    $html .= '<strong>BANK DETAILS</strong><br/><div class="small">' . nl2br( esc_html( $bank_details ) ) . '</div>';

    // terms (already contains <em> by default)
    $html .= '<br/>';
    $html .= '<div class="small">' . $terms . '</div>';

    // Generate PDF
    try {
        // create PDF object and set defaults
        $pdf = new TCPDF( 'P', 'mm', 'A4', true, 'UTF-8', false );
        $pdf->SetCreator( 'Sandbaai Hall' );
        $pdf->SetAuthor( 'Sandbaai Hall Management Committee' );
        $pdf->SetTitle( 'Invoice #' . $invoice_number );

        // smaller default font to keep invoice compact
        // use DejaVu Sans for better unicode support if available in your TCPDF build
        $pdf->SetFont( 'dejavusans', '', 9 );

        $pdf->SetMargins( 15, 15, 15 );
        $pdf->SetAutoPageBreak( true, 15 );
        $pdf->AddPage();

        // write the HTML
        $pdf->writeHTML( $html, true, false, true, false, '' );

        // Save to uploads/hall-invoices/
        $upload_dir = wp_upload_dir();
        $dir = trailingslashit( $upload_dir['basedir'] ) . 'hall-invoices';
        if ( ! file_exists( $dir ) ) wp_mkdir_p( $dir );
        $filename = 'invoice-' . $invoice_number . '.pdf';
        $path = trailingslashit( $dir ) . $filename;

        $pdf->Output( $path, 'F' );

        $public_url = trailingslashit( $upload_dir['baseurl'] ) . 'hall-invoices/' . $filename;
        update_post_meta( $invoice_id, '_hbi_pdf_url', $public_url );
        update_post_meta( $invoice_id, '_hbi_pdf_path', $path );

        return $public_url;
    } catch ( Exception $e ) {
        error_log( 'HBI PDF error: ' . $e->getMessage() );
        return '';
    }
}

    /**
 * Register editable invoice details box
 */
public function register_invoice_details_box() {
    add_meta_box(
        'hbi_invoice_details',
        'Invoice Details',
        array( $this, 'render_invoice_details_box' ),
        'hbi_invoice',
        'normal',
        'high'
    );
}

/**
 * Render editable invoice details
 */
public function render_invoice_details_box( $post ) {
    $fields = array(
        '_hbi_customer_name'  => 'Customer Name',
        '_hbi_customer_email' => 'Customer Email',
        '_hbi_customer_phone' => 'Phone',
        '_hbi_organization'   => 'Organization',
        '_hbi_event_title'    => 'Event Title',
        '_hbi_space'          => 'Space',
        '_hbi_guest_count'    => 'Guest Count',
        '_hbi_start_date'     => 'Start Date',
        '_hbi_end_date'       => 'End Date',
    );

    wp_nonce_field( 'hbi_invoice_details', 'hbi_invoice_details_nonce' );

    echo '<table class="form-table"><tbody>';
    foreach ( $fields as $meta_key => $label ) {
        $val = get_post_meta( $post->ID, $meta_key, true );
        echo '<tr>';
        echo '<th><label for="' . esc_attr( $meta_key ) . '">' . esc_html( $label ) . '</label></th>';
        echo '<td><input type="text" style="width:100%" id="' . esc_attr( $meta_key ) . '" name="' . esc_attr( $meta_key ) . '" value="' . esc_attr( $val ) . '"></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    // Line items
    $items = get_post_meta( $post->ID, '_hbi_items', true );
    if ( !is_array($items) ) $items = [];

    echo '<h4>Line Items</h4>';
    echo '<table class="widefat hbi-items-table"><thead>
            <tr><th>Category</th><th>Label</th><th style="width:60px;">Qty</th><th style="width:80px;">Price</th><th style="width:100px;">Subtotal</th></tr>
          </thead><tbody>';

    foreach ( $items as $i => $it ) {
        echo '<tr>';
        echo '<td><input type="text" style="width:140px" name="hbi_items['.$i.'][category]" value="'.esc_attr($it['category']).'" /></td>';
        echo '<td><input type="text" style="width:240px" name="hbi_items['.$i.'][label]" value="'.esc_attr($it['label']).'" /></td>';
        echo '<td><input type="number" step="1" class="hbi-qty" style="width:60px" name="hbi_items['.$i.'][quantity]" value="'.intval($it['quantity']).'" /></td>';
        echo '<td><input type="number" step="0.01" class="hbi-price" style="width:80px" name="hbi_items['.$i.'][price]" value="'.esc_attr($it['price']).'" /></td>';
        echo '<td><input type="number" step="0.01" class="hbi-subtotal" style="width:100px;background:#f7f7f7;" name="hbi_items['.$i.'][subtotal]" value="'.esc_attr($it['subtotal']).'" readonly /></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '<p style="text-align:right;font-weight:bold;">Grand Total: R <span id="hbi-grand-total">0.00</span></p>';

    // Add JS
    ?>
    <script>
    jQuery(function($){
        function recalc() {
            let total = 0;
            $('.hbi-items-table tbody tr').each(function(){
                let qty = parseFloat($(this).find('.hbi-qty').val()) || 0;
                let price = parseFloat($(this).find('.hbi-price').val()) || 0;
                let subtotal = qty * price;
                $(this).find('.hbi-subtotal').val(subtotal.toFixed(2));
                total += subtotal;
            });
            $('#hbi-grand-total').text(total.toFixed(2));
        }
        $('.hbi-qty, .hbi-price').on('input', recalc);
        recalc();
    });
    </script>
    <?php
}

/**
 * Save editable invoice details
 */
public function save_invoice_details( $post_id ) {
    // Skip auto drafts
    if ( get_post_status($post_id) === 'auto-draft' ) return;

    // Check nonce if present
    if ( isset($_POST['hbi_invoice_details_nonce']) ) {
        if ( ! wp_verify_nonce( $_POST['hbi_invoice_details_nonce'], 'hbi_invoice_details' ) ) {
            return;
        }
    }

    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $keys = array(
        '_hbi_customer_name',
        '_hbi_customer_email',
        '_hbi_customer_phone',
        '_hbi_organization',
        '_hbi_event_title',
        '_hbi_space',
        '_hbi_guest_count',
        '_hbi_start_date',
        '_hbi_end_date',
    );

    foreach ( $keys as $key ) {
        if ( isset( $_POST[$key] ) ) {
            update_post_meta( $post_id, $key, sanitize_text_field( $_POST[$key] ) );
        }
    }

    // Save line items + recalc total
    $grand_total = 0;
    if ( isset($_POST['hbi_items']) && is_array($_POST['hbi_items']) ) {
        $items = array();
        foreach ( $_POST['hbi_items'] as $i => $it ) {
            $qty = intval($it['quantity'] ?? 0);
            $price = floatval($it['price'] ?? 0);
            $subtotal = $qty * $price;
            $grand_total += $subtotal;
            $items[] = array(
                'category' => sanitize_text_field( $it['category'] ?? '' ),
                'label'    => sanitize_text_field( $it['label'] ?? '' ),
                'quantity' => $qty,
                'price'    => $price,
                'subtotal' => $subtotal,
            );
        }
        update_post_meta( $post_id, '_hbi_items', $items );
    }
    update_post_meta( $post_id, '_hbi_total', $grand_total );

    // Debug log to confirm save is running
    error_log("✅ HBI invoice saved for post {$post_id}, total={$grand_total}");
}
    
    /**
     * Render invoice content when viewing single invoice
     */
    public function render_invoice_content( $content ) {
        if ( is_singular( 'hbi_invoice' ) && in_the_loop() && is_main_query() ) {
            global $post;
            $pdf_url = self::generate_pdf( $post->ID );
            if ( $pdf_url ) {
                $content .= '<p><a href="' . esc_url( $pdf_url ) . '" target="_blank" class="button button-primary">Download Invoice PDF</a></p>';
            } else {
                $content .= '<p><em>Invoice PDF could not be generated.</em></p>';
            }
        }
        return $content;
    }
    
public function register_invoice_meta_box() {
    add_meta_box(
        'hbi_invoice_summary',
        'Invoice Summary & Actions',
        array( $this, 'render_invoice_meta_box' ),
        'hbi_invoice',
        'side',
        'high'
    );
}

public function render_invoice_meta_box( $post ) {
    $invoice_id = $post->ID;
    $name = get_post_meta( $invoice_id, '_hbi_customer_name', true );
    $email = get_post_meta( $invoice_id, '_hbi_customer_email', true );
    $number = get_post_meta( $invoice_id, '_hbi_invoice_number', true );
    $items = get_post_meta( $invoice_id, '_hbi_items', true );
    $total = 0;
    if (is_array($items)) {
        foreach ($items as $it) {
            $total += floatval($it['subtotal'] ?? 0);
        }
    }
    $pdf_url = get_post_meta( $invoice_id, '_hbi_pdf_url', true );

    echo '<p><strong>Invoice:</strong> ' . esc_html( $number ) . '</p>';
    echo '<p><strong>Name:</strong> ' . esc_html( $name ) . '<br>';
    echo '<strong>Email:</strong> ' . esc_html( $email ) . '</p>';
    echo '<p><strong>Total:</strong> R ' . number_format( floatval($total), 2 ) . '</p>';

    if ( $pdf_url ) {
        echo '<p><a href="' . esc_url( $pdf_url ) . '" target="_blank" class="button">Download PDF</a></p>';
    }

    // Approve button (nonce-protected)
    $nonce = wp_create_nonce( 'hbi_approve_invoice_' . $invoice_id );
    $approve_url = admin_url( 'admin-post.php?action=hbi_approve_invoice&invoice_id=' . $invoice_id . '&_wpnonce=' . $nonce );
    echo '<p style="margin-top:10px;"><a href="' . esc_url($approve_url) . '" class="button button-primary">Approve & Send Invoice</a></p>';
}    
    
/**
 * Admin approve handler — generates PDF and emails it to customer
 */
public function handle_admin_approve_invoice() {
    if ( empty( $_GET['invoice_id'] ) || empty( $_GET['_wpnonce'] ) ) {
        wp_die( 'Invalid request.' );
    }
    $invoice_id = intval( $_GET['invoice_id'] );
    if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'hbi_approve_invoice_' . $invoice_id ) ) {
        wp_die( 'Security check failed.' );
    }
    if ( ! current_user_can( 'edit_post', $invoice_id ) ) {
        wp_die( 'Insufficient permissions.' );
    }

    // generate PDF and get public URL
    $pdf_url = self::generate_pdf( $invoice_id );

    // if generate_pdf returns empty, write debug and redirect back with error
    if ( empty( $pdf_url ) ) {
        error_log( "HBI: Failed to generate PDF for invoice $invoice_id" );
        wp_redirect( admin_url( 'post.php?post=' . $invoice_id . '&action=edit&hbi_msg=pdf_failed' ) );
        exit;
    }

    // Update invoice status/meta
    update_post_meta( $invoice_id, '_hbi_pdf_url', $pdf_url );
    update_post_meta( $invoice_id, '_hbi_pdf_generated', current_time('mysql') );
    update_post_meta( $invoice_id, '_hbi_invoice_status', 'sent' );

    // Email the customer the PDF (attach actual file if path meta exists)
    $customer_email = get_post_meta( $invoice_id, '_hbi_customer_email', true );
    $customer_name  = get_post_meta( $invoice_id, '_hbi_customer_name', true );

    // Try attach file if available
    $pdf_path = get_post_meta( $invoice_id, '_hbi_pdf_path', true ); // path saved by generate_pdf()
    $attachments = array();
    if ( ! empty( $pdf_path ) && file_exists( $pdf_path ) ) {
        $attachments[] = $pdf_path;
    }

    $subject = 'Sandbaai Hall — Invoice #' . get_post_meta( $invoice_id, '_hbi_invoice_number', true );
    $message = '<p>Dear ' . esc_html( $customer_name ) . ',</p>';
    $message .= '<p>Please find attached your invoice for the requested booking. Payment instructions are on the invoice.</p>';
    $message .= '<p>If you have questions, reply to this email.</p>';
    $headers = array('Content-Type: text/html; charset=UTF-8');

    wp_mail( $customer_email, $subject, $message, $headers, $attachments );

    // Redirect back to the invoice edit screen with success param
    wp_redirect( admin_url( 'post.php?post=' . $invoice_id . '&action=edit&hbi_msg=sent' ) );
    exit;
}
}
