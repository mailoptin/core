/**
 * MailOptin email editor. The email is one object: {content: [blocks], design: {}, settings: {}}.
 * Forms are drawn by PHP; this fills them, keeps the object up to date, asks the server to draw the
 * email after each change (no reload), saves, keeps a draft in this browser, and undoes.
 */
(function ($) {
    'use strict';

    var cfg = window.moEmailEditor || {};
    var i18n = cfg.i18n || {};
    var state = clone(cfg.state);
    var version = cfg.version;
    var savedJson = JSON.stringify(state);
    var undoStack = [], redoStack = [], editing = false;
    var openId = null, previewTimer = null, previewSeq = 0, draftTimer = null, saving = false;
    var draftKey = 'mo-email-editor-draft-' + cfg.id;

    function clone(o) { return JSON.parse(JSON.stringify(o)); }
    function uid() { return 'b' + Math.random().toString(36).slice(2, 10); }
    function dirty() { return JSON.stringify(state) !== savedJson; }
    function blockById(id) { for (var i = 0; i < state.content.length; i++) if (state.content[i].id === id) return state.content[i]; return null; }

    /* ---------------------------------------------------------------- history */

    // One undo step per burst of typing, not per keystroke; adding, moving and removing elements
    // ($always) are each their own step.
    function beginEdit(always) {
        if (editing && !always) return;
        editing = true;
        undoStack.push(JSON.stringify(state));
        if (undoStack.length > 100) undoStack.shift();
        redoStack = [];
        setTimeout(function () { editing = false; }, 800);
    }

    function restore(json) {
        editing = false;
        state = JSON.parse(json);
        if (openId && !blockById(openId)) closeBlock();
        fillAll();
        changed(true);
    }

    function undo() { if (undoStack.length) { redoStack.push(JSON.stringify(state)); restore(undoStack.pop()); } }
    function redo() { if (redoStack.length) { undoStack.push(JSON.stringify(state)); restore(redoStack.pop()); } }

    /* ---------------------------------------------------------------- after any change */

    function changed(structural) {
        status();
        renderList();
        $('#mo-ee-subject').text(state.settings.email_campaign_title || i18n.untitled);
        schedulePreview();
        clearTimeout(draftTimer);
        draftTimer = setTimeout(saveDraft, 2000);
    }

    function status(text) {
        $('#mo-ee-status').text(text || (dirty() ? i18n.unsaved : i18n.saved)).toggleClass('is-dirty', dirty());
        $('#mo-ee-undo').prop('disabled', !undoStack.length);
        $('#mo-ee-redo').prop('disabled', !redoStack.length);
    }

    /* ---------------------------------------------------------------- the element list */

    function summary(block) {
        var s = block.settings || {};
        var text = s.text_content || s.button_text || s.image_alt_text || '';
        return $('<div>').html(String(text)).text().slice(0, 60);
    }

    function renderList() {
        var $list = $('#mo-ee-blocks').empty();

        state.content.forEach(function (block) {
            var meta = cfg.blocks[block.type] || {title: block.type};
            var $li = $('<li class="mo-ee-item">').attr('data-id', block.id).toggleClass('is-open', block.id === openId);
            $li.append('<span class="mo-ee-handle dashicons dashicons-move" aria-hidden="true"></span>');
            $('<button type="button" class="mo-ee-item-open">').append($('<strong>').text(meta.title), $('<span>').text(summary(block))).appendTo($li);
            $li.append('<button type="button" class="mo-ee-icon mo-ee-dup" aria-label="Duplicate"><span class="dashicons dashicons-admin-page"></span></button>');
            $li.append('<button type="button" class="mo-ee-icon mo-ee-del" aria-label="Delete"><span class="dashicons dashicons-trash"></span></button>');
            $list.append($li);
        });

        $('#mo-ee-empty').toggle(!state.content.length);
    }

    function addBlock(type) {
        var meta = cfg.blocks[type];
        if (!meta) return;
        beginEdit(true);
        var block = {id: uid(), type: type, settings: clone(meta.defaults || {})};
        state.content.push(block);
        changed(true);
        openBlock(block.id);
    }

    /* ---------------------------------------------------------------- forms */

    function getValue(obj, $input) {
        var v = obj[$input.data('name')];
        var part = $input.data('part');
        return part ? (v && v[part] !== undefined ? v[part] : '') : v;
    }

    function setValue(obj, $input) {
        var name = $input.data('name'), part = $input.data('part'), v;

        if ($input.is(':checkbox')) v = $input.prop('checked');
        else if ($input.is('select[multiple]')) v = $input.val() || [];
        else v = $input.val();

        if (part) {
            obj[name] = $.extend({}, obj[name] || {});
            obj[name][part] = v;
        } else {
            obj[name] = v;
        }
    }

    function fill($scope, obj) {
        $scope.find('.mo-ee-input').each(function () {
            var $input = $(this), v = getValue(obj, $input);

            if ($input.is(':checkbox')) $input.prop('checked', v === true || v === 'true' || v === 'yes' || v === 1 || v === '1');
            else $input.val(v === undefined || v === null ? '' : v);

            if ($input.hasClass('mo-ee-color') && $input.hasClass('wp-color-picker')) $input.wpColorPicker('color', $input.val());
        });
    }

    function fillAll() {
        fill($('[data-group="design"]'), state.design);
        fill($('[data-group="settings"]'), state.settings);
        if (openId) fill($('.mo-ee-block-form'), blockById(openId).settings);
    }

    // Which object a field writes to.
    function target($input) {
        var group = $input.closest('[data-group]').data('group');
        if (group) return state[group];
        var block = blockById(openId);
        return block ? block.settings : null;
    }

    function bind($scope) {
        $scope.find('.mo-ee-color').each(function () {
            var $input = $(this);
            $input.wpColorPicker({
                change: function (e, ui) { $input.val(ui.color.toString()); onField($input); },
                clear: function () { $input.val(''); onField($input); }
            });
        });
    }

    function onField($input) {
        var obj = target($input);
        if (!obj) return;
        beginEdit();
        setValue(obj, $input);
        changed(false);
    }

    function openBlock(id) {
        var block = blockById(id);
        if (!block) return;

        closeBlock();
        openId = id;

        var $form = $('.mo-ee-block-form').html($('#mo-ee-form-' + block.type).html() || '');
        $('.mo-ee-block-title').text((cfg.blocks[block.type] || {}).title || block.type);
        showPane('block');

        fill($form, block.settings);
        bind($form);

        var $tabs = $form.find('.mo-ee-subtab');
        if ($tabs.length) $tabs.first().trigger('click');

        // The classic editor starts only when a text block is opened.
        $form.find('.mo-ee-richtext').each(function (i) {
            var $ta = $(this), edId = 'mo-ee-rich-' + id + '-' + i;
            $ta.attr('id', edId);
            if (!window.wp || !wp.editor) return;
            wp.editor.initialize(edId, {
                tinymce: {
                    wpautop: true, height: 260,
                    setup: function (ed) {
                        ed.on('input change keyup NodeChange', function () { ed.save(); onField($ta); });
                    }
                },
                quicktags: true, mediaButtons: true
            });
        });

        renderList();
    }

    function closeBlock() {
        if (!openId) return;

        $('.mo-ee-block-form .mo-ee-richtext').each(function () {
            if (window.wp && wp.editor && this.id) wp.editor.remove(this.id);
        });

        $('.mo-ee-block-form').empty();
        openId = null;
        showPane('content');
        renderList();
    }

    function showPane(name) {
        $('.mo-ee-pane').removeClass('is-on').filter('[data-pane="' + name + '"]').addClass('is-on');
        var tab = 'block' === name ? 'content' : name;
        $('.mo-ee-tab').each(function () {
            var on = $(this).data('panel') === tab;
            $(this).toggleClass('is-on', on).attr('aria-selected', on ? 'true' : 'false');
        });
    }

    /* ---------------------------------------------------------------- preview */

    function schedulePreview() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(drawPreview, 300);
    }

    function drawPreview() {
        var seq = ++previewSeq;
        $('#mo-ee-updating').prop('hidden', false);

        $.post(cfg.ajax, {action: 'mo_email_editor_preview', id: cfg.id, nonce: cfg.nonce, state: JSON.stringify(state)}).done(function (res) {
            // A newer change already asked for a newer preview.
            if (seq !== previewSeq || !res || !res.success) return;

            var frame = document.getElementById('mo-ee-frame');
            var doc = frame.contentDocument;
            var y = doc && doc.defaultView ? doc.defaultView.scrollY : 0;

            // Swapped into the open frame: no reload, and the scroll position is kept.
            doc.open();
            doc.write(res.data.html);
            doc.close();
            if (doc.defaultView) doc.defaultView.scrollTo(0, y);
        }).always(function () {
            if (seq === previewSeq) $('#mo-ee-updating').prop('hidden', true);
        });
    }

    /* ---------------------------------------------------------------- saving */

    function save(force) {
        if (saving) return $.Deferred().reject().promise();
        saving = true;
        status(i18n.saving);

        return $.post(cfg.ajax, {action: 'mo_email_editor_save', id: cfg.id, nonce: cfg.nonce, state: JSON.stringify(state), version: version, force: force ? 1 : 0})
            .done(function (res) {
                if (!res || !res.success) return;
                version = res.data.version;
                state = res.data.state;
                savedJson = JSON.stringify(state);
                try { localStorage.removeItem(draftKey); } catch (e) {}
                fillAll();
                changed(true);
            })
            .fail(function (xhr) {
                var data = xhr.responseJSON && xhr.responseJSON.data;
                if (409 === xhr.status && data && data.conflict) {
                    saving = false;
                    if (window.confirm(i18n.conflict)) {
                        undoStack.push(JSON.stringify(state));
                        state = data.state;
                        version = data.version;
                        savedJson = JSON.stringify(state);
                        fillAll();
                        changed(true);
                    } else {
                        version = data.version;
                        save(true);
                    }
                    return;
                }
                status(i18n.failed);
            })
            .always(function () { saving = false; });
    }

    function saveDraft() {
        try {
            if (dirty()) localStorage.setItem(draftKey, JSON.stringify({version: version, state: state}));
            else localStorage.removeItem(draftKey);
        } catch (e) {}
    }

    function offerDraft() {
        var draft = null;
        try { draft = JSON.parse(localStorage.getItem(draftKey) || 'null'); } catch (e) {}

        if (!draft || !draft.state || JSON.stringify(draft.state) === savedJson) return;

        if (window.confirm(i18n.draft)) {
            undoStack.push(JSON.stringify(state));
            state = draft.state;
            fillAll();
        } else {
            try { localStorage.removeItem(draftKey); } catch (e) {}
        }
    }

    /* ---------------------------------------------------------------- events */

    $(function () {
        if (!cfg.id) return;

        bind($('[data-group]'));
        offerDraft();
        fillAll();
        changed(true);
        drawPreview();

        $('.mo-ee-tab').on('click', function () { closeBlock(); showPane($(this).data('panel')); });
        $(document).on('click', '.mo-ee-subtab', function () {
            var tab = $(this).data('tab'), $form = $(this).closest('.mo-ee-block-form');
            $form.find('.mo-ee-subtab').removeClass('is-on').filter(this).addClass('is-on');
            $form.find('.mo-ee-subpanel').prop('hidden', true).filter('[data-tab="' + tab + '"]').prop('hidden', false);
        });

        $('.mo-ee-add-btn').on('click', function () { addBlock($(this).data('type')); });
        $('#mo-ee-blocks')
            .on('click', '.mo-ee-item-open', function () { openBlock($(this).closest('li').data('id')); })
            .on('click', '.mo-ee-dup', function () {
                var id = $(this).closest('li').data('id'), i = state.content.indexOf(blockById(id));
                beginEdit(true);
                var copy = clone(state.content[i]);
                copy.id = uid();
                state.content.splice(i + 1, 0, copy);
                changed(true);
            })
            .on('click', '.mo-ee-del', function () {
                var id = $(this).closest('li').data('id');
                if (!window.confirm(i18n.delete)) return;
                beginEdit(true);
                if (id === openId) closeBlock();
                state.content = state.content.filter(function (b) { return b.id !== id; });
                changed(true);
            })
            .sortable({
                handle: '.mo-ee-handle', axis: 'y',
                update: function () {
                    beginEdit(true);
                    var order = $('#mo-ee-blocks > li').map(function () { return $(this).data('id'); }).get();
                    state.content.sort(function (a, b) { return order.indexOf(a.id) - order.indexOf(b.id); });
                    changed(true);
                }
            });

        $('.mo-ee-block-done').on('click', closeBlock);

        $('#mo-ee').on('input change', '.mo-ee-input:not(.mo-ee-color)', function () { onField($(this)); });

        $(document).on('click', '.mo-ee-pick-image', function (e) {
            e.preventDefault();
            var $input = $(this).siblings('.mo-ee-input');
            var frame = wp.media({multiple: false, library: {type: 'image'}});
            frame.on('select', function () {
                $input.val(frame.state().get('selection').first().toJSON().url).trigger('change');
            });
            frame.open();
        });

        $('.mo-ee-device').on('click', function () {
            $('.mo-ee-device').removeClass('is-on').attr('aria-pressed', 'false');
            $(this).addClass('is-on').attr('aria-pressed', 'true');
            $('.mo-ee-preview').attr('data-device', $(this).data('device'));
        });

        $('#mo-ee-save').on('click', function () { save(false); });
        $('#mo-ee-undo').on('click', undo);
        $('#mo-ee-redo').on('click', redo);

        $('#mo-ee-test-send').on('click', function () {
            var email = $('#mo-ee-test-email').val();
            $.when(dirty() ? save(false) : null).then(function () {
                return $.post(cfg.ajax, {action: 'mo_email_editor_test', id: cfg.id, nonce: cfg.nonce, email: email});
            }).done(function (res) {
                status(res && res.success ? i18n.testSent : i18n.testFailed);
            }).fail(function () { status(i18n.testFailed); });
        });

        $(document).on('keydown', function (e) {
            if (!(e.ctrlKey || e.metaKey)) return;
            var key = String(e.key).toLowerCase();
            var inField = $(e.target).is('input, textarea, select, [contenteditable]');

            if ('s' === key) { e.preventDefault(); save(false); return; }
            // In a text field the browser's own undo applies.
            if (inField) return;
            if ('z' === key && !e.shiftKey) { e.preventDefault(); undo(); }
            if (('z' === key && e.shiftKey) || 'y' === key) { e.preventDefault(); redo(); }
        });

        $(window).on('beforeunload', function () { if (dirty()) return i18n.leave; });
    });
})(jQuery);
