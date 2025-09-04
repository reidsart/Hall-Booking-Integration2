<?php
/**
 * Booking Form Class - FINAL WORKING DYNAMIC VERSION
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class HBI_Booking_Form {

    public function __construct() {
        add_shortcode( 'hall_booking_form', array( $this, 'render_form' ) );
    }

    public function render_form() {
        $tariffs = get_option('hall_tariffs', []);
        $main_hall_deposit_price = null;
        $crockery_deposit_price = null;
        foreach ($tariffs as $cat => &$items) {
            foreach ($items as $label => $price) {
                if (stripos($label, 'deposit') !== false) {
                    if (stripos($label, 'main hall') !== false) {
                        $main_hall_deposit_price = $price;
                        unset($items[$label]);
                    } elseif (stripos($label, 'crockery') !== false) {
                        $crockery_deposit_price = $price;
                        unset($items[$label]);
                    }
                }
            }
        }
        unset($items);
        if ($main_hall_deposit_price === null) $main_hall_deposit_price = 2000;
        if ($crockery_deposit_price === null) $crockery_deposit_price = 500;

        $main_hall_day_label = 'Rate per day up to 24h00';
        $main_hall_hour_first_label = 'Rate per hour: for 1st hour';
        $main_hall_hour_after_label = 'Rate per hour: after 1st hour';

        ob_start(); ?>
        <form id="hall-booking-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="hbi_process_booking">

            <h3>Contact Information</h3>
            <label for="hbi_name">Your Name *</label>
            <input type="text" name="hbi_name" id="hbi_name" required>

            <label for="hbi_organization">Organization *</label>
            <input type="text" name="hbi_organization" id="hbi_organization" required>

            <label for="hbi_email">Your Email *</label>
            <input type="email" name="hbi_email" id="hbi_email" required>

            <label for="hbi_phone">Phone Number *</label>
            <input type="text" name="hbi_phone" id="hbi_phone" required>

            <div style="display:flex; gap:24px;">
                <div style="flex:2;">
                    <label>Event Title*</label>
                    <input type="text" name="hbi_event_title" required style="width:100%;">
                </div>
                <div style="flex:1; display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" name="hbi_event_privacy" id="hbi_event_privacy" value="private" style="margin-top:0;">
                    <label for="hbi_event_privacy" style="margin-bottom:0;">Private Event</label>
                </div>
            </div>
            <label>Event Description</label>
            <textarea name="hbi_event_description" style="width:100%;"></textarea>
            <div style="display:flex; gap:24px;">
                <div style="flex:1;">
                    <label>Preferred Space*</label>
                    <select name="hbi_space" id="hbi_space" required style="width:100%;">
                        <option value="Main Hall">Main Hall</option>
                        <option value="Meeting Room">Meeting Room</option>
                        <option value="Both Spaces">Both Spaces</option>
                    </select>
                </div>
                <div style="flex:1;">
                    <label>Expected Number of Guests*</label>
                    <input type="number" name="hbi_guest_count" min="1" required style="width:100%;">
                </div>
            </div>
            <div style="height:12px;"></div>
            <div style="display:flex; gap:24px; align-items:center;">
                <div style="flex:1;">
                    <label>Event Start Date*</label>
                    <input type="date" name="hbi_start_date" id="hbi_start_date" required style="width:100%;">
                </div>
                <div style="flex:1; display:flex; align-items:center;">
                    <input type="checkbox" id="hbi_multi_day" name="hbi_multi_day" value="1" style="margin-top:0;">
                    <label for="hbi_multi_day" style="margin-bottom:0; margin-left:5px;">Multi-day event?</label>
                </div>
                <div style="flex:1;" id="hbi_end_date_wrap">
                    <label>Event End Date*</label>
                    <input type="date" name="hbi_end_date" id="hbi_end_date" style="width:100%;">
                </div>
            </div>
            <div style="height:12px;"></div>
            <div style="display:flex; gap:24px;">
                <div style="flex:1;">
                    <label>Event Time*</label>
                    <select name="hbi_event_time" id="hbi_event_time" required style="width:100%;">
                        <option value="Full Day">Full Day (8am-12:00am)</option>
                        <option value="Morning">Morning (8am-12pm)</option>
                        <option value="Afternoon">Afternoon (1pm-6pm)</option>
                        <option value="Evening">Evening (6pm-12:00am)</option>
                        <option value="Custom">Custom Hours</option>
                    </select>
                </div>
                <div id="hbi_custom_hours_wrap" style="flex:1; display:none; align-items:center; gap:12px;">
                    <label for="hbi_custom_start">Start Hour:</label>
                    <select name="hbi_custom_start" id="hbi_custom_start">
                        <?php foreach (['08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23'] as $t): ?>
                            <option value="<?php echo $t; ?>"><?php echo $t; ?>:00</option>
                        <?php endforeach; ?>
                    </select>
                    <label for="hbi_custom_end">End Hour:</label>
                    <select name="hbi_custom_end" id="hbi_custom_end">
                        <?php foreach (['09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24'] as $t): ?>
                            <option value="<?php echo $t; ?>"><?php echo $t; ?>:00</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="height:12px;"></div>

            <h3>Quote & Tariff Selection</h3>
            <div class="hbi-tariffs">
                <?php foreach ($tariffs as $category => $items): ?>
                    <?php if (strtolower($category) === 'hall hire rate'): ?>
                        <fieldset>
                            <legend><?php echo esc_html($category); ?></legend>
                            <table style="width:100%; border-collapse:collapse;">
                                <?php foreach ($items as $label => $price): ?>
                                    <tr class="tariff-row"
                                        data-category="<?php echo esc_attr($category); ?>"
                                        data-label="<?php echo esc_attr($label); ?>">
                                        <td>
                                            <label>
                                                <input type="checkbox"
                                                    class="tariff-item"
                                                    name="hbi_tariff[<?php echo esc_attr($category); ?>][<?php echo esc_attr($label); ?>]"
                                                    value="1"
                                                    data-price="<?php echo esc_attr($price); ?>"
                                                    data-category="<?php echo esc_attr($category); ?>"
                                                    data-label="<?php echo esc_attr($label); ?>"
                                                >
                                                <?php echo esc_html($label); ?>
                                            </label>
                                        </td>
                                        <td>
                                            <input type="number"
                                                name="hbi_quantity[<?php echo esc_attr($category); ?>][<?php echo esc_attr($label); ?>]"
                                                value="0" min="0" max="999"
                                                style="width:80px; text-align:center;"
                                                class="tariff-qty"
                                            >
                                        </td>
                                        <td>R <?php echo number_format((float)$price,2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </fieldset>
                    <?php else: ?>
                        <fieldset class="hbi-collapsible">
                            <legend>
                                <button type="button" class="hbi-toggle" aria-expanded="false" style="cursor:pointer;">&#9654; <?php echo esc_html($category); ?></button>
                            </legend>
                            <div class="hbi-collapsible-content" style="display:none;">
                                <table style="width:100%; border-collapse:collapse;">
                                    <?php foreach ($items as $label => $price):
                                        $is_spotlight_sound = (strtolower($category) == "spotlights & sound");
                                        $is_kitchen = (strtolower($category) == "kitchen hire");
                                        $is_crockery = (strtolower($category) == "crockery (each)");
                                        $is_cutlery = (strtolower($category) == "cutlery (each)");
                                        $is_glassware = (strtolower($category) == "glassware (each)");
                                        $qty_readonly = ($is_spotlight_sound || $is_kitchen);
                                    ?>
                                        <tr class="tariff-row"
                                            data-category="<?php echo esc_attr($category); ?>"
                                            data-label="<?php echo esc_attr($label); ?>"
                                        >
                                            <td>
                                                <label>
                                                    <input type="checkbox"
                                                        class="tariff-item"
                                                        name="hbi_tariff[<?php echo esc_attr($category); ?>][<?php echo esc_attr($label); ?>]"
                                                        value="1"
                                                        data-price="<?php echo esc_attr($price); ?>"
                                                        data-category="<?php echo esc_attr($category); ?>"
                                                        data-label="<?php echo esc_attr($label); ?>"
                                                    >
                                                    <?php echo esc_html($label); ?>
                                                </label>
                                            </td>
                                            <td>
                                                <?php if ($is_spotlight_sound || $is_kitchen): ?>
                                                    <span class="static-qty-display">0</span>
                                                <?php else: ?>
                                                    <input type="number"
                                                        name="hbi_quantity[<?php echo esc_attr($category); ?>][<?php echo esc_attr($label); ?>]"
                                                        value="0" min="0" max="999"
                                                        style="width:80px; text-align:center;"
                                                        class="tariff-qty <?php
                                                            echo $is_crockery || $is_cutlery || $is_glassware ? 'crockery-qty' : '';
                                                        ?>"
                                                    >
                                                <?php endif; ?>
                                            </td>
                                            <td>R <?php echo number_format((float)$price,2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        </fieldset>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div id="deposit-summary" style="margin-top:18px;"></div>
            <div style="text-align:right;font-size:1.2em;">
                <strong>Total: <span id="quote-total">R 0.00</span></strong>
            </div>
            <button type="submit" class="button button-primary" style="margin-top:20px;">Submit Booking Request</button>
        </form>
        <script>
        var mainHallDepositPrice = <?php echo json_encode($main_hall_deposit_price); ?>;
        var crockeryDepositPrice = <?php echo json_encode($crockery_deposit_price); ?>;
        </script>
        <style>
        .hbi-collapsible .hbi-collapsible-content { display: none; }
        .hbi-collapsible.expanded .hbi-collapsible-content { display: block; }
        .hbi-collapsible .hbi-toggle { font-size: 1em; background: none; border: none; padding: 0; }
        </style>
        <?php
        return ob_get_clean();
    }
}
