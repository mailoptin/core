(function ($) {
    $(window).on('load', function () {
        $('[data-postid]').on('click', function (e) {
            const postID = $(this).data('postid');
            $('#email-form').on('submit', (event) => {
                event.preventDefault();
                const formData = $(event.currentTarget).serializeArray();
                console.log(formData);
                const email_address = formData[1].value;
                const mailoptin_email_campaign_id = formData[0].value;
                $.post(
                    ajaxurl,
                    {
                        action: 'mailoptin_send_test_email',
                        email_campaign_id: mailoptin_email_campaign_id,
                        post_id: postID,
                        email: email_address,
                        security: $('#mailoptin-send-test-email-nonce').val()
                    },
                    function () {
                        console.log(postID);
                        $('#mailoptin-success').fadeIn().delay(3000).fadeOut();
                        event.currentTarget.submit();
                    }, "json");
            })
        });
    });
})(jQuery);