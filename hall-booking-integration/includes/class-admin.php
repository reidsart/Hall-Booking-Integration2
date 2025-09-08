<?php
/**
 * Admin Class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBI_Admin {

    public function __construct() {
        // Register only the sidebar "Invoice Actions" box
        add_action( 'add_meta_boxes', array( $this, 'register_invoice_metabox' ) );
        add_action( 'admin_post_hbi_resend_invoice', array( $this, 'resend_invoice_action' ) );
        add_action( 'admin_menu', array( $this, 'add_tariff_admin_menu' ) );
    }

    public function add_tariff_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=hbi_invoice',
            'Tariff Settings',
            'Tariffs',
            'manage_options',
            'hbi_tariffs',
            array($this, 'render_tariff_admin_page')
        );
    }

    public function render_tariff_admin_page() {
        $tariffs = get_option('hall_tariffs', []);
        // Always flatten for display
        if ($tariffs && (is_array(reset($tariffs)) && (isset(reset($tariffs)['category']) === false))) {
            $new_tariffs = [];
            foreach ($tariffs as $category => $label_prices) {
                if (!is_array($label_prices)) continue;
                foreach ($label_prices as $label => $price) {
                    $new_tariffs[] = [
                        'category' => $category,
                        'label' => $label,
                        'price' => $price
                    ];
                }
            }
            $tariffs = $new_tariffs;
            update_option('hall_tariffs', $tariffs);
        }
        // Handle CRUD actions
        if (isset($_POST['hbi_tariff_action']) && check_admin_referer('hbi_tariff_action')) {
            switch ($_POST['hbi_tariff_action']) {
                case 'add':
                    $tariffs[] = [
                        'category' => sanitize_text_field($_POST['tariff_category']),
                        'label'    => sanitize_text_field($_POST['tariff_label']),
                        'price'    => floatval($_POST['tariff_price'])
                    ];
                    update_option('hall_tariffs', $tariffs);
                    echo '<div class="notice notice-success"><p>Tariff added.</p></div>';
                    break;
                case 'edit':
                    $i = intval($_POST['tariff_index']);
                    if (isset($tariffs[$i])) {
                        $tariffs[$i] = [
                            'category' => sanitize_text_field($_POST['tariff_category']),
                            'label'    => sanitize_text_field($_POST['tariff_label']),
                            'price'    => floatval($_POST['tariff_price'])
                        ];
                        update_option('hall_tariffs', $tariffs);
                        echo '<div class="notice notice-success"><p>Tariff updated.</p></div>';
                    }
                    break;
                case 'delete':
                    $i = intval($_POST['tariff_index']);
                    if (isset($tariffs[$i]) && isset($_POST['confirm']) && $_POST['confirm'] == 'yes') {
                        array_splice($tariffs, $i, 1);
                        update_option('hall_tariffs', $tariffs);
                        echo '<div class="notice notice-success"><p>Tariff deleted.</p></div>';
                    }
                    break;
            }
        }

        // Handle deposit saving
        if (isset($_POST['hbi_deposit_action']) && check_admin_referer('hbi_deposit_action')) {
            $deposits = [
                'main_hall_deposit' => floatval($_POST['main_hall_deposit'] ?? 0),
                'crockery_deposit' => floatval($_POST['crockery_deposit'] ?? 0)
            ];
            update_option('hall_deposits', $deposits);
            echo '<div class="notice notice-success"><p>Deposits updated.</p></div>';
        }

        $tariffs = get_option('hall_tariffs', []);
        $deposits = get_option('hall_deposits', ['main_hall_deposit'=>2000, 'crockery_deposit'=>500]);
        ?>
        <div class="wrap">
            <h1>Tariff Settings</h1>
            <h2>Tariffs</h2>
            <table class="widefat">
                <thead><tr><th>Category</th><th>Label</th><th>Price (R)</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($tariffs as $i => $tariff): ?>
                        <tr>
                <form method="post">
                    <?php wp_nonce_field('hbi_tariff_action'); ?>
                    <td><input type="text" name="tariff_category" value="<?php echo esc_attr($tariff['category']); ?>"></td>
                    <td><input type="text" name="tariff_label" value="<?php echo esc_attr($tariff['label']); ?>"></td>
                    <td><input type="number" step="0.01" name="tariff_price" value="<?php echo esc_attr($tariff['price']); ?>"></td>
                    <td>
                        <input type="hidden" name="tariff_index" value="<?php echo $i; ?>">
                        <button type="submit" name="hbi_tariff_action" value="edit" class="button button-primary">Save</button>
                    </td>
                </form>
                <td>
                <form method="post" style="display:inline;">
                    <?php wp_nonce_field('hbi_tariff_action'); ?>
                    <input type="hidden" name="tariff_index" value="<?php echo $i; ?>">
                    <input type="hidden" name="confirm" value="yes">
                    <button type="submit" name="hbi_tariff_action" value="delete" class="button button-danger" onclick="return confirm('Are you sure you want to delete this tariff?');">Delete</button>
                </form>
                </td>
            </tr>
                    <?php endforeach; ?>
                    <tr>
                        <form method="post">
                            <?php wp_nonce_field('hbi_tariff_action'); ?>
                            <td><input type="text" name="tariff_category" placeholder="Category"></td>
                            <td><input type="text" name="tariff_label" placeholder="Label"></td>
                            <td><input type="number" step="0.01" name="tariff_price" placeholder="Price"></td>
                            <td><button type="submit" name="hbi_tariff_action" value="add" class="button button-primary">Add</button></td>
                        </form>
                    </tr>
                </tbody>
            </table>
            <h2>Deposits</h2>
            <form method="post">
                <?php wp_nonce_field('hbi_deposit_action'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="main_hall_deposit">Main Hall Deposit (R)</label></th>
                        <td><input type="number" step="0.01" name="main_hall_deposit" value="<?php echo esc_attr($deposits['main_hall_deposit']); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="crockery_deposit">Crockery Deposit (R)</label></th>
                        <td><input type="number" step="0.01" name="crockery_deposit" value="<?php echo esc_attr($deposits['crockery_deposit']); ?>"></td>
                    </tr>
                </table>
                <button type="submit" name="hbi_deposit_action" value="save" class="button button-primary">Save Deposits</button>
            </form>
        </div>
        <?php
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
        $pdf_url = get_post_meta( $post->ID, '_hbi_pdf_url', true );

        echo '<p><strong>Invoice Number:</strong> ' . esc_html( $invoice_number ) . '</p>';

        if ( $pdf_url ) {
            echo '<p><a href="' . esc_url( $pdf_url ) . '" target="_blank">View Current PDF</a></p>';
        }

        // Approve & Send
        $approve_url = admin_url('admin-post.php?action=hbi_approve_invoice&invoice_id=' . $post->ID . '&_wpnonce=' . wp_create_nonce('hbi_approve_invoice_' . $post->ID));
        echo '<p><a href="' . esc_url($approve_url) . '" class="button button-primary">Approve &amp; Send Invoice</a></p>';

        // Resend Invoice
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;">';
        wp_nonce_field( 'hbi_resend_invoice_' . $post->ID, 'hbi_nonce' );
        echo '<input type="hidden" name="action" value="hbi_resend_invoice">';
        echo '<input type="hidden" name="invoice_id" value="' . intval( $post->ID ) . '">';
        submit_button( 'Resend Invoice', 'secondary', 'submit', false );
        echo '</form>';
    }

    /**
     * Resend invoice (same number, new PDF, notify customer)
     */
    public function resend_invoice_action() {
        if ( ! isset( $_POST['invoice_id'], $_POST['hbi_nonce'] ) ) {
            wp_die( 'Invalid request' );
        }
        $invoice_id = intval( $_POST['invoice_id'] );
        if ( ! wp_verify_nonce( $_POST['hbi_nonce'], 'hbi_resend_invoice_' . $invoice_id ) ) {
            wp_die( 'Security check failed' );
        }

        // Regenerate PDF
        $pdf_url = HBI_Invoices::generate_pdf( $invoice_id );
        update_post_meta( $invoice_id, '_hbi_pdf_url', $pdf_url );

        // Email customer about updated invoice (no lettered number)
        $invoice_number = get_post_meta( $invoice_id, '_hbi_invoice_number', true );
        $customer_email = get_post_meta( $invoice_id, '_hbi_customer_email', true );
        $customer_name  = get_post_meta( $invoice_id, '_hbi_customer_name', true );
        $pdf_path = get_post_meta( $invoice_id, '_hbi_pdf_path', true );
        $attachments = [];
        if ( ! empty( $pdf_path ) && file_exists( $pdf_path ) ) {
            $attachments[] = $pdf_path;
        }
        $subject = 'Sandbaai Hall — Invoice #' . $invoice_number;
        $message = '<p>Dear ' . esc_html( $customer_name ) . ',</p>';
        $message .= '<p>This invoice has been updated. Please find the current version attached. Payment instructions are on the invoice.</p>';
        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($customer_email, $subject, $message, $headers, $attachments);

        wp_redirect( admin_url( 'post.php?post=' . $invoice_id . '&action=edit&hbi_message=resend' ) );
        exit;
    }
}

