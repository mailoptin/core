/**
 * Spin to win, in the Customizer: the slice editor (Prize wheel › Slices) and the colour presets (Design).
 *
 * The slices are one setting, a JSON list, written back on every change. The editor is drawn from the
 * data the control prints (Wheel\WheelSlicesControl): the shop's coupons and products, how often each slice
 * has come up, and whether Premium is active (a winner's own single-use code is Premium).
 */
(function ($, api) {
    'use strict';

    var i18n = {
        shown: 'Shown on the wheel',
        prize: 'Prize',
        chance: 'Chance',
        colours: 'Colours',
        remove: 'Remove this slice',
        add: '+ Add a slice',
        cameUp: function (n) { return n === 1 ? 'came up once' : 'came up ' + n + ' times'; },
        types: {
            coupon: 'A WooCommerce coupon (a discount or free shipping)',
            product: 'A free product (WooCommerce)',
            code: 'A coupon code you type',
            link: 'Text and a link (a guide, a download)',
            none: 'No prize'
        },
        coupon: 'Coupon',
        pickCoupon: 'Pick a coupon',
        product: 'Product',
        pickProduct: 'Pick a product',
        freeCoupon: 'The coupon that makes it free',
        code: 'Code',
        codeHelp: 'Shown and emailed as typed. Make it in your shop first; every winner gets the same code.',
        where: 'Where to use it (optional)',
        title: 'What they won',
        text: 'A line about it (optional)',
        button: 'Button',
        link: 'Link',
        unique: 'Give each winner their own code',
        uniqueLocked: 'Everyone who wins this slice gets the same code, so it can be shared. With Premium, each winner gets a code that works once, only for their email.',
        uniqueOn: 'Each winner gets a copy of this coupon that works once, only for their email address.',
        premium: 'Premium',
        seePremium: 'See what Premium adds',
        total: function (t) { return 'Chances add up to ' + t + '%'; },
        totalOk: ' ✓',
        totalOff: function (t) { return 'They don\'t have to make 100: each slice comes up in proportion, so 25 of ' + t + ' is ' + (t ? Math.round(2500 / t) : 0) + '% of spins.'; },
        noShop: 'Shop coupons and free products need WooCommerce.'
    };

    function esc(s) {
        return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function Editor($control) {
        this.$el = $control.find('.mo-wheel-editor');
        this.$input = $control.find('.mo-wheel-slices-value');
        this.cfg = this.$el.data('config') || {};
        this.setting = api(this.$input.attr('data-customize-setting-link'));
        this.open = 0;

        try {
            this.slices = JSON.parse(this.$input.val() || '[]');
        } catch (e) {
            this.slices = [];
        }

        this.render();
        this.bind();
    }

    Editor.prototype.save = function () {
        var json = JSON.stringify(this.slices);
        this.$input.val(json);
        if (this.setting) this.setting.set(json);
    };

    Editor.prototype.newId = function () {
        var used = {}, i = 1;
        $.each(this.slices, function (k, s) { used[s.id] = true; });
        while (used['s' + i]) i++;
        return 's' + i;
    };

    Editor.prototype.options = function (list, selected, placeholder) {
        var html = '<option value="">' + esc(placeholder) + '</option>';
        $.each(list || [], function (k, o) {
            html += '<option value="' + esc(o.id) + '"' + (String(o.id) === String(selected) ? ' selected' : '') + '>' + esc(o.label) + '</option>';
        });
        return html;
    };

    Editor.prototype.summary = function (s) {
        var cfg = this.cfg, label = '';
        var find = function (list, id) {
            var hit = '';
            $.each(list || [], function (k, o) { if (String(o.id) === String(id)) hit = o.label; });
            return hit;
        };
        switch (s.type) {
            case 'coupon': label = find(cfg.coupons, s.coupon) || i18n.pickCoupon; break;
            case 'product': label = find(cfg.products, s.product) || i18n.pickProduct; break;
            case 'code': label = s.code || i18n.code; break;
            case 'link': label = s.title || s.url || i18n.link; break;
            default: label = i18n.types.none;
        }
        var n = cfg.counts && cfg.counts[s.id] ? parseInt(cfg.counts[s.id], 10) : 0;
        return label + (n ? ' · ' + i18n.cameUp(n) : '');
    };

    Editor.prototype.field = function (label, html, help) {
        return '<label class="mo-wheel-field"><span>' + esc(label) + '</span>' + html + (help ? '<em>' + esc(help) + '</em>' : '') + '</label>';
    };

    Editor.prototype.body = function (s, i) {
        var cfg = this.cfg, f = this.field.bind(this), html = '';
        var input = function (key, type) {
            return '<input type="' + (type || 'text') + '" data-key="' + key + '" value="' + esc(s[key]) + '">';
        };

        html += f(i18n.shown, input('label'));

        var types = '';
        $.each(i18n.types, function (key, label) {
            if ((key === 'coupon' || key === 'product') && !cfg.woocommerce && s.type !== key) return;
            types += '<option value="' + key + '"' + (s.type === key ? ' selected' : '') + '>' + esc(label) + '</option>';
        });
        html += f(i18n.prize, '<select data-key="type">' + types + '</select>', (s.type === 'coupon' || s.type === 'product') && !cfg.woocommerce ? i18n.noShop : '');

        if (s.type === 'coupon' || s.type === 'product') {
            if (s.type === 'product') html += f(i18n.product, '<select data-key="product">' + this.options(cfg.products, s.product, i18n.pickProduct) + '</select>');
            html += f(s.type === 'product' ? i18n.freeCoupon : i18n.coupon, '<select data-key="coupon">' + this.options(cfg.coupons, s.coupon, i18n.pickCoupon) + '</select>');

            if (cfg.premium) {
                html += '<label class="mo-wheel-toggle"><input type="checkbox" data-key="unique"' + (s.unique ? ' checked' : '') + '> ' + esc(i18n.unique) + '</label>'
                    + (s.unique ? '<em class="mo-wheel-help">' + esc(i18n.uniqueOn) + '</em>' : '');
            } else {
                html += '<div class="mo-wheel-locked"><label class="mo-wheel-toggle"><input type="checkbox" disabled> ' + esc(i18n.unique)
                    + ' <span class="mo-wheel-pill">' + esc(i18n.premium) + '</span></label><p>' + esc(i18n.uniqueLocked) + '</p>'
                    + '<a href="' + esc(cfg.upgrade) + '" target="_blank">' + esc(i18n.seePremium) + '</a></div>';
            }
        }

        if (s.type === 'code') {
            html += f(i18n.code, input('code'), i18n.codeHelp);
            html += f(i18n.where, input('url', 'url'));
        }

        if (s.type === 'link') {
            html += f(i18n.title, input('title'));
            html += f(i18n.text, input('text'));
            html += f(i18n.button, input('button'));
            html += f(i18n.link, input('url', 'url'));
        }

        html += '<div class="mo-wheel-row">' + f(i18n.chance + ' (%)', '<input type="number" min="0" max="100" data-key="chance" value="' + esc(s.chance) + '">')
            + f(i18n.colours, '<span class="mo-wheel-colours"><input type="color" data-key="color" value="' + esc(s.color) + '" aria-label="Slice colour">'
                + '<input type="color" data-key="ink" value="' + esc(s.ink) + '" aria-label="Text colour"></span>') + '</div>';

        if (this.slices.length > (cfg.min || 2)) html += '<button type="button" class="button-link mo-wheel-remove" data-remove="' + i + '">' + esc(i18n.remove) + '</button>';

        return html;
    };

    Editor.prototype.render = function () {
        var self = this, html = '', total = 0;

        $.each(this.slices, function (i, s) {
            total += parseInt(s.chance, 10) || 0;
            var open = i === self.open;
            html += '<div class="mo-wheel-slice' + (open ? ' is-open' : '') + '" data-index="' + i + '">'
                + '<button type="button" class="mo-wheel-head" aria-expanded="' + (open ? 'true' : 'false') + '">'
                + '<span class="mo-wheel-swatch" style="background:' + esc(s.color) + '"></span>'
                + '<span class="mo-wheel-name"><b>' + esc(s.label) + '</b><small>' + esc(self.summary(s)) + '</small></span>'
                + '<span class="mo-wheel-chance">' + esc(s.chance) + '%</span></button>'
                + (open ? '<div class="mo-wheel-body">' + self.body(s, i) + '</div>' : '')
                + '</div>';
        });

        html += '<div class="mo-wheel-total' + (total === 100 ? ' is-ok' : '') + '"><b>' + esc(i18n.total(total)) + (total === 100 ? i18n.totalOk : '') + '</b>'
            + (total !== 100 ? '<span>' + esc(i18n.totalOff(total)) + '</span>' : '') + '</div>';

        if (this.slices.length < (this.cfg.max || 12)) html += '<button type="button" class="button mo-wheel-add">' + esc(i18n.add) + '</button>';

        this.$el.html(html);
    };

    Editor.prototype.bind = function () {
        var self = this;

        this.$el.on('click', '.mo-wheel-head', function () {
            var i = parseInt($(this).closest('.mo-wheel-slice').data('index'), 10);
            self.open = self.open === i ? -1 : i;
            self.render();
        });

        this.$el.on('change input', '[data-key]', function (e) {
            var $f = $(this), i = parseInt($f.closest('.mo-wheel-slice').data('index'), 10), key = $f.data('key'), s = self.slices[i];
            if (!s) return;

            var value = $f.is(':checkbox') ? $f.is(':checked') : $f.val();
            if (key === 'chance') value = Math.max(0, Math.min(100, parseInt(value, 10) || 0));
            if (key === 'coupon' || key === 'product') value = parseInt(value, 10) || 0;
            s[key] = value;

            self.save();

            // A new prize type changes the fields; ticking a box, too. Typing doesn't redraw (the cursor stays).
            if (e.type === 'change' && (key === 'type' || key === 'unique' || key === 'chance' || key === 'color')) self.render();
            else self.$el.find('.mo-wheel-slice[data-index="' + i + '"] .mo-wheel-name b').text(s.label);
        });

        this.$el.on('click', '.mo-wheel-remove', function () {
            self.slices.splice(parseInt($(this).data('remove'), 10), 1);
            self.open = -1;
            self.save();
            self.render();
        });

        this.$el.on('click', '.mo-wheel-add', function () {
            var last = self.slices[self.slices.length - 1] || {color: '#2f6b4f', ink: '#ffffff'};
            self.slices.push({id: self.newId(), label: 'New prize', type: 'code', code: '', chance: 0, color: last.color, ink: last.ink});
            self.open = self.slices.length - 1;
            self.save();
            self.render();
        });
    };

    /** A preset sets the colours it lists and recolours the slices, in order. */
    function applyPreset(name, editor) {
        var preset = (window.moWheel && moWheel.presets && moWheel.presets[name]) || null;
        if (!preset) return;

        $.each(preset.colors, function (key, value) {
            var setting = api(moWheel.prefix + '[' + key + ']');
            if (setting) setting.set(value);
        });

        if (editor) {
            $.each(editor.slices, function (i, s) {
                var c = preset.slices[i % preset.slices.length];
                s.color = c[0];
                s.ink = c[1];
            });
            editor.save();
            editor.render();
        }
    }

    api.bind('ready', function () {
        var editor = null;

        $('.customize-control-mo_wheel_slices').each(function () {
            editor = new Editor($(this));
        });

        if (window.moWheel) {
            var preset = api(moWheel.prefix + '[wheel_preset]');
            if (preset) preset.bind(function (name) { applyPreset(name, editor); });
        }
    });

    $('<style>').text(
        '.mo-wheel-slice{border:1px solid #dcdcde;border-radius:6px;margin:0 0 8px;background:#fff}'
        + '.mo-wheel-slice.is-open{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}'
        + '.mo-wheel-head{display:flex;align-items:center;gap:10px;width:100%;min-height:44px;padding:8px 10px;border:0;background:none;text-align:left;cursor:pointer}'
        + '.mo-wheel-swatch{flex:none;width:14px;height:14px;border-radius:4px;border:1px solid rgba(0,0,0,.15)}'
        + '.mo-wheel-name{flex:1;min-width:0}.mo-wheel-name b{display:block;color:#1d2327}.mo-wheel-name small{display:block;color:#646970;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
        + '.mo-wheel-chance{color:#646970;font-variant-numeric:tabular-nums}'
        + '.mo-wheel-body{padding:2px 12px 12px}'
        + '.mo-wheel-field{display:block;margin:10px 0 0}.mo-wheel-field>span{display:block;font-weight:600;color:#1d2327;margin-bottom:4px}'
        + '.mo-wheel-field input[type=text],.mo-wheel-field input[type=url],.mo-wheel-field input[type=number],.mo-wheel-field select{width:100%;max-width:100%}'
        + '.mo-wheel-field em,.mo-wheel-help{display:block;color:#646970;font-style:normal;font-size:12px;margin-top:4px}'
        + '.mo-wheel-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}'
        + '.mo-wheel-colours{display:flex;gap:6px}.mo-wheel-colours input{width:44px;height:32px;padding:0;border:1px solid #c3c4c7;border-radius:4px}'
        + '.mo-wheel-toggle{display:flex;align-items:center;gap:8px;margin:12px 0 0;font-weight:600;color:#1d2327}'
        + '.mo-wheel-locked{margin-top:10px;padding:10px 12px;background:#f6f7f7;border:1px dashed #c3c4c7;border-radius:6px}.mo-wheel-locked .mo-wheel-toggle{margin:0;color:#646970}'
        + '.mo-wheel-locked p{margin:6px 0;color:#646970;font-size:12px}'
        + '.mo-wheel-pill{display:inline-block;font-size:11px;font-weight:600;color:#5b2a9e;background:#f3ecff;border-radius:999px;padding:1px 8px;margin-left:4px}'
        + '.mo-wheel-remove{margin-top:12px;color:#b32d2e!important}'
        + '.mo-wheel-total{padding:8px 2px;color:#1d2327}.mo-wheel-total.is-ok b{color:#00701a}.mo-wheel-total span{display:block;color:#646970;font-size:12px;margin-top:2px}'
        + '.mo-wheel-add{margin:4px 0 0}'
        + '.mo-wheel-box{padding:12px;background:#f6f7f7;border:1px dashed #c3c4c7;border-radius:6px;font-size:13px}.mo-wheel-box p{margin:6px 0 0;color:#646970}'
    ).appendTo('head');
})(jQuery, wp.customize);
