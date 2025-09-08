<?php
/**
 * Booking Form Class
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class HBI_Booking_Form {

    public function __construct() {
        add_shortcode( 'hall_booking_form', array( $this, 'render_form' ) );
    }

    public function render_form() {
        $tariffs_flat = get_option('hall_tariffs', []);
        $tariffs = [];
        foreach ($tariffs_flat as $tariff) {
            $cat = $tariff['category'];
            $label = $tariff['label'];
            $price = $tariff['price'];
            $tariffs[$cat][$label] = $price;
        }
        // Now use $tariffs (nested) in your form rendering logic as before!
        $deposits = get_option('hall_deposits', ['main_hall_deposit'=>2000, 'crockery_deposit'=>500]);
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
             <hr class="section-divider">
            <h3>CONTACT INFORMATION</h3>
            <label for="hbi_name">Your Name *</label>
            <input type="text" name="hbi_name" id="hbi_name" required>

            <label for="hbi_organization">Organization *</label>
            <input type="text" name="hbi_organization" id="hbi_organization" required>

            <label for="hbi_email">Your Email *</label>
            <input type="email" name="hbi_email" id="hbi_email" required>

            <label for="hbi_phone">Phone Number *</label>
            <input type="text" name="hbi_phone" id="hbi_phone" required>
            
             <hr class="section-divider">
                
            <h3>EVENT DETAILS</h3>
            <div style="display:flex; gap:24px;">
                <div style="flex:1;">
                    <label>Event Title*</label>
                    <input type="text" name="hbi_event_title" required style="width:100%;">
                </div>
                <div style="flex:1;">
                    <label>Privacy Setting</label>
                    <select name="hbi_event_privacy" id="hbi_event_privacy" required style="width:100%;">
                        <option value="public">Public Event</option>
                        <option value="private">Private Event</option>
                    </select>
                </div>
            </div>
                    
            <label>Event Description</label>
            <textarea name="hbi_event_description" style="width:100%;"></textarea>
            <div style="display:flex; gap:24px;">
                <div style="flex:1;">
                    <label>Preferred Space*</label>
                    <select name="hbi_space" id="hbi_space" required style="width:100%;">
                        <option value="">Please select a space</option>
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
    
    <div style="flex:1;">
        <label>Duration</label>
        <select name="hbi_multi_day" id="hbi_multi_day" required style="width:100%;">
            <option value="0">One day</option>
            <option value="1">Multi-day event</option>
        </select>
    </div>
    
    <div style="flex:1;" id="hbi_end_date_wrap">
        <label>Event End Date*</label>
        <input type="date" name="hbi_end_date" id="hbi_end_date" style="width:100%;">
    </div>
</div>
            
            <div style="height:12px;"></div>
            <div style="display:flex; gap:24px;">
                <div style="flex:1;">
                    <label>Event Time* ((include setup and break-down times)</label>
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

<hr class="section-divider">

            <h3>QUOTE & TARIFF SELECTION</h3>
            <div class="hbi-tariffs">
                <?php foreach ($tariffs as $category => $items): ?>
                    <?php if (strtolower($category) === 'hall hire rate'): ?>
                        <fieldset>
                            <legend><?php echo esc_html($category); ?> <span style="font-size:0.9em; font-weight:normal;">(Auto-calculated based on dates/times above)</span></legend>
                            <table style="width:100%; border-collapse:collapse;">
                                <?php foreach ($items as $label => $price): ?>
                                    <tr class="tariff-row"
                                        data-category="<?php echo esc_attr($category); ?>"
                                        data-label="<?php echo esc_attr($label); ?>">
                                        <td>
                                            <label style="color:#666; cursor:not-allowed;">
                                                <input type="checkbox"
                                                    class="tariff-item"
                                                    name="hbi_tariff[<?php echo esc_attr($category); ?>][<?php echo esc_attr($label); ?>]"
                                                    value="1"
                                                    data-price="<?php echo esc_attr($price); ?>"
                                                    data-category="<?php echo esc_attr($category); ?>"
                                                    data-label="<?php echo esc_attr($label); ?>"
                                                    style="cursor:not-allowed;"
                                                    disabled
                                                >
                                                <?php echo esc_html($label); ?>
                                            </label>
                                        </td>
                                        <td>
                                            <span class="static-qty-display">0</span>
                                            <input type="hidden" 
                                                name="hbi_quantity[<?php echo esc_attr($category); ?>][<?php echo esc_attr($label); ?>]" 
                                                value="0" 
                                                class="tariff-qty">
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
    <?php elseif (stripos($label, 'bar service') !== false): ?>
        <span class="bar-display">No</span>
        <input type="hidden" name="hbi_quantity[<?php echo esc_attr($category); ?>][<?php echo esc_attr($label); ?>]" value="0" class="tariff-qty bar-qty">
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
            <!-- Subtotal and deposits display -->
            <div style="text-align:right; font-size:1.1em; margin-top: 15px;">
                <div style="margin-bottom: 8px;">
                    <strong>Subtotal: <span id="quote-subtotal">R 0.00</span></strong>
                </div>
                
                <!-- Deposits section -->
                <div id="deposit-summary" style="margin: 10px 0; font-size: 0.95em;"></div>
                
                <!-- Solid line above total -->
                <hr style="border: none; border-top: 2px solid #1e4f91; margin: 10px 0; width: 200px; margin-left: auto;">
                
                <!-- Final total -->
                <div style="font-size: 1.2em;">
                    <strong>Total: <span id="quote-total">R 0.00</span></strong>
                </div>
            </div>
            <p>
  <label style="font-size:14px;">
    <input type="checkbox" name="hbi_agree_terms" value="1" required />
    I agree to the <a href="https://sandbaaihall.co.za/terms-rules-policies/" target="_blank" rel="noopener">Terms, Rules &amp; Policies</a>.
  </label>
</p>
            <button type="submit" class="button button-primary" style="margin-top:20px;">Submit Booking Request</button>
 
            <script>
jQuery(function($){
    // 1) set start date min to tomorrow (client-side convenience)
    (function setMinDate(){
        var d = new Date();
        d.setDate(d.getDate() + 1); // tomorrow
        var yyyy = d.getFullYear();
        var mm = ('0' + (d.getMonth() + 1)).slice(-2);
        var dd = ('0' + d.getDate()).slice(-2);
        var min = yyyy + '-' + mm + '-' + dd;
        $('input[name="hbi_start_date"]').attr('min', min);
        // also restrict end date if present
        $('input[name="hbi_end_date"]').attr('min', min);
    })();
});
</script>
            
        </form>
        <script>
        var mainHallDepositPrice = <?php echo json_encode($main_hall_deposit_price); ?>;
        var crockeryDepositPrice = <?php echo json_encode($crockery_deposit_price); ?>;
        </script>
<style>
.hbi-collapsible .hbi-collapsible-content { 
    display: none; 
}
.hbi-collapsible.expanded .hbi-collapsible-content { 
    display: block; 
}
.hbi-collapsible .hbi-toggle { 
    font-size: 1.1rem;
    background: #1e4f91;
    color: #fff;
    border: none;
    padding: 12px 16px;
    width: 100%;
    text-align: left;
    cursor: pointer;
    border-radius: 8px;
    transition: background 0.3s ease;
}
.hbi-collapsible .hbi-toggle:hover {
    background: #2360b0;
}
.hbi-collapsible {
    border: none;
    margin: 10px 0;
    padding: 0;
}
.hbi-collapsible legend {
    width: 100%;
    padding: 0;
    margin: 0;
}

/* ADD THE NEW SUBMIT BUTTON STYLES HERE: */
#hall-booking-form {
    text-align: left;
}

