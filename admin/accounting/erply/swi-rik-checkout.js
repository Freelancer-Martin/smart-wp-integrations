jQuery(function ($) {
    var timer;
    var $regField = $('#billing_reg_no');
    var $errMsg   = $('<p class="swi-rik-error" style="color:#dc2626;font-size:12px;margin:4px 0 0;"></p>');
    $regField.closest('.form-row').append($errMsg);

    function lookupRik(regCode) {
        clearTimeout(timer);
        $errMsg.text('');
        if (!regCode || regCode.length < 7) return;
        timer = setTimeout(function () {
            $.post(swiRik.ajaxurl, {
                action:   'swi_rik_lookup',
                security: swiRik.nonce,
                reg_code: regCode,
            }, function (r) {
                if (!r.success) {
                    $errMsg.text(r.data && r.data.error ? r.data.error : 'Ettevõtet ei leitud.');
                    return;
                }
                var d = r.data;
                $errMsg.text('');
                if (d.name) $('#billing_company').val(d.name).trigger('change');
                if (d.vat)  $('#billing_vat_no').val(d.vat).trigger('change');
                if (d.address && swiRik.autofillAddress === '1') {
                    $('#billing_address_1').val(d.address).trigger('change');
                }
            }).fail(function () {
                $errMsg.text('Äriregistri päring ebaõnnestus. Proovi uuesti.');
            });
        }, 700);
    }

    $(document).on('input change', '#billing_reg_no', function () {
        lookupRik($(this).val().trim());
    });

    // B2B kohustuslikkus: kui billing_company täidetud ja reg_required seadistus on sees
    if (swiRik.regRequired === '1') {
        $(document.body).on('checkout_error', function () {
            if ($('#billing_company').val() && !$regField.val()) {
                $errMsg.text('Ärikliendina tellides on registrikood kohustuslik.');
                $regField.css('border-color', '#dc2626');
            }
        });
        $regField.on('input', function () {
            $(this).css('border-color', '');
            $errMsg.text('');
        });
    }
});
