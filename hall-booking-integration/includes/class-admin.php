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

        // Remove editor & publish box for invoices and add Admin Notes box
        add_action( 'admin_init', array( $this, 'cleanup_invoice_edit_screen' ) );

        // AJAX handler for Admin Notes
        add_action( 'wp_ajax_hbi_save_admin_notes', array( $this, 'ajax_save_admin_notes' ) );
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
        // Prevent double rendering of tariffs
        static $already_rendered = false;
        if ($already_rendered) {
            return;
        }
        $already_rendered = true;
        $tariffs = get_option('hall_tariffs', []);
        // Normalize stored format to flat array if needed
        if ($tariffs && is_array($tariffs) && isset($tariffs[0]) && is_array($tariffs[0]) && isset($tariffs[0]['category']) && isset($tariffs[0]['label'])) {
            // already flat
        } else {
            // if associative by category -> convert to flat
            if (is_array($tariffs)) {
                $new = [];
                foreach ($tariffs as $cat => $labels) {
                    if (!is_array($labels)) continue;
                    foreach ($labels as $label => $price) {
                        $new[] = ['category' => $cat, 'label' => $label, 'price' => $price];
                    }
                }
                if (!empty($new)) {
                    $tariffs = $new;
                    update_option('hall_tariffs', $tariffs);
                }
            }
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
     * Register Invoice Admin Metabox (Invoice Actions)
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

        // Add Admin Notes box
        add_meta_box(
            'hbi_admin_notes',
            'Admin Notes',
            array( $this, 'render_admin_notes_metabox' ),
            'hbi_invoice',
            'normal',
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
     * Render Admin Notes Metabox (with inline AJAX saving)
     */
    public function render_admin_notes_metabox( $post ) {
        $notes = get_post_meta( $post->ID, '_hbi_admin_notes', true );
        $nonce = wp_create_nonce( 'hbi_admin_notes_save_' . $post->ID );
        ?>
        <div id="hbi-admin-notes-wrap">
            <textarea id="hbi_admin_notes_field" style="width:100%;min-height:220px;"><?php echo esc_textarea( $notes ); ?></textarea>
            <p style="margin-top:8px;">
                <button type="button" class="button button-primary" id="hbi_save_notes_btn">Save Notes</button>
                <span id="hbi_save_notes_msg" style="margin-left:12px;"></span>
            </p>
        </div>

        <script type="text/javascript">
        (function($){
            $(document).ready(function(){
                $('#hbi_save_notes_btn').on('click', function(e){
                    e.preventDefault();
                    var postId = <?php echo intval($post->ID); ?>;
                    var notes = $('#hbi_admin_notes_field').val();
                    $('#hbi_save_notes_msg').css('color','black').text('Saving...');
                    $.post(ajaxurl, {
                        action: 'hbi_save_admin_notes',
                        post_id: postId,
                        notes: notes,
                        nonce: '<?php echo esc_js($nonce); ?>'
                    }, function(resp){
                        if (resp && resp.success) {
                            $('#hbi_save_notes_msg').css('color','green').text(resp.data);
                        } else {
                            var msg = (resp && resp.data) ? resp.data : 'Save failed';
                            $('#hbi_save_notes_msg').css('color','red').text(msg);
                        }
                    }, 'json').fail(function(){
                        $('#hbi_save_notes_msg').css('color','red').text('AJAX error while saving.');
                    });
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * AJAX handler: save admin notes
     */
    public function ajax_save_admin_notes() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $post_id = intval( $_POST['post_id'] ?? 0 );
        $notes   = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
        $nonce   = sanitize_text_field( $_POST['nonce'] ?? '' );

        if ( ! $post_id || empty( $nonce ) ) {
            wp_send_json_error( 'Missing data.' );
        }

        if ( ! wp_verify_nonce( $nonce, 'hbi_admin_notes_save_' . $post_id ) ) {
            wp_send_json_error( 'Security check failed.' );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( 'No permission to edit this invoice.' );
        }

        update_post_meta( $post_id, '_hbi_admin_notes', $notes );
        $time = current_time( 'H:i:s' );
        wp_send_json_success( 'Saved at ' . $time );
    }

    /**
     * Remove editor & publish box on invoice edit screen
     */
    public function cleanup_invoice_edit_screen() {
        // Remove the content editor from the invoice post type
        remove_post_type_support( 'hbi_invoice', 'editor' );
        // Remove the Publish meta box
        remove_meta_box( 'submitdiv', 'hbi_invoice', 'side' );
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

        // Email customer about updated invoice
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

// instantiate
new HBI_Admin();
