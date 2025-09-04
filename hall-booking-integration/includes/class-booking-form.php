<?php
/**
 * Booking Form Class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBI_Booking_Form {

    public function __construct() {
        add_shortcode( 'hall_booking_form', array( $this, 'render_form' ) );
    }

    /**
     * Render the booking form HTML
     */
    public function render_form() {
        ob_start(); ?>

        <form id="hall-booking-form" method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
            <input type="hidden" name="action" value="hbi_process_booking">

            <!-- Customer Info -->
            <h3>Customer Information</h3>
            <p>
                <label for="hbi_name">Full Name *</label>
                <input type="text" name="hbi_name" id="hbi_name" required>
            </p>
            <p>
                <label for="hbi_email">Email *</label>
                <input type="email" name="hbi_email" id="hbi_email" required>
            </p>
            <p>
                <label for="hbi_phone">Phone *</label>
                <input type="tel" name="hbi_phone" id="hbi_phone" required>
            </p>

            <!-- Booking Dates -->
            <h3>Booking Dates</h3>
            <p>
                <label for="hbi_start_date">Start Date *</label>
                <input type="date" name="hbi_start_date" id="hbi_start_date" required>
            </p>
            <p>
                <input type="checkbox" id="hbi_multi_day" name="hbi_multi_day" value="1">
                <label for="hbi_multi_day">Multi-day booking?</label>
            </p>
            <p id="hbi_end_date_wrap" style="display:none;">
                <label for="hbi_end_date">End Date</label>
                <input type="date" name="hbi_end_date" id="hbi_end_date">
            </p>

            <!-- Tariffs -->
            <h3>Tariffs</h3>
            <div class="hbi-tariffs">

                <!-- Example: Hall Hire Rates (always expanded) -->
                <fieldset>
                    <legend>Hall Hire Rates</legend>
                    <p>
                        <label>Standard Hire</label>
                        <input type="number" name="hbi_tariff[hall_standard]" value="0" min="0" step="1">
                    </p>
                </fieldset>

                <!-- Example expandable section -->
                <fieldset class="hbi-collapsible">
                    <legend>
                        Catering Options <button type="button" class="hbi-toggle">+</button>
                    </legend>
                    <div class="hbi-collapsible-content" style="display:none;">
                        <p>
                            <label>Plates</label>
                            <input type="number" name="hbi_tariff[plates]" value="0" min="0" step="1">
                        </p>
                        <p>
                            <label>Cutlery Set</label>
                            <input type="number" name="hbi_tariff[cutlery]" value="0" min="0" step="1">
                        </p>
                    </div>
                </fieldset>

                <!-- Add more sections dynamically later via DB -->
            </div>

            <!-- Hours -->
            <h3>Booking Time</h3>
            <p>
                <label for="hbi_hours">Select Hours</label>
                <select name="hbi_hours" id="hbi_hours">
                    <option value="morning">Morning</option>
                    <option value="afternoon">Afternoon</option>
                    <option value="evening">Evening</option>
                    <option value="custom">Custom Hours (specify below)</option>
                </select>
            </p>
            <p id="hbi_custom_hours_wrap" style="display:none;">
                <label for="hbi_custom_hours">Custom Hours</label>
                <input type="text" name="hbi_custom_hours" id="hbi_custom_hours">
            </p>

            <!-- Notes -->
            <h3>Additional Notes</h3>
            <p>
                <textarea name="hbi_notes" rows="4"></textarea>
            </p>

            <!-- Submit -->
            <p>
                <button type="submit" class="button button-primary">Submit Booking</button>
            </p>
        </form>

        <?php
        return ob_get_clean();
    }
}