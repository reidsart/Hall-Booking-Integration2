jQuery(document).ready(function ($) {
    /**
     * Toggle custom hours input
     */
    $('#hbi-hours-type').on('change', function () {
        if ($(this).val() === 'custom') {
            $('#hbi-custom-hours-wrapper').slideDown();
        } else {
            $('#hbi-custom-hours-wrapper').slideUp();
        }
    }).trigger('change');

    /**
     * Expandable/collapsible tariff sections
     * (except Hall Hire Rates)
     */
    $('.hbi-tariff-section .hbi-toggle').on('click', function () {
        let section = $(this).closest('.hbi-tariff-section');
        section.toggleClass('open');
        section.find('.hbi-tariff-items').slideToggle();
    });

    /**
     * Quantity fields with up/down arrows
     */
    $('.hbi-qty-control').each(function () {
        let wrapper = $(this);
        let input = wrapper.find('input[type="number"]');

        wrapper.find('.hbi-qty-up').on('click', function () {
            let val = parseInt(input.val()) || 0;
            input.val(val + 1).trigger('change');
        });

        wrapper.find('.hbi-qty-down').on('click', function () {
            let val = parseInt(input.val()) || 0;
            if (val > 0) {
                input.val(val - 1).trigger('change');
            }
        });
    });

    /**
     * Multi-day toggle (show/hide end date)
     */
    $('#hbi-multiday').on('change', function () {
        if ($(this).is(':checked')) {
            $('#hbi-end-date-wrapper').slideDown();
        } else {
            $('#hbi-end-date-wrapper').slideUp();
        }
    }).trigger('change');
});