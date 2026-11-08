/* Tags box (Tagify). Saves the tags as an array of strings, as the tag-it version did. */
wp.customize.controlConstructor.mailoptin_tagit = wp.customize.Control.extend({
    ready: function () {
        var control = this,
            input = control.container.find('input.mo-tagify').get(0);

        if (!input || input._tagify) return;

        var options = JSON.parse(input.getAttribute('data-tagify-options') || '{}');

        var tagify = new Tagify(input, {
            placeholder: options.placeholder || '',
            whitelist: options.whitelist || [],
            enforceWhitelist: false,
            duplicates: false,
            dropdown: {enabled: options.onFocus ? 0 : 1, maxItems: 20, closeOnSelect: true},
            // As before: "http://", "https://" and "www." aren't accepted anywhere in a tag.
            validate: function (tag) {
                return !/(https?:\/\/|www\.)/i.test(tag.value);
            },
            editTags: false
        });

        // A rejected tag is removed straight away rather than left in red.
        tagify.on('invalid', function (e) {
            setTimeout(function () { tagify.removeTags(e.detail.tag); }, 600);
        });

        var save = function () {
            // Only accepted tags: a rejected one stays in tagify.value with __isValid set to its error.
            control.setting.set(tagify.value.filter(function (tag) { return undefined === tag.__isValid || true === tag.__isValid; })
                .map(function (tag) { return tag.value; }));
        };

        tagify.on('add', save).on('remove', save);
    }
});
