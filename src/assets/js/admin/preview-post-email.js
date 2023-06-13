(function ($) {
    $(window).on('load', function () {
        $('[data-postid]').on('click', function (e) {
            e.preventDefault();
            const postID = $(this).data('postid');
            const nonce = $(this).data('nonce');
            let emailForm = `<div id="email-modal" style="display: none;">
                <span id="mailoptin-success" style="display:none;">Email sent. Go check your message.</span>
                <form id="email-form">
                    <label for="campaigns">Choose a campaign</label>
                    <select name="campaigns" id="campaigns" required></select>
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required/>
                    <input id="email-preview" type="submit"/>
                </form>
                <input id="mailoptin-send-test-email-nonce" type="hidden" value="${nonce}"/>
            </div>`;
            $('#wpbody').append(emailForm);

            $.post(
                ajaxurl,
                {
                    action: 'mailoptin_get_campaigns',
                    post_id: postID,
                },
                function (campaigns) {
                    campaigns = JSON.parse(campaigns);
                    campaigns.forEach(campaign => {
                        $('#campaigns').append($('<option>', {
                            value: campaign.id,
                            text: campaign.name
                        }));
                    })
                }, "json");

            $('#email-form').on('submit', (e) => {
                e.preventDefault();
                const formData = $('#email-form').serializeArray();
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
                    function (e) {
                        $('#mailoptin-success').fadeIn().delay(3000).fadeOut();
                    }, "json");
            })
        });
    });
})(jQuery);