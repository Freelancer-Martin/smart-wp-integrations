jQuery(document).ready(function ($) {
    $.ajax({
        url: MyAjax.ajaxurl,
        type: "POST",
        data: {
            action: "my_custom_action",
            security: MyAjax.nonce
        },
        success: function(response) {
            console.log("Täielik response:", response);       // kogu objekt
            console.log("Ainult data:", response.data);       // wp_send_json_success payload
            //alert(JSON.stringify(response.data, null, 2));    // inimloetavaks JSON stringiks
        },
        error: function(xhr, status, error) {
            console.group("AJAX viga");
            console.error("Status text:", status);
            console.error("Error message:", error);
            console.error("HTTP status code:", xhr.status);
            console.error("Server response text:", xhr.responseText);
            console.error("XHR object:", xhr);
            console.groupEnd();
        }
    });



    $.ajax({
        url: MyAjax.ajaxurl,
        type: "POST",
        data: {
            action: "send_invoice_action",
            security: MyAjax.nonce
        },
        success: function(response) {
            console.log("Emailid on saadetud Ajaxiga:", response);       // kogu objekt
            console.log("Emailid on saadetud Ajaxiga Ainult data:", response.data);       // wp_send_json_success payload
            //alert(JSON.stringify(response.data, null, 2));    // inimloetavaks JSON stringiks
        },
        error: function(xhr, status, error) {
            console.group("AJAX viga");
            console.error("Status text:", status);
            console.error("Error message:", error);
            console.error("HTTP status code:", xhr.status);
            console.error("Server response text:", xhr.responseText);
            console.error("XHR object:", xhr);
            console.groupEnd();
        }
    });


});

