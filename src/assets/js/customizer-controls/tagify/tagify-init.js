/* Tags box (Tagify). Saves the tags as an array of strings, as the tag-it version did. */
wp.customize.controlConstructor.mailoptin_tagit = wp.customize.Control.extend({
    ready: function () {
        var control = this,
            input = control.container.find('input.mo-tagify').get(0);

        if (!input || input.__tagify) return;

        var options = JSON.parse(input.getAttribute('data-tagify-options') || '{}');

        var tagify = new Tagify(input, {
            placeholder: options.placeholder || '',
            whitelist: options.whitelist || [],
            enforceWhitelist: false,
            // tag-it also split on spaces, and a referrer never contains one.
            delimiters: ',| ',
            duplicates: false,
            dropdown: {enabled: options.onFocus ? 0 : 1, maxItems: 20, closeOnSelect: true},
            // As before: "http://", "https://" and "www." aren't accepted anywhere in a tag.
            validate: function (tag) {
                return !/(https?:\/\/|www\.)/i.test(tag.value);
            },
            editTags: false
        });

        var save = function () {
            // Only accepted tags: a rejected one stays in tagify.value with __isValid set to its error.
            control.setting.set(tagify.value.filter(function (tag) { return undefined === tag.__isValid || true === tag.__isValid; })
                .map(function (tag) { return tag.value; }));
        };

        tagify.on('add', save).on('remove', save);
    }
});
