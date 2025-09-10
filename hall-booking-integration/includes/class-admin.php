<?php
/**
 * Admin Class - Full version with Quick Edit + columns + original features preserved (Resend removed)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'HBI_Admin' ) ) {

class HBI_Admin {

    public function __construct() {
        // Register meta boxes + menus
        add_action( 'add_meta_boxes', array( $this, 'register_invoice_metabox' ) );
        add_action( 'admin_menu', array( $this, 'add_tariff_admin_menu' ), 99 );

        // Remove editor & publish box for invoices and add Admin Notes box
        add_action( 'admin_init', array( $this, 'cleanup_invoice_edit_screen' ) );

        // AJAX handler for Admin Notes
        add_action( 'wp_ajax_hbi_save_admin_notes', array( $this, 'ajax_save_admin_notes' ) );

        // Manage status changes
        add_action( 'admin_post_hbi_change_status', array( $this, 'handle_change_status' ) );

        // List table columns (Invoices)
        add_filter( 'manage_hbi_invoice_posts_columns', array( $this, 'add_invoice_columns' ) );
        add_action( 'manage_hbi_invoice_posts_custom_column', array( $this, 'render_invoice_columns' ), 10, 2 );

        // Quick Edit support for status
        add_action( 'quick_edit_custom_box', array( $this, 'quick_edit_custom_box' ), 10, 2 );
        add_action( 'save_post_hbi_invoice', array( $this, 'save_quick_edit' ) );
        add_action( 'admin_footer-edit.php', array( $this, 'quick_edit_javascript' ) );

        // Inline admin CSS for badges
        add_action( 'admin_head', array( $this, 'admin_inline_css' ) );
    }

    /******************************
     * Change status handler
     ******************************/
    public function handle_change_status() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'No permission.' );
        }

        $invoice_id = intval( $_GET['invoice_id'] ?? 0 );
        $status = sanitize_text_field( $_GET['status'] ?? '' );
        $nonce = $_GET['_wpnonce'] ?? '';

        if ( ! $invoice_id || ! wp_verify_nonce( $nonce, 'hbi_change_status_' . $invoice_id ) ) {
            wp_die( 'Invalid request.' );
        }

        if ( ! in_array( $status, ['unpaid','deposit','paid'], true ) ) {
            wp_die( 'Invalid status.' );
        }

        update_post_meta( $invoice_id, '_hbi_status', $status );

        // Send notifications only when fully paid
        if ( $status === 'paid' ) {
            $manager_email = 'manager@sandbaaihall.co.za';
            wp_mail( $manager_email, 'Invoice Paid', 'Invoice #' . $invoice_id . ' has been marked as Paid.' );

            $items = get_post_meta( $invoice_id, '_hbi_items', true );
            if ( $items && is_array($items) ) {
                foreach ( $items as $item ) {
                    if ( stripos( $item['label'], 'Bar Service' ) !== false ) {
                        wp_mail( 'bar@sandbaaihall.co.za', 'Bar Service Requested', 'An event with bar service has been paid. Invoice #' . $invoice_id );
                        break;
                    }
                }
            }
        }

        wp_redirect( admin_url( 'edit.php?post_type=hbi_invoice' ) );
        exit;
    }

    /******************************
     * Tariff admin menu & UI
     ******************************/
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
        // Prevent double rendering
        static $already_rendered = false;
        if ($already_rendered) return;
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

    /******************************
     * Invoice meta boxes registration
     ******************************/
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

    /******************************
     * Render Invoice Actions metabox
     * (Resend removed; keep Approve & Send and status display)
     ******************************/
    public function render_invoice_metabox( $post ) {
        $invoice_number = get_post_meta( $post->ID, '_hbi_invoice_number', true );
        $pdf_url = get_post_meta( $post->ID, '_hbi_pdf_url', true );
        $status = get_post_meta( $post->ID, '_hbi_status', true );

        echo '<p><strong>Invoice Number:</strong> ' . esc_html( $invoice_number ) . '</p>';

        if ( $pdf_url ) {
            echo '<p><a href="' . esc_url( $pdf_url ) . '" target="_blank">View Current PDF</a></p>';
        }

        // Status label in metabox
        $label = $status ? ucfirst( $status ) : 'Unpaid';
        $color = ( $status === 'paid' ) ? 'green' : ( $status === 'deposit' ? 'orange' : 'red' );
        echo '<p><strong>Status:</strong> <span style="color:' . esc_attr($color) . ';">' . esc_html($label) . '</span></p>';

        // Approve & Send (existing functionality)
        $approve_url = admin_url('admin-post.php?action=hbi_approve_invoice&invoice_id=' . $post->ID . '&_wpnonce=' . wp_create_nonce('hbi_approve_invoice_' . $post->ID));
        echo '<p><a href="' . esc_url($approve_url) . '" class="button button-primary">Approve &amp; Send Invoice</a></p>';
    }

    /******************************
     * Admin Notes metabox (AJAX save)
     ******************************/
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

    /******************************
     * Cleanup edit screen (remove editor & publish)
     ******************************/
    public function cleanup_invoice_edit_screen() {
        // Remove the content editor from the invoice post type
        remove_post_type_support( 'hbi_invoice', 'editor' );
        // Remove the Publish meta box
        remove_meta_box( 'submitdiv', 'hbi_invoice', 'side' );
    }

    /******************************
     * List table: add columns
     ******************************/
    public function add_invoice_columns( $columns ) {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[$key] = $label;
            if ( $key === 'title' ) {
                // Insert our columns after Title
                $new['hbi_status']      = 'Status';
                $new['hbi_start_date']  = 'Event Start Date';
                $new['hbi_submitted']   = 'Submitted On';
            }
        }
        return $new;
    }

    public function render_invoice_columns( $column, $post_id ) {
        if ( $column === 'hbi_status' ) {
            $status = get_post_meta( $post_id, '_hbi_status', true );
            $status = $status ?: 'unpaid';
            // Output a badge span with data-status for JS
            $class = 'hbi-status-' . esc_attr( $status );
            echo '<span class="hbi-invoice-status hbi-status-badge ' . $class . '" data-status="' . esc_attr($status) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
        }

        if ( $column === 'hbi_start_date' ) {
            $start = get_post_meta( $post_id, '_hbi_start_date', true );
            if ( $start ) {
                echo esc_html( date_i18n( 'D, j M Y', strtotime( $start ) ) );
            } else {
                echo '—';
            }
        }

        if ( $column === 'hbi_submitted' ) {
            $post = get_post( $post_id );
            if ( $post ) {
                echo esc_html( date_i18n( 'D, j M Y', strtotime( $post->post_date ) ) );
            } else {
                echo '—';
            }
        }
    }

    /******************************
     * Quick Edit UI (status)
     ******************************/
    public function quick_edit_custom_box( $column, $post_type ) {
        if ( $post_type !== 'hbi_invoice' || $column !== 'hbi_status' ) return;
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <label>
                    <span class="title"><?php _e( 'Invoice Status', 'hbi-events' ); ?></span>
                    <select name="hbi_status">
                        <option value="unpaid"><?php _e( 'Unpaid', 'hbi-events' ); ?></option>
                        <option value="deposit"><?php _e( 'Deposit', 'hbi-events' ); ?></option>
                        <option value="paid"><?php _e( 'Paid', 'hbi-events' ); ?></option>
                    </select>
                </label>
            </div>
        </fieldset>
        <?php
    }

    public function quick_edit_javascript() {
        global $current_screen;
        if ( ! isset( $current_screen ) || $current_screen->post_type !== 'hbi_invoice' ) return;
        ?>
        <script>
        jQuery(function($){
            // Extend inline edit to populate our status select
            var $wp_inline_edit = inlineEditPost.edit;
            inlineEditPost.edit = function( id ) {
                $wp_inline_edit.apply( this, arguments );
                var postId = 0;
                if ( typeof(id) == 'object' ) { postId = parseInt(this.getId(id)); }
                if ( postId > 0 ) {
                    var $editRow = $('#edit-' + postId);
                    var $postRow = $('#post-' + postId);
                    var status = null;
                    // Try to read data-status from a child element (we render data-status on the span)
                    var child = $postRow.find('.hbi-invoice-status[data-status]');
                    if ( child.length ) {
                        status = child.data('status');
                    } else {
                        // fallback: try to parse text
                        var txt = $postRow.find('.column-hbi_status').text().trim().toLowerCase();
                        if (txt) status = txt;
                    }
                    if ( status && $editRow.find('select[name="hbi_status"]').length ) {
                        $editRow.find('select[name="hbi_status"]').val(status);
                    }
                }
            };
        });
        </script>
        <?php
    }

    public function save_quick_edit( $post_id ) {
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        if ( isset( $_POST['hbi_status'] ) ) {
            $status = sanitize_text_field( $_POST['hbi_status'] );
            if ( in_array( $status, ['unpaid','deposit','paid'], true ) ) {
                update_post_meta( $post_id, '_hbi_status', $status );
            }
        }
    }

    /******************************
     * Admin inline CSS for badges
     ******************************/
    public function admin_inline_css() {
        echo '<style>
            .hbi-status-badge{display:inline-block;padding:3px 9px;border-radius:999px;color:#fff;font-weight:600;font-size:12px;line-height:1}
            .hbi-status-unpaid{background:#e74c3c}
            .hbi-status-deposit{background:#f39c12}
            .hbi-status-paid{background:#28a745}
            .column-hbi_start_date{width:12%}
            .column-hbi_status{width:12%}
            .column-hbi_submitted{width:12%}
        </style>';
    }

} // end class HBI_Admin

// instantiate only if we defined it here
new HBI_Admin();

} // end if class_exists()