/**
 * Clean up Invoice edit screen and add Admin Notes with AJAX save
 */

// Remove Publish box and content editor
add_action('admin_menu', function() {
    remove_meta_box('submitdiv', 'hbi_invoice', 'side'); // Publish box
});
add_action('admin_init', function() {
    remove_post_type_support('hbi_invoice', 'editor'); // Big editor
});

// Add custom Admin Notes meta box
add_action('add_meta_boxes', function() {
    add_meta_box(
        'hbi_admin_notes',
        'Admin Notes',
        function($post) {
            $notes = get_post_meta($post->ID, '_hbi_admin_notes', true);
            wp_nonce_field('hbi_admin_notes_nonce', 'hbi_admin_notes_nonce_field');
            echo '<textarea id="hbi_admin_notes_field" style="width:100%;min-height:200px;">'
                . esc_textarea($notes)
                . '</textarea>';
            echo '<p><button type="button" class="button button-primary" id="hbi_save_notes_btn">Save Notes</button></p>';
            echo '<div id="hbi_save_notes_msg" style="margin-top:5px;"></div>';
        },
        'hbi_invoice',
        'normal',
        'high'
    );
});

// Handle AJAX save
add_action('wp_ajax_hbi_save_admin_notes', function() {
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('No permission');
    }
    $post_id = intval($_POST['post_id'] ?? 0);
    $notes   = sanitize_textarea_field($_POST['notes'] ?? '');
    update_post_meta($post_id, '_hbi_admin_notes', $notes);
    wp_send_json_success('Notes saved at ' . current_time('H:i:s'));
});

