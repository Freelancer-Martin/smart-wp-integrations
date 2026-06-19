jQuery(function ($) {
    var timer;

    function lookupRik(regCode) {
        clearTimeout(timer);
        timer = setTimeout(function () {
            if (!regCode || regCode.length < 7) return;
            $.post(swiRik.ajaxurl, {
                action:   'swi_rik_lookup',
                security: swiRik.nonce,
                reg_code: regCode,
            }, function (r) {
                if (!r.success) return;
                var d = r.data;
                if (d.name)    $('#billing_company').val(d.name).trigger('change');
                if (d.vat)     $('#billing_vat_no').val(d.vat).trigger('change');
                if (d.address) $('#billing_address_1').val(d.address).trigger('change');
            });
        }, 700);
    }

    $(document).on('input change', '#billing_reg_no', function () {
        lookupRik($(this).val().trim());
    });
});
