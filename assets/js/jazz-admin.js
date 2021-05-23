function clearCache() {
    jQuery.ajax({
        type: "POST",
        dataType: "json",
        url: ajaxurl,
        data: {
            action: "cache_clear"
        },
        success: function (data) {
            jQuery(".notifications").append(
                '<div class="jazz-message info notice is-dismissible"><p>' +
                    data.message +
                    '</p><button id="jazz-dismiss-admin-message" class="notice-dismiss" type="button"><span class="screen-reader-text">Dismiss this notice.</span></button></div>'
            );
            jQuery("#jazz-dismiss-admin-message").click(function (e) {
                jQuery(".lever-message").fadeTo(100, 0, function () {
                    jQuery(".lever-message").remove();
                });
            });
        }
    });
}

jQuery(document).ready(
    (function ($) {
        $("#clear_cache").click(function (e) {
            e.preventDefault();
            clearCache();
        });
    })(jQuery)
);