// Enqueue JS for AJAX save
add_action('admin_enqueue_scripts', function($hook) {
    global $post;
    if ($hook === 'post.php' && $post && $post->post_type === 'hbi_invoice') {
        wp_enqueue_script('hbi-admin-notes', plugin_dir_url(__FILE__) . 'js/hbi-admin-notes.js', ['jquery'], false, true);
        wp_localize_script('hbi-admin-notes', 'HBIAdminNotes', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('hbi_admin_notes_nonce')
        ]);
    }
});

// allows approval of invoice with one click on a link
function hbi_handle_approve_invoice() {
    if ( ! isset($_GET['invoice_id']) ) {
        wp_die( 'Invalid invoice request.' );
    }

    $invoice_id = intval($_GET['invoice_id']);

    // Require login
    if ( ! is_user_logged_in() ) {
        auth_redirect(); // WP handles login + redirect back
    }

    // Verify nonce
    if ( empty($_GET['_wpnonce']) || ! wp_verify_nonce($_GET['_wpnonce'], 'hbi_approve_invoice_' . $invoice_id ) ) {
        wp_die( 'Security check failed.' );
    }

    // Permission check
    if ( ! current_user_can( 'edit_post', $invoice_id ) ) {
        wp_die( 'No permission' );
    }

    // Generate PDF
    if ( class_exists( 'HBI_Invoices' ) ) {
        $pdf_url  = HBI_Invoices::generate_pdf( $invoice_id );
        if ( $pdf_url ) {
            update_post_meta( $invoice_id, '_hbi_pdf_url', $pdf_url );
        }
    }

    // Publish invoice post
    wp_update_post( array(
        'ID'          => $invoice_id,
        'post_status' => 'publish'
    ) );

    // Send final invoice email to customer
    $customer_email  = get_post_meta( $invoice_id, '_hbi_customer_email', true );
    $customer_name   = get_post_meta( $invoice_id, '_hbi_customer_name', true );
    $pdf_path        = get_post_meta( $invoice_id, '_hbi_pdf_path', true );
    $attachments     = array();

    if ( ! empty( $pdf_path ) && file_exists( $pdf_path ) ) {
        $attachments[] = $pdf_path;
    }

    $invoice_number  = get_post_meta( $invoice_id, '_hbi_invoice_number', true );
    $subject         = 'Sandbaai Hall — Invoice #' . $invoice_number;
    $message         = '<p>Dear ' . esc_html( $customer_name ) . ',</p>';
    $message        .= '<p>Please find attached your invoice for the requested booking. Payment instructions are on the invoice.</p>';
    $message        .= '<p>If you have questions, reply to this email.</p>';
    $headers         = array('Content-Type: text/html; charset=UTF-8');

    wp_mail( $customer_email, $subject, $message, $headers, $attachments );

    // Redirect back to invoice edit screen with success message
    wp_safe_redirect( admin_url( 'post.php?post=' . $invoice_id . '&action=edit&hbi_message=approved' ) );
    exit;
}
add_action( 'admin_post_hbi_approve_invoice', 'hbi_handle_approve_invoice' );