#hall-booking-form button[type="submit"] {
    display: block;
    margin: 20px auto 0 auto;
    background: #28a745 !important;
    color: #fff !important;
    padding: 12px 30px;
    font-size: 1.1rem;
    font-weight: 600;
    border: none !important;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(40, 167, 69, 0.3);
}

#hall-booking-form button[type="submit"]:hover {
    background: #218838 !important;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(40, 167, 69, 0.4);
}

#hall-booking-form button[type="submit"]:active {
    background: #1e7e34 !important;
    transform: translateY(0);
}

/* ADD THIS CSS TO YOUR EXISTING <style> SECTION IN THE PHP FORM */

/* Style all fieldsets with light blue backgrounds */
fieldset {
    background: #f0f7ff !important; /* Light blue background */
    border: 1px solid #c3d9f5 !important;
    border-radius: 8px !important;
    margin: 15px 0 !important;
    padding: 0 !important;
}

/* Style all fieldset legends (including Hall Hire Rate) */
fieldset legend {
    background: #1e4f91 !important; /* Dark blue like collapsible headers */
    color: #fff !important;
    padding: 12px 16px !important;
    margin: 0 !important;
    font-size: 1.1rem !important;
    font-weight: 600 !important;
    border-radius: 8px 8px 0 0 !important;
    width: 100% !important;
    box-sizing: border-box !important;
    border: none !important;
}

/* Content inside fieldsets */
fieldset table {
    margin: 15px !important;
    background: transparent !important;
}

/* Make collapsible sections match the new styling */
.hbi-collapsible {
    background: #f0f7ff !important; /* Light blue background */
    border: 1px solid #c3d9f5 !important;
    border-radius: 8px !important;
    margin: 15px 0 !important;
    padding: 0 !important;
}

/* Collapsible content area */
.hbi-collapsible-content {
    background: #f0f7ff !important; /* Light blue background */
    border: none !important;
    border-radius: 0 0 8px 8px !important;
    padding: 15px !important;
}

/* Ensure tables look good on light blue background */
.hbi-collapsible-content table,
fieldset table {
    width: 100% !important;
    border-collapse: collapse !important;
    background: transparent !important;
}

/* Style table rows for better visibility */
.hbi-collapsible-content table tr,
fieldset table tr {
    border-bottom: 1px solid #d6e8f5 !important;
}

/* Make checkboxes and inputs more visible */
.hbi-collapsible-content input[type="checkbox"],
fieldset input[type="checkbox"] {
    margin-right: 8px !important;
}

.hbi-collapsible-content input[type="number"],
fieldset input[type="number"] {
    background: #fff !important;
    border: 1px solid #c3d9f5 !important;
}

.section-divider {
    border: none;
    height: 5px;
    background: linear-gradient(to right, transparent, #c3d9f5 20%, #c3d9f5 80%, transparent);
    margin: 30px 0;
    border-radius: 2px;
}

/* Style the main section headings */
#hall-booking-form h3 {
    color: #0693E3 !important;
    font-weight: bold !important;
    font-size: 1.3rem !important;
    margin: 20px 0 15px 0 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}

</style>
        <?php
        return ob_get_clean();
    }
}
