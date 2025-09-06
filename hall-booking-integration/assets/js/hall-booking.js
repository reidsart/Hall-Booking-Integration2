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

    // --- Collapsible sections
    $('.hbi-toggle').on('click', function () {
        var $fieldset = $(this).closest('.hbi-collapsible');
        $fieldset.toggleClass('expanded');
        $fieldset.find('.hbi-collapsible-content').slideToggle();
        // Rotate arrow
        $(this).html(
            $fieldset.hasClass('expanded') ?
            '&#9660; ' + $(this).text().replace('▶', '').replace('▼', '').trim() :
            '&#9654; ' + $(this).text().replace('▼', '').replace('▶', '').trim()
        );
        return false;
    });

    // --- Tariff selection and quantity
    $('.tariff-item').on('change', function () {
        var $row = $(this).closest('.tariff-row');
        var $qtyInput = $row.find('.tariff-qty');
        var $staticQty = $row.find('.static-qty-display');
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
    $('.crockery-qty').on('input', function () {
        updateDepositSummary();
        updateTotal();
    });
    $('.tariff-qty').on('input', updateTotal);

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
        var total = 0;
        $('.tariff-row').each(function () {
            var $row = $(this);
            var $cb = $row.find('.tariff-item');
            var price = parseFloat($cb.data('price')) || 0;
            var $staticQty = $row.find('.static-qty-display');
            var $qtyInput = $row.find('.tariff-qty');
            var qty = 0;
            if ($staticQty.length && $cb.prop('checked')) qty = 1;
            else if ($qtyInput.length && !$qtyInput.prop('disabled')) qty = parseInt($qtyInput.val()) || 0;
            total += price * qty;
        });
        var dep = getDepositState();
        if (typeof mainHallDepositPrice !== 'undefined' && dep.hall) total += Number(mainHallDepositPrice);
        if (typeof crockeryDepositPrice !== 'undefined' && dep.crockery) total += Number(crockeryDepositPrice);
        $('#quote-total').text('R ' + total.toFixed(2));
    }

// --- Hall Hire autofill logic
function autofillHallHireRates() {
    var space = $('#hbi_space').val();
    var time = $('#hbi_event_time').val();
    var startDate = $('#hbi_start_date').val();
    // FIXED: Changed from .is(':checked') to .val() == '1'
    var endDate = $('#hbi_multi_day').val() == '1' ? $('#hbi_end_date').val() : startDate;
    var days = (startDate && endDate) ? Math.max(1, Math.floor((new Date(endDate) - new Date(startDate)) / (1000 * 60 * 60 * 24)) + 1) : 1;
    var mainHallDayRateLabel = "Rate per day up to 24h00";
    var mainHallHourFirstLabel = "Rate per hour: for 1st hour";
    var mainHallHourAfterLabel = "Rate per hour: after 1st hour";

    // Reset Hall Hire checkboxes/qty
    $('.tariff-row[data-category="Hall Hire Rate"]').each(function () {
        var $cb = $(this).find('.tariff-item');
        var $qtyInput = $(this).find('.tariff-qty');
        $cb.prop('checked', false);
        if ($qtyInput.length) $qtyInput.val("0").prop('disabled', true);
    });

    if (space === "Main Hall" || space === "Both Spaces") {
        if (time === "Full Day") {
            $('.tariff-row[data-label="' + mainHallDayRateLabel + '"]').each(function () {
                var $cb = $(this).find('.tariff-item');
                var $qtyInput = $(this).find('.tariff-qty');
                $cb.prop('checked', true);
                if ($qtyInput.length) $qtyInput.val(days.toString()).prop('disabled', false);
            });
        } else if (time === "Morning" || time === "Afternoon" || time === "Evening") {
            var duration = 0;
            if (time === "Morning") { duration = 4; }
            if (time === "Afternoon") { duration = 5; }
            if (time === "Evening") { duration = 6; }
            var totalFirstHour = days;
            var totalAfterHour = (duration > 1) ? (days * (duration - 1)) : 0;
            $('.tariff-row[data-label="' + mainHallHourFirstLabel + '"]').each(function () {
                var $cb = $(this).find('.tariff-item');
                var $qtyInput = $(this).find('.tariff-qty');
                $cb.prop('checked', true);
                if ($qtyInput.length) $qtyInput.val(totalFirstHour.toString()).prop('disabled', false);
            });
            if (totalAfterHour > 0) {
                $('.tariff-row[data-label="' + mainHallHourAfterLabel + '"]').each(function () {
                    var $cb = $(this).find('.tariff-item');
                    var $qtyInput = $(this).find('.tariff-qty');
                    $cb.prop('checked', true);
                    if ($qtyInput.length) $qtyInput.val(totalAfterHour.toString()).prop('disabled', false);
                });
            }
        } else if (time === "Custom") {
            var customStart = parseInt($('#hbi_custom_start').val() || "8");
            var customEnd = parseInt($('#hbi_custom_end').val() || "9");
            if (customEnd <= customStart) customEnd += 24;
            var duration = customEnd - customStart;
            var totalFirstHour = days;
            var totalAfterHour = (duration > 1) ? (days * (duration - 1)) : 0;
            $('.tariff-row[data-label="' + mainHallHourFirstLabel + '"]').each(function () {
                var $cb = $(this).find('.tariff-item');
                var $qtyInput = $(this).find('.tariff-qty');
                $cb.prop('checked', true);
                if ($qtyInput.length) $qtyInput.val(totalFirstHour.toString()).prop('disabled', false);
            });
            if (totalAfterHour > 0) {
                $('.tariff-row[data-label="' + mainHallHourAfterLabel + '"]').each(function () {
                    var $cb = $(this).find('.tariff-item');
                    var $qtyInput = $(this).find('.tariff-qty');
                    $cb.prop('checked', true);
                    if ($qtyInput.length) $qtyInput.val(totalAfterHour.toString()).prop('disabled', false);
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
