(function ($) {
    $(window).on('load', function () {
        $('[data-postid]').on('click', function (e) {
            e.preventDefault();
            const postID = $(this).data('postid');

            $.post(ajaxurl, {
                action: 'mailoptin_preview_post_as_email',
                post_id: postID
            }, function (response) {
                let iframe = $('<iframe class="preview-post-iframe">').attr('srcdoc', response);
                $('#TB_ajaxWindowTitle').text('Newsletter Preview');
                $('#TB_ajaxContent').append(iframe);
                iframe.css({
                    'width': '100%',
                    'height': '100%'
                });
            });
        });
    });
})(jQuery);