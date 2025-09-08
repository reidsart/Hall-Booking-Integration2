jQuery(document).ready(function ($) {
// --- Multi-day logic
function updateMultiDay() {
    if ($('#hbi_multi_day').val() == '1') {
        $('#hbi_end_date_wrap').show();
    } else {
        $('#hbi_end_date_wrap').hide();
        $('#hbi_end_date').val($('#hbi_start_date').val());
    }
    autofillHallHireRates();
}

$('#hbi_multi_day').on('change', updateMultiDay);

$('#hbi_start_date').on('change', function () {
    if ($('#hbi_multi_day').val() != '1') {
        $('#hbi_end_date').val($(this).val());
    }
    autofillHallHireRates();
});

updateMultiDay();

    // --- Custom hours logic
    $('#hbi_event_time').on('change', function () {
        if ($(this).val() === 'Custom') {
            $('#hbi_custom_hours_wrap').show();
        } else {
            $('#hbi_custom_hours_wrap').hide();
        }
        autofillHallHireRates();
    });
    $('#hbi_custom_start, #hbi_custom_end').on('change', autofillHallHireRates);

    // --- Collapsible sections (FIXED - no more stuttering)
    $('.hbi-toggle').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        
        var $fieldset = $(this).closest('.hbi-collapsible');
        var $content = $fieldset.find('.hbi-collapsible-content');
        var isExpanded = $fieldset.hasClass('expanded');
        
        if (isExpanded) {
            // Closing
            $fieldset.removeClass('expanded');
            $content.slideUp();
            $(this).html('&#9654; ' + $(this).text().replace('▶', '').replace('▼', '').trim());
        } else {
            // Opening
            $fieldset.addClass('expanded');
            $content.slideDown();
            $(this).html('&#9660; ' + $(this).text().replace('▶', '').replace('▼', '').trim());
        }
        
        return false;
    });

    // --- Tariff selection and quantity (ENHANCED)
    $('.tariff-item').on('change', function () {
        var $row = $(this).closest('.tariff-row');
        var $qtyInput = $row.find('.tariff-qty');
        var $staticQty = $row.find('.static-qty-display');
        var category = $(this).data('category') || '';
        var isHallHire = category.toLowerCase() === 'hall hire rate';
        
        // Don't allow manual changes to Hall Hire Rate checkboxes
        if (isHallHire) {
            // Restore the checkbox to its previous state and return
            $(this).prop('checked', $(this).data('previous-state') || false);
            return;
        }
        
        if ($(this).prop('checked')) {
            if ($qtyInput.length) {
                $qtyInput.val("1").prop('disabled', false);
            }
            if ($staticQty.length) {
                $staticQty.text("1");
            }
        } else {
            if ($qtyInput.length) {
                $qtyInput.val("0").prop('disabled', true);
            }
            if ($staticQty.length) {
                $staticQty.text("0");
            }
        }
        updateDepositSummary();
        updateTotal();
    });
    
    // --- Quantity input changes (ENHANCED - auto-check items)
    $('.tariff-qty').on('input', function () {
        var $row = $(this).closest('.tariff-row');
        var $checkbox = $row.find('.tariff-item');
        var category = $checkbox.data('category') || '';
        var label = $checkbox.data('label') || '';
        var isHallHire = category.toLowerCase() === 'hall hire rate';
        var isFirstHour = label.indexOf('for 1st hour') !== -1;
        var qty = parseInt($(this).val()) || 0;
        
        // Don't allow manual changes to Hall Hire Rate quantities
        if (isHallHire) {
            // Restore the previous value and return
            $(this).val($(this).data('previous-value') || 0);
            return;
        }
        
        // Limit first hour to maximum of 1
        if (isFirstHour && qty > 1) {
            $(this).val(1);
            qty = 1;
        }
        
        // Auto-check/uncheck based on quantity
        if (qty > 0 && !$checkbox.prop('checked')) {
            $checkbox.prop('checked', true);
            $(this).prop('disabled', false);
        } else if (qty === 0 && $checkbox.prop('checked')) {
            $checkbox.prop('checked', false);
            $(this).prop('disabled', true);
        }
        
        updateTotal();
    });
    
    $('.crockery-qty').on('input', function () {
        // Handle crockery quantities (auto-check logic)
        var $row = $(this).closest('.tariff-row');
        var $checkbox = $row.find('.tariff-item');
        var qty = parseInt($(this).val()) || 0;
        
        if (qty > 0 && !$checkbox.prop('checked')) {
            $checkbox.prop('checked', true);
            $(this).prop('disabled', false);
        } else if (qty === 0 && $checkbox.prop('checked')) {
            $checkbox.prop('checked', false);
            $(this).prop('disabled', true);
        }
        
        updateDepositSummary();
        updateTotal();
    });

    // --- Deposit logic
    function getDepositState() {
        var space = $('#hbi_space').val();
        var hallDeposit = (space === "Main Hall" || space === "Both Spaces") ? 1 : 0;
        var crockeryChecked = false;
        $('.tariff-row').each(function () {
            var cat = ($(this).data('category') || '').toLowerCase();
            if (
                (cat === "crockery (each)" || cat === "glassware (each)" || cat === "cutlery (each)") &&
                $(this).find('.tariff-item').prop('checked')
            ) crockeryChecked = true;
            if (
                (cat === "crockery (each)" || cat === "glassware (each)" || cat === "cutlery (each)") &&
                parseInt($(this).find('.crockery-qty').val(), 10) > 0
            ) crockeryChecked = true;
        });
        return {
            hall: hallDeposit,
            crockery: crockeryChecked ? 1 : 0
        };
    }

    function updateDepositSummary() {
        var dep = getDepositState();
        var out = '';
        if (typeof mainHallDepositPrice !== 'undefined' && dep.hall) {
            out += '<div class="deposit-row" style="display:flex;justify-content:space-between;align-items:center;"><span>Main Hall refundable deposit</span><span>R ' + Number(mainHallDepositPrice).toFixed(2) + '</span></div>';
        }
        if (typeof crockeryDepositPrice !== 'undefined' && dep.crockery) {
            out += '<div class="deposit-row" style="display:flex;justify-content:space-between;align-items:center;"><span>Refundable deposit for crockery, cutlery, & glassware</span><span>R ' + Number(crockeryDepositPrice).toFixed(2) + '</span></div>';
        }
        $('#deposit-summary').html(out);
    }

    // --- Total calculation
