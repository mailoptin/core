/* Post details control: tick, drag to order, add custom fields. Saves a comma-separated list of keys. */
wp.customize.controlConstructor['mailoptin-post-meta'] = wp.customize.Control.extend({
    ready: function () {
        var control = this, $ = jQuery;
        var list = control.container.find('.mo-post-meta-list');

        var save = function () {
            control.setting.set(list.find('input:checked').map(function () {
                return this.value;
            }).get().join(','));
        };

        list.sortable({handle: '.mo-post-meta-handle', axis: 'y', update: save});
        list.on('change', 'input', save);

        var add = function () {
            var input = control.container.find('.mo-post-meta-add input');
            var name = $.trim(input.val()).replace(/[^A-Za-z0-9_\-]/g, '').substring(0, 100);

            if (!name) return;

            var key = 'meta:' + name;
            var existing = list.find('input').filter(function () {
                return this.value === key;
            });

            if (existing.length) {
                existing.prop('checked', true);
            } else {
                var item = $('<li><span class="dashicons dashicons-menu mo-post-meta-handle" aria-hidden="true"></span><label><input type="checkbox" checked> </label></li>');
                item.find('input').val(key);
                item.find('label').append(document.createTextNode(window.moPostMetaControl.customField.replace('%s', name)));
                list.prepend(item);
            }

            input.val('');
            save();
        };

        control.container.on('click', '.mo-post-meta-add button', add);
        control.container.on('keydown', '.mo-post-meta-add input', function (e) {
            if (13 === e.which) {
                e.preventDefault();
                add();
            }
        });
    }
});
