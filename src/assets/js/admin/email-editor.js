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
    var openId = null, previewTimer = null, previewSeq = 0, previewXhr = null, draftTimer = null, saving = false, filling = false;
    var draftKey = 'mo-email-editor-draft-' + cfg.id;

    function clone(o) { return JSON.parse(JSON.stringify(o)); }
    function uid() { return 'b' + Math.random().toString(36).slice(2, 10); }
    function dirty() { return JSON.stringify(state) !== savedJson; }
    // Where a block is: the list it sits in (the email, or a column) and its place there.
    function locate(id) {
        var found = null;
        (function walk(list) {
            for (var i = 0; i < list.length && !found; i++) {
                if (list[i].id === id) found = {list: list, index: i, block: list[i]};
                else if (list[i].columns) list[i].columns.forEach(walk);
            }
        })(state.content);
        return found;
    }
    function blockById(id) { var at = locate(id); return at ? at.block : null; }
    function withNewIds(block) {
        block.id = uid();
        if (block.columns) block.columns.forEach(function (col) { col.forEach(withNewIds); });
        return block;
    }
    function columnCount(block) { return ((block.settings || {}).columns_layout || '50-50').split('-').length; }
    // As many columns as the layout has; blocks in columns past that move to the last one.
    function fitColumns(block) {
        var n = columnCount(block), cols = block.columns || [];
        while (cols.length < n) cols.push([]);
        while (cols.length > n) { var extra = cols.pop(); cols[cols.length - 1] = cols[cols.length - 1].concat(extra); }
        block.columns = cols;
    }

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
        if (block.columns) {
            var count = block.columns.reduce(function (n, col) { return n + col.length; }, 0);
            return (i18n.columnsSummary || '%1$s columns, %2$s elements').replace('%1$s', block.columns.length).replace('%2$s', count);
        }
        var text = s.text_content || s.button_text || s.image_alt_text || '';
        return $('<div>').html(String(text)).text().slice(0, 60);
    }

    function itemHtml(block) {
        var meta = cfg.blocks[block.type] || {title: block.type};
        var $li = $('<li class="mo-ee-item">').attr('data-id', block.id).toggleClass('is-open', block.id === openId);
        var $row = $('<div class="mo-ee-item-row">').appendTo($li);
        $row.append('<span class="mo-ee-handle dashicons dashicons-move" aria-hidden="true"></span>');
        $('<button type="button" class="mo-ee-item-open">').append($('<strong>').text(meta.title), $('<span>').text(summary(block))).appendTo($row);
        $row.append($('<button type="button" class="mo-ee-icon mo-ee-dup"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button>').attr('aria-label', i18n.duplicate || 'Duplicate'));
        $row.append($('<button type="button" class="mo-ee-icon mo-ee-del"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>').attr('aria-label', i18n.remove || 'Delete'));

        if (block.columns) {
            var $cols = $('<div class="mo-ee-cols">').appendTo($li);
            block.columns.forEach(function (col, i) {
                var $col = $('<div class="mo-ee-col-wrap">').appendTo($cols);
                $('<span class="mo-ee-col-label">').text((i18n.column || 'Column %s').replace('%s', i + 1)).appendTo($col);
                var $list = $('<ol class="mo-ee-col">').attr('data-col', i).appendTo($col);
                col.forEach(function (child) { $list.append(itemHtml(child)); });
                var $add = $('<select class="mo-ee-col-add">').attr('aria-label', i18n.addToColumn || 'Add to this column').attr('data-col', i);
                $add.append($('<option value="">').text(i18n.addToColumn || '+ Add'));
                Object.keys(cfg.blocks).forEach(function (type) {
                    if ('columns' !== type) $add.append($('<option>').val(type).text(cfg.blocks[type].title));
                });
                $col.append($add);
            });
        }

        return $li;
    }

    function renderList() {
        var $list = $('#mo-ee-blocks').empty();
        state.content.forEach(function (block) { $list.append(itemHtml(block)); });
        $('#mo-ee-empty').toggle(!state.content.length);
        makeSortable();
    }

    // Drag within the email, within a column, and between them. Columns stay at the top level.
    function makeSortable() {
        $('#mo-ee-blocks, #mo-ee-blocks .mo-ee-col').each(function () {
            var $l = $(this);
            if ($l.data('ui-sortable')) $l.sortable('destroy');
            $l.sortable({
                handle: '.mo-ee-handle',
                connectWith: '#mo-ee-blocks, #mo-ee-blocks .mo-ee-col',
                placeholder: 'mo-ee-drop',
                tolerance: 'pointer',
                receive: function (e, ui) {
                    if ($(this).hasClass('mo-ee-col') && ui.item.find('.mo-ee-cols').length) $(ui.sender).sortable('cancel');
                },
                stop: function () { rebuildFromList(); }
            });
        });
    }

    // After a drag: the state follows the order on screen. New objects are built, so the state
    // (and the undo step taken from it) is untouched until the order is known to have changed.
    function rebuildFromList() {
        var before = JSON.stringify(state), byId = {};
        (function walk(list) { list.forEach(function (b) { byId[b.id] = b; if (b.columns) b.columns.forEach(walk); }); })(state.content);

        function read($ol) {
            return $ol.children('li').map(function () {
                var b = byId[$(this).data('id')];
                if (!b) return null;
                var copy = $.extend({}, b);
                if (b.columns) copy.columns = $(this).find('> .mo-ee-cols > .mo-ee-col-wrap > .mo-ee-col').map(function () { return [read($(this))]; }).get();
                return copy;
            }).get();
        }

        var next = JSON.parse(JSON.stringify(read($('#mo-ee-blocks'))));
        if (JSON.stringify(next) === JSON.stringify(state.content)) return;

        beginEdit(true);
        state.content = next;
        changed(true);
    }

    function newBlock(type, settings) {
        var meta = cfg.blocks[type];
        if (!meta) return null;
        var block = {id: uid(), type: type, settings: $.extend(clone(meta.defaults || {}), settings || {})};
        if ('columns' === type) { block.columns = []; fitColumns(block); }
        return block;
    }

    // $column: [columns block id, column index] to add inside a column.
    function addBlock(type, column) {
        if (column && 'columns' === type) return;
        var block = newBlock(type);
        if (!block) return;
        beginEdit(true);
        if (column) {
            var parent = blockById(column[0]);
            if (!parent) return;
            parent.columns[column[1]].push(block);
        } else {
            state.content.push(block);
        }
        changed(true);
        openBlock(block.id);
    }

    // Ready-made columns: an image beside text.
    function addPreset(name) {
        var cols = newBlock('columns', {columns_layout: 'image-text' === name ? '33-67' : '67-33'});
        var image = newBlock('image', {image_width: '170'}), text = newBlock('text');
        if (!cols || !image || !text) return;
        cols.columns = 'image-text' === name ? [[image], [text]] : [[text], [image]];
        beginEdit(true);
        state.content.push(cols);
        changed(true);
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

    // Filling the forms from the state is not an edit: the colour picker and the classic editor
    // report a change when they are set, which would add undo steps and clear redo.
    function fill($scope, obj) {
        filling = true;
        try { fillFields($scope, obj); } finally { filling = false; }
    }

    function fillFields($scope, obj) {
        $scope.find('.mo-ee-input').each(function () {
            var $input = $(this), v = getValue(obj, $input);

            if ($input.is(':checkbox')) $input.prop('checked', v === true || v === 'true' || v === 'yes' || v === 1 || v === '1');
            else $input.val(v === undefined || v === null ? '' : v);

            if ($input.hasClass('mo-ee-color') && $input.hasClass('wp-color-picker')) $input.wpColorPicker('color', $input.val());

            // An open classic editor shows its own copy of the text; keep it in step after undo,
            // redo, a save or loading someone else's version.
            var ed = this.id && window.tinymce ? tinymce.get(this.id) : null;
            if (ed && ed.initialized) {
                var html = window.wp && wp.editor && wp.editor.autop ? wp.editor.autop($input.val()) : $input.val();
                if (ed.getContent() !== html) ed.setContent(html);
            }
        });
    }

    var codeMirror = null;

    /* ---------------------------------------------------------------- insert placeholder */

    // A menu of the placeholders this email fills in, put next to a field: it inserts {{key}} (or
    // {{key|fallback}}) where the cursor is, in a plain field or the classic editor.
    function placeholderMenu($field) {
        if (!(cfg.placeholders || []).length || $field.data('moPh')) return;
        $field.data('moPh', true);

        var $menu = $('<details class="mo-ee-ph"><summary class="button button-small"></summary><div class="mo-ee-ph-pop"></div></details>');
        $menu.find('summary').text('{ } ' + i18n.insertPlaceholder);
        var $pop = $menu.find('.mo-ee-ph-pop');
        var $search = $('<input type="search" class="mo-ee-ph-search">').attr('placeholder', i18n.search).attr('aria-label', i18n.search).appendTo($pop);
        var $list = $('<div class="mo-ee-ph-list">').appendTo($pop);
        var $fallback = $('<input type="text" class="mo-ee-ph-fallback">').attr('aria-label', i18n.fallback);
        $('<label class="mo-ee-ph-fb">').append($('<span>').text(i18n.fallback), $fallback, $('<small>').text(i18n.fallbackHint)).appendTo($pop);

        cfg.placeholders.forEach(function (group) {
            $('<div class="mo-ee-ph-group">').text(group.label).appendTo($list);
            group.items.forEach(function (item) {
                $('<button type="button" class="mo-ee-ph-item">').attr('data-key', item.key)
                    .append($('<span>').text(item.label), $('<code>').text(item.key)).appendTo($list);
            });
        });

        $search.on('input', function () {
            var q = this.value.toLowerCase();
            $list.find('.mo-ee-ph-item').each(function () { $(this).toggle(!q || $(this).text().toLowerCase().indexOf(q) !== -1); });
        });

        $list.on('click', '.mo-ee-ph-item', function () {
            var fb = $fallback.val().replace(/[{}|]/g, '').trim();
            insertAt($field, '{{' + $(this).data('key') + (fb ? '|' + fb : '') + '}}');
            $menu.prop('open', false);
            $fallback.val('');
        });

        // Above the classic editor as a whole, when the field is one.
        var $wrap = $field.closest('.wp-editor-wrap');
        ($wrap.length ? $wrap : $field).before($menu);
    }

    function insertAt($field, text) {
        var ed = $field[0].id && window.tinymce ? tinymce.get($field[0].id) : null;

        if (ed && !ed.isHidden()) {
            ed.insertContent(text);
            ed.save();
            $field.trigger('change');
            return;
        }

        var el = $field[0], start = el.selectionStart || 0, end = el.selectionEnd || 0;
        el.value = el.value.slice(0, start) + text + el.value.slice(end);
        el.selectionStart = el.selectionEnd = start + text.length;
        $field.trigger('input').trigger('change');
        el.focus();
    }

    function fillAll() {
        if (cfg.code) {
            var html = state.code || '';
            if (codeMirror && codeMirror.getValue() !== html) { filling = true; codeMirror.setValue(html); filling = false; }
            else if (!codeMirror) $('#mo-ee-code').val(html);
        }
        $('.mo-ee-template-input').each(function () { this.checked = this.value === state.template; });
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
        if (filling) return;
        var obj = target($input);
        if (!obj) return;

        var name = $input.data('name'), next = {};
        next[name] = obj[name] === undefined ? undefined : clone(obj[name]);
        setValue(next, $input);
        if (JSON.stringify(next[name]) === JSON.stringify(obj[name])) return;

        beginEdit();
        obj[name] = next[name];
        var open = blockById(openId);
        if ('columns_layout' === name && open && open.columns && obj === open.settings) fitColumns(open);
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
                        ed.on('input change keyup', function () { if (filling) return; ed.save(); onField($ta); });
                    }
                },
                quicktags: true, mediaButtons: true
            });
        });

        // Text and links can hold placeholders.
        $form.find('.mo-ee-richtext, [data-name$="_link"]').each(function () { placeholderMenu($(this)); });

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

        // One render at a time: an older one still running is no longer wanted.
        if (previewXhr) previewXhr.abort();

        previewXhr = $.post(cfg.ajax, {action: 'mo_email_editor_preview', id: cfg.id, nonce: cfg.nonce, state: JSON.stringify(state)});
        previewXhr.done(function (res) {
            // A newer change already asked for a newer preview.
            if (seq !== previewSeq || !res || !res.success) return;

            var frame = document.getElementById('mo-ee-frame');
            var doc = frame.contentDocument;

            // A link clicked in the preview took the frame to another site: start it again.
            if (!doc) {
                frame.src = 'about:blank';
                frame.onload = function () { frame.onload = null; drawPreview(); };
                return;
            }
            var y = doc && doc.defaultView ? doc.defaultView.scrollY : 0;

            // Swapped into the open frame: no reload, and the scroll position is kept.
            doc.open();
            doc.write(res.data.html);
            doc.close();
            if (doc.defaultView) doc.defaultView.scrollTo(0, y);
        }).always(function () {
            if (seq === previewSeq) {
                previewXhr = null;
                $('#mo-ee-updating').prop('hidden', true);
            }
        });
    }

    /* ---------------------------------------------------------------- saving */

    function save(force) {
        if (saving) return $.Deferred().reject().promise();
        saving = true;
        status(i18n.saving);

        var posted = JSON.stringify(state);
        var result = $.Deferred();

        $.post(cfg.ajax, {action: 'mo_email_editor_save', id: cfg.id, nonce: cfg.nonce, state: posted, version: version, force: force ? 1 : 0})
            .done(function (res) {
                saving = false;
                if (!res || !res.success) { status(i18n.failed); return result.reject(); }
                version = res.data.version;
                savedJson = JSON.stringify(res.data.state);
                try { localStorage.removeItem(draftKey); } catch (e) {}
                // Take the server's cleaned copy only if nothing was typed while saving.
                if (JSON.stringify(state) === posted) {
                    state = res.data.state;
                    fillAll();
                }
                changed(true);
                result.resolve(res);
            })
            .fail(function (xhr) {
                saving = false;
                var data = xhr.responseJSON && xhr.responseJSON.data;
                if (409 === xhr.status && data && data.conflict) {
                    if (window.confirm(i18n.conflict)) {
                        beginEdit(true);
                        state = data.state;
                        version = data.version;
                        savedJson = JSON.stringify(state);
                        fillAll();
                        changed(true);
                        return result.reject();
                    }
                    version = data.version;
                    return save(true).then(result.resolve, result.reject);
                }
                status(i18n.failed);
                result.reject();
            });

        return result.promise();
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

        // A draft made on an older saved version: saving it must not quietly replace the newer one.
        var stale = draft.version !== version;

        if (window.confirm(stale ? i18n.draftStale : i18n.draft)) {
            undoStack.push(JSON.stringify(state));
            state = draft.state;
            // Keep the draft's version, so saving it asks about the newer one.
            if (stale) version = draft.version;
            fillAll();
        } else {
            try { localStorage.removeItem(draftKey); } catch (e) {}
        }
    }

    /* ---------------------------------------------------------------- events */

    $(function () {
        if (!cfg.id) return;

        bind($('[data-group]'));
        $('[data-group="settings"] [data-name="email_campaign_title"], [data-group="settings"] [data-name="email_campaign_preheader"]').each(function () { placeholderMenu($(this)); });

        // An email written by hand: WordPress's code editor (CodeMirror), or the plain textarea.
        if (cfg.code) {
            var $code = $('#mo-ee-code').val(state.code || '');
            var onCode = function (value) {
                if (filling || value === state.code) return;
                beginEdit();
                state.code = value;
                changed(false);
            };
            if (cfg.codeEditor && window.wp && wp.codeEditor) {
                codeMirror = wp.codeEditor.initialize($code[0], cfg.codeEditor).codemirror;
                codeMirror.on('change', function () { onCode(codeMirror.getValue()); });
            } else {
                $code.on('input', function () { onCode(this.value); });
            }
        }
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

        $('.mo-ee-add-btn').on('click', function () {
            if ($(this).data('preset')) addPreset($(this).data('preset'));
            else addBlock($(this).data('type'));
        });
        $('#mo-ee-blocks')
            .on('click', '.mo-ee-item-open', function () { openBlock($(this).closest('li').data('id')); })
            .on('change', '.mo-ee-col-add', function () {
                var type = $(this).val(), id = $(this).closest('.mo-ee-cols').closest('li').data('id');
                if (type) addBlock(type, [id, parseInt($(this).data('col'), 10)]);
                $(this).val('');
            })
            .on('click', '.mo-ee-dup', function () {
                var at = locate($(this).closest('li').data('id'));
                if (!at) return;
                beginEdit(true);
                at.list.splice(at.index + 1, 0, withNewIds(clone(at.block)));
                changed(true);
            })
            .on('click', '.mo-ee-del', function () {
                var id = $(this).closest('li').data('id');
                if (!window.confirm(i18n.delete)) return;
                var at = locate(id);
                if (!at) return;
                beginEdit(true);
                if (id === openId || (openId && at.block.columns && JSON.stringify(at.block.columns).indexOf('"' + openId + '"') !== -1)) closeBlock();
                at.list.splice(at.index, 1);
                changed(true);
            });

        $('.mo-ee-block-done').on('click', closeBlock);

        // The template: the email's frame, drawn around the same content.
        $('#mo-ee').on('change', '.mo-ee-template-input', function () {
            if (!this.checked || this.value === state.template) return;
            beginEdit(true);
            state.template = this.value;
            changed(true);
        });

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

        // The next step (a broadcast's audience and sending): saved first.
        $('#mo-ee-next').on('click', function (e) {
            if (!dirty()) return;
            e.preventDefault();
            var href = this.href;
            save(false).done(function () { window.location.href = href; });
        });
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