function updateTotal() {
    var subtotal = 0;
    var totalDeposits = 0;
    
    // Calculate subtotal (tariffs only, no deposits)
    $('.tariff-row').each(function () {
        var $row = $(this);
        var $cb = $row.find('.tariff-item');
        var price = parseFloat($cb.data('price')) || 0;
        var $staticQty = $row.find('.static-qty-display');
        var $qtyInput = $row.find('.tariff-qty');
        var qty = 0;
        if ($staticQty.length && $cb.prop('checked')) qty = 1;
        else if ($qtyInput.length && !$qtyInput.prop('disabled')) qty = parseInt($qtyInput.val()) || 0;
        subtotal += price * qty;
    });
    
    // Calculate deposits separately
    var dep = getDepositState();
    if (typeof mainHallDepositPrice !== 'undefined' && dep.hall) totalDeposits += Number(mainHallDepositPrice);
    if (typeof crockeryDepositPrice !== 'undefined' && dep.crockery) totalDeposits += Number(crockeryDepositPrice);
    
    // Update display
    $('#quote-subtotal').text('R ' + subtotal.toFixed(2));
    $('#quote-total').text('R ' + (subtotal + totalDeposits).toFixed(2));
}

// --- Hall Hire autofill logic (ENHANCED - stores previous states)
function autofillHallHireRates() {
    var space = $('#hbi_space').val();
    var time = $('#hbi_event_time').val();
    var startDate = $('#hbi_start_date').val();
    var endDate = $('#hbi_multi_day').val() == '1' ? $('#hbi_end_date').val() : startDate;
    var days = (startDate && endDate) ? Math.max(1, Math.floor((new Date(endDate) - new Date(startDate)) / (1000 * 60 * 60 * 24)) + 1) : 1;
    
    var mainHallDayRateLabel = "Rate per day up to 24h00";
    var mainHallHourFirstLabel = "Rate per hour: for 1st hour";
    var mainHallHourAfterLabel = "Rate per hour: after 1st hour";

    // RESET ALL Hall Hire checkboxes/qty to 0 first
    $('.tariff-row[data-category="Hall Hire Rate"]').each(function () {
        var $cb = $(this).find('.tariff-item');
        var $qtyInput = $(this).find('.tariff-qty');
        
        // Store previous states for protection against manual changes
        $cb.data('previous-state', false);
        $qtyInput.data('previous-value', 0);
        
        $cb.prop('checked', false);
        if ($qtyInput.length) {
            $qtyInput.val("0").prop('readonly', true); // Make readonly instead of disabled
        }
    });

    // Only proceed if Main Hall is selected
    if (space === "Main Hall" || space === "Both Spaces") {
        if (time === "Full Day") {
            // FULL DAY: Only use daily rate
            $('.tariff-row[data-label="' + mainHallDayRateLabel + '"]').each(function () {
                var $cb = $(this).find('.tariff-item');
                var $qtyInput = $(this).find('.tariff-qty');
                $cb.prop('checked', true).data('previous-state', true);
                if ($qtyInput.length) {
                    $qtyInput.val(days.toString()).data('previous-value', days);
                }
            });
            
        } else if (time === "Morning" || time === "Afternoon" || time === "Evening" || time === "Custom") {
            // PARTIAL DAY: Use hourly rates
            var duration = 0;
            
            if (time === "Morning") { duration = 4; }
            else if (time === "Afternoon") { duration = 5; }
            else if (time === "Evening") { duration = 6; }
            else if (time === "Custom") {
                var customStart = parseInt($('#hbi_custom_start').val() || "8");
                var customEnd = parseInt($('#hbi_custom_end').val() || "9");
                if (customEnd <= customStart) customEnd += 24;
                duration = customEnd - customStart;
            }
            
            var totalFirstHour = days;
            var totalAfterHour = (duration > 1) ? (days * (duration - 1)) : 0;
            
            // Set first hour rate (always limited to quantity of days, max 1 per day)
            $('.tariff-row[data-label="' + mainHallHourFirstLabel + '"]').each(function () {
                var $cb = $(this).find('.tariff-item');
                var $qtyInput = $(this).find('.tariff-qty');
                var $staticQty = $(this).find('.static-qty-display');
                $cb.prop('checked', true).data('previous-state', true);
                if ($qtyInput.length) {
                    $qtyInput.val(totalFirstHour.toString()).data('previous-value', totalFirstHour);
                }
                if ($staticQty.length) {
                    $staticQty.text(totalFirstHour.toString());
                }
            });
            
            // Set additional hours rate
            if (totalAfterHour > 0) {
                $('.tariff-row[data-label="' + mainHallHourAfterLabel + '"]').each(function () {
                    var $cb = $(this).find('.tariff-item');
                    var $qtyInput = $(this).find('.tariff-qty');
                    var $staticQty = $(this).find('.static-qty-display');
                    $cb.prop('checked', true).data('previous-state', true);
                    if ($qtyInput.length) {
                        $qtyInput.val(totalAfterHour.toString()).data('previous-value', totalAfterHour);
                    }
                    if ($staticQty.length) {
                        $staticQty.text(totalAfterHour.toString());
                    }
                });
            }
        }
    }
    
    updateDepositSummary();
    updateTotal();
}

    $('#hbi_space').on('change', autofillHallHireRates);
    $('#hbi_start_date, #hbi_end_date').on('change', autofillHallHireRates);

    // Initial fill
    $('.hbi-collapsible .hbi-collapsible-content').hide();
    autofillHallHireRates();
    updateTotal();
});
