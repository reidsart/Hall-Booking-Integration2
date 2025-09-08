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

    // --- Helper: compute hours per day from the time selector
    function getPerDayHours() {
        var time = $('#hbi_event_time').val();
        if (time === 'Morning') return 4;
        if (time === 'Afternoon') return 5;
        if (time === 'Evening') return 6;
        if (time === 'Custom') {
            var s = parseInt($('#hbi_custom_start').val() || "8", 10);
            var e = parseInt($('#hbi_custom_end').val() || "9", 10);
            if (e <= s) e += 24;
            return Math.max(0, e - s);
        }
        return 0; // Full Day not handled here
    }

    // --- Total calculation (FIXED to use actual quantities)
    function updateTotal() {
        var subtotal = 0;
        var totalDeposits = 0;

        $('.tariff-row').each(function () {
            var $row = $(this);
            var $cb = $row.find('.tariff-item');
            var price = parseFloat($cb.data('price')) || 0;
            var category = ($cb.data('category') || '').toLowerCase();

            var qty = 0;

            // Prefer bar-qty if present (bar service special case)
            var $barQty = $row.find('.bar-qty');
            if ($barQty.length) {
                qty = parseInt($barQty.val(), 10) || 0;
            } else {
                // If there's a numeric input for qty, use it (Hall Hire rows use a hidden input)
                var $qtyInput = $row.find('.tariff-qty');
                var $staticQty = $row.find('.static-qty-display');

                if ($qtyInput.length) {
                    // For Hall Hire Rate rows, rely on the hidden input regardless of disabled state
                    if (category === 'hall hire rate') {
                        qty = parseInt($qtyInput.val(), 10) || 0;
                    } else {
                        // For regular items, only count if enabled & checked
                        if (!$qtyInput.prop('disabled') && $cb.prop('checked')) {
                            qty = parseInt($qtyInput.val(), 10) || 0;
                        } else {
                            qty = 0;
                        }
                    }
                } else if ($staticQty.length) {
                    // Rows with static quantity display (e.g., spotlights/kitchen)
                    qty = $cb.prop('checked') ? (parseInt($staticQty.text(), 10) || 0) : 0;
                }
            }

            subtotal += price * qty;
        });

        // Deposits
        var dep = getDepositState();
        if (typeof mainHallDepositPrice !== 'undefined' && dep.hall) totalDeposits += Number(mainHallDepositPrice);
        if (typeof crockeryDepositPrice !== 'undefined' && dep.crockery) totalDeposits += Number(crockeryDepositPrice);

        // Update display
        $('#quote-subtotal').text('R ' + subtotal.toFixed(2));
        $('#quote-total').text('R ' + (subtotal + totalDeposits).toFixed(2));
    }

    // --- Hall Hire autofill logic (MINIMAL CHANGES, correct labels/selectors)
    function autofillHallHireRates() {
        var space = $('#hbi_space').val();
        var time = $('#hbi_event_time').val();
        var startDate = $('#hbi_start_date').val();
        var endDate = ($('#hbi_multi_day').val() == '1') ? $('#hbi_end_date').val() : $('#hbi_start_date').val();

        if (!startDate) return;
        if (!endDate) endDate = startDate;

        var start = new Date(startDate);
        var end = new Date(endDate);
        var days = Math.max(1, Math.floor((end - start) / (1000 * 60 * 60 * 24)) + 1);

        // Label strings MUST match your PHP labels
        var mainHallDayRateLabel   = "Rate per day up to 24h00";
        var mainHallHourFirstLabel = "Rate per hour: for 1st hour";
        var mainHallHourAfterLabel = "Rate per hour: after 1st hour";
        var meetingRoomPerHourLabel = "Meeting room: per hour";

        // Helpers to reset/set rows by label
        function resetRow(label) {
            $('.tariff-row[data-label="' + label + '"]').each(function () {
                var $cb = $(this).find('.tariff-item');
                var $qtyInput = $(this).find('.tariff-qty');
                var $staticQty = $(this).find('.static-qty-display');
                $cb.prop('checked', false).data('previous-state', false);
                if ($qtyInput.length) $qtyInput.val("0").data('previous-value', 0);
                if ($staticQty.length) $staticQty.text("0");
            });
        }
        function setRowQty(label, qty) {
            $('.tariff-row[data-label="' + label + '"]').each(function () {
                var $cb = $(this).find('.tariff-item');
                var $qtyInput = $(this).find('.tariff-qty');
                var $staticQty = $(this).find('.static-qty-display');
                $cb.prop('checked', qty > 0).data('previous-state', qty > 0);
                if ($qtyInput.length) $qtyInput.val(String(qty)).data('previous-value', qty);
                if ($staticQty.length) $staticQty.text(String(qty));
            });
        }

        // Always start from a clean slate for Hall Hire items
        [mainHallDayRateLabel, mainHallHourFirstLabel, mainHallHourAfterLabel, meetingRoomPerHourLabel].forEach(resetRow);

        // --- Main Hall / Both Spaces
        if (space === "Main Hall" || space === "Both Spaces") {
            if (time === "Full Day") {
                // Day rate = one per day; hourly rates zeroed
                setRowQty(mainHallDayRateLabel, days);
            } else {
                // Not full day → day rate must be 0
                setRowQty(mainHallDayRateLabel, 0);

                var perDayHours = getPerDayHours(); // (Morning=4, Afternoon=5, Evening=6, Custom=calc)
                if (perDayHours > 0) {
                    // First hour → 1 per each selected day
                    setRowQty(mainHallHourFirstLabel, days);

                    // After first hour → (hours-1) per day × days
                    var afterHoursTotal = Math.max(0, perDayHours - 1) * days;
                    setRowQty(mainHallHourAfterLabel, afterHoursTotal);
                }
            }

            // Ensure Meeting Room per hour is reset when not selected
            setRowQty(meetingRoomPerHourLabel, 0);
        }

        // --- Meeting Room
        if (space === "Meeting Room") {
            // Reset any Main Hall rates
            setRowQty(mainHallDayRateLabel, 0);
            setRowQty(mainHallHourFirstLabel, 0);
            setRowQty(mainHallHourAfterLabel, 0);

            var qtyHours;
            if (time === "Full Day") {
                // Full-day Meeting Room = 8 hours per day selected
                qtyHours = 8 * days;
            } else {
                var perDayHoursMR = getPerDayHours();
                qtyHours = Math.max(0, perDayHoursMR) * days;
            }
            setRowQty(meetingRoomPerHourLabel, qtyHours);
        }

        // Update deposit + totals
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
