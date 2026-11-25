/**
 * MailOptin email editor. The email is one object: {content: [blocks], design: {}, settings: {}}.
 * Forms are drawn by PHP; this fills them, keeps the object up to date, asks the server to draw the
 * email after each change (no reload), saves, keeps a draft in this browser, and undoes.
 */
(function ($) {
    'use strict';

    var cfg = window.moEmailEditor || {};
    var i18n = cfg.i18n || {};
    var state = objects(clone(cfg.state));
    var version = cfg.version;
    var savedJson = JSON.stringify(state);
    var undoStack = [], redoStack = [], editing = false;
    var openId = null, previewTimer = null, previewSeq = 0, previewXhr = null, draftTimer = null, saving = false, filling = false;
    var draftKey = 'mo-email-editor-draft-' + cfg.id;

    function clone(o) { return JSON.parse(JSON.stringify(o)); }
    // Settings saved empty come from PHP as [], a list: a setting put in one wouldn't be saved.
    function objects(st) {
        (function walk(list) {
            (list || []).forEach(function (b) {
                if (b && !$.isPlainObject(b.settings)) b.settings = $.extend({}, b.settings);
                if (b && b.columns) b.columns.forEach(walk);
            });
        })(st && st.content);
        return st;
    }
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

    // A structural change (blocks added, moved, removed, columns changed) redraws the list; typing
    // only updates the open block's summary, not every row and its drag-and-drop on each key.
    function changed(structural) {
        status();
        if (structural) renderList();
        else refreshSummary();
        updateBlockHead();
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
        // Parsed inert (no images loaded, no handlers run), for its words only.
        return (new DOMParser().parseFromString(String(text), 'text/html').body.textContent || '').slice(0, 60);
    }

    function refreshSummary() {
        var block = openId ? blockById(openId) : null;
        if (!block) return;
        $('#mo-ee-blocks li.mo-ee-item').filter(function () { return $(this).attr('data-id') === openId; })
            .children('.mo-ee-item-row').find('.mo-ee-item-open > span').text(summary(block));
    }

    function itemHtml(block) {
        var meta = cfg.blocks[block.type] || {title: block.type};
        var $li = $('<li class="mo-ee-item">').attr('data-id', block.id).toggleClass('is-open', block.id === openId).toggleClass('is-warn', !!warnings[block.id]);
        if (warnings[block.id]) $li.attr('title', warnings[block.id]);
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

    // "+" between elements: adds one at that place (a menu of the elements this email offers).
    function insertHtml(index) {
        var $li = $('<li class="mo-ee-insert">').attr('data-index', index);
        var $sel = $('<select class="mo-ee-insert-add">').attr('aria-label', i18n.addHere);
        $sel.append($('<option value="">').text('+'));
        Object.keys(cfg.blocks).forEach(function (type) { $sel.append($('<option>').val(type).text(cfg.blocks[type].title)); });
        return $li.append($sel);
    }

    function renderList() {
        var $list = $('#mo-ee-blocks').empty();
        state.content.forEach(function (block, i) { $list.append(insertHtml(i)).append(itemHtml(block)); });
        if (state.content.length) $list.append(insertHtml(state.content.length));
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
                items: '> li.mo-ee-item',
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
                var b = byId[$(this).attr('data-id')];
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

    // Each block's defaults by template: the page has the saved template's; another template's
    // come with its first preview (its colours and fonts for new blocks and "Reset").
    var blockDefaults = {};
    blockDefaults[(cfg.state || {}).template] = {};
    Object.keys(cfg.blocks || {}).forEach(function (type) { blockDefaults[(cfg.state || {}).template][type] = cfg.blocks[type].defaults || {}; });

    function defaultsOf(type) {
        var by = blockDefaults[state.template];
        return (by && by[type]) || (cfg.blocks[type] || {}).defaults || {};
    }

    // A block saved before one of its fields existed sends with that field's default: show it so.
    function withDefaults(block) {
        return $.extend({}, clone(defaultsOf(block.type)), block.settings || {});
    }

    function newBlock(type, settings) {
        var meta = cfg.blocks[type];
        if (!meta) return null;
        var block = {id: uid(), type: type, settings: $.extend({}, clone(defaultsOf(type)), settings || {})};
        if ('columns' === type) { block.columns = []; fitColumns(block); }
        return block;
    }

    // $column: [columns block id, column index] to add inside a column. $index: where in the email.
    function addBlock(type, column, index) {
        if (column && 'columns' === type) return;
        var block = newBlock(type);
        if (!block) return;
        var parent = column ? blockById(column[0]) : null, list = parent && parent.columns ? parent.columns[column[1]] : null;
        if (column && !list) return;
        beginEdit(true);
        if (column) {
            if (typeof index === 'number' && index >= 0 && index <= list.length) list.splice(index, 0, block);
            else list.push(block);
        } else if (typeof index === 'number' && index >= 0 && index <= state.content.length) {
            state.content.splice(index, 0, block);
        } else {
            state.content.push(block);
        }
        changed(true);
        openBlock(block.id);
    }

    // Ready-made columns: an image beside text.
    function addPreset(name, index) {
        var cols = newBlock('columns', {columns_layout: 'image-text' === name ? '33-67' : '67-33'});
        var image = newBlock('image', {image_width: '170'}), text = newBlock('text');
        if (!cols || !image || !text) return;
        cols.columns = 'image-text' === name ? [[image], [text]] : [[text], [image]];
        beginEdit(true);
        if (typeof index === 'number' && index >= 0 && index <= state.content.length) state.content.splice(index, 0, cols);
        else state.content.push(cols);
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
        refreshControls($scope);
    }

    /* ---------------------------------------------------------------- the open block's form */

    // Where the open block sits, for its header: "Block 2 of 5", or "In Columns · column 2".
    function whereIs(id) {
        var at = locate(id), where = '';
        if (!at) return '';
        if (at.list === state.content) return (i18n.blockOf || 'Block %1$s of %2$s').replace('%1$s', at.index + 1).replace('%2$s', at.list.length);
        state.content.forEach(function (b) {
            (b.columns || []).forEach(function (col, c) { if (col === at.list) where = (i18n.inColumn || 'In Columns · column %s').replace('%s', c + 1); });
        });
        return where;
    }

    function updateBlockHead() {
        var at = openId ? locate(openId) : null;
        if (!at) return;
        $('.mo-ee-block-where').text(whereIs(openId));
        $('.mo-ee-block-up').prop('disabled', 0 === at.index);
        $('.mo-ee-block-down').prop('disabled', at.index === at.list.length - 1);
    }

    function moveOpen(by) {
        var at = openId ? locate(openId) : null, to = at ? at.index + by : -1;
        if (!at || to < 0 || to >= at.list.length) return;
        beginEdit(true);
        at.list.splice(to, 0, at.list.splice(at.index, 1)[0]);
        changed(true);
        // At the top or bottom the pressed button is disabled: keep the keyboard in the header.
        var $btn = $(by < 0 ? '.mo-ee-block-up' : '.mo-ee-block-down');
        if ($btn.prop('disabled')) {
            var $other = $(by < 0 ? '.mo-ee-block-down' : '.mo-ee-block-up');
            ($other.prop('disabled') ? $('.mo-ee-block-done') : $other).trigger('focus');
        }
    }

    // After a block is deleted, the keyboard goes to the block now in its place (or the one before),
    // else the Columns block it was in, else the blocks to add. $at: where it was (locate()).
    function focusAfterRemove(at) {
        var next = at.list[at.index] || at.list[at.index - 1], parent = null;
        if (!next && at.list !== state.content) {
            state.content.forEach(function (b) { if ((b.columns || []).indexOf(at.list) !== -1) parent = b; });
        }
        var to = next || parent;
        var $to = to ? $('#mo-ee-blocks li.mo-ee-item').filter(function () { return $(this).attr('data-id') === to.id; }).children('.mo-ee-item-row').find('.mo-ee-item-open') : $();
        ($to.length ? $to : $('.mo-ee-tile').first()).trigger('focus');
    }

    // A rule from a field's show_if: one of the values, or (with "!") none of them.
    function allows(allowed, value) {
        allowed = (allowed || []).map(String);
        var not = allowed.filter(function (a) { return '!' === a.charAt(0); }).map(function (a) { return a.slice(1); });
        var yes = allowed.filter(function (a) { return '!' !== a.charAt(0); });
        return not.indexOf(value) === -1 && (!yes.length || yes.indexOf(value) !== -1);
    }

    // The friendlier controls follow their inputs: slider values, the pressed button, the image
    // preview, and fields that only apply to some choices.
    function refreshControls($scope) {
        var block = openId ? blockById(openId) : null, values = block ? withDefaults(block) : null;
        $scope.find('.mo-ee-range').each(function () {
            // A slider can't show "not set" (it sits mid-way): say the template decides instead.
            var $o = $(this).find('output'), $in = $(this).find('input'), raw = values ? values[$in.data('name')] : $in.val();
            var unset = undefined === raw || null === raw || '' === raw;
            $(this).toggleClass('is-unset', unset);
            $o.text(unset ? (i18n.auto || 'Auto') : $in.val() + ($o.data('unit') || ''));
        });
        $scope.find('.mo-ee-seg').each(function () {
            var v = String($(this).prev('.mo-ee-seg-source').val());
            $(this).find('.mo-ee-seg-btn').each(function () {
                var on = String($(this).data('value')) === v;
                $(this).toggleClass('is-on', on).attr('aria-pressed', on ? 'true' : 'false');
            });
        });
        $scope.find('.mo-ee-image').each(function () {
            var url = $.trim(String($(this).find('.mo-ee-input').val() || '')), ok = /^(https?:)?\/\//i.test(url) || /^\/[^\/]/.test(url);
            $(this).toggleClass('is-empty', !ok);
            // Typed URLs load once typing stops, not a request per key.
            var $img = $(this).find('.mo-ee-image-preview img'), src = ok ? url : '';
            clearTimeout($img.data('moTimer'));
            if ($img.attr('src') !== src) $img.data('moTimer', setTimeout(function () { $img.attr('src', src); }, src && $(this).find('.mo-ee-input').is(':focus') ? 500 : 0));
            $(this).find('.mo-ee-pick-image-btn').text(ok ? (i18n.replaceImage || 'Replace') : (i18n.chooseImage || 'Choose image'));
        });
        if (!values) return;
        $scope.find('[data-show-if]').each(function () {
            var rules = $(this).data('showIf') || {}, show = true;
            $.each(rules, function (name, allowed) {
                if (!allows(allowed, String(values[name] === undefined || values[name] === null ? '' : values[name]))) show = false;
            });
            $(this).prop('hidden', !show);
        });
    }

    function fillFields($scope, obj) {
        $scope.find('.mo-ee-input').each(function () {
            var $input = $(this), v = getValue(obj, $input);

            if ($input.hasClass('mo-ee-search-value')) return searchFill($input, v);

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

    /* ---------------------------------------------------------------- searched lists */

    // Posts and products are searched for, not listed: the chosen ones live in a hidden <select
    // multiple> (read and written like any field), shown as chips with a search box to add more.

    var titles = {};

    function searchRequest(data) {
        return $.post(cfg.ajax, $.extend({action: 'mo_email_editor_search', id: cfg.id, nonce: cfg.nonce}, data));
    }

    function searchKind($box) {
        var kind = $box.data('search');
        var type = 'products' === kind ? 'product' : ($box.closest('.mo-ee-block-form, form, [data-group]').find('[data-name="posts_post_type"]').val() || 'post');
        return {kind: kind, post_type: type};
    }

    function searchChips($select) {
        var $box = $select.closest('.mo-ee-search'), $list = $box.find('.mo-ee-search-chosen').empty();
        $select.find('option').each(function () {
            var text = $(this).text();
            $('<li class="mo-ee-search-chip"></li>').text(text).append(
                $('<button type="button" class="mo-ee-search-remove">&times;</button>').attr('aria-label', (i18n.removeItem || 'Remove %s').replace('%s', function () { return text; })).data('id', this.value)
            ).appendTo($list);
        });
    }

    function searchAdd($select, id, text) {
        id = String(id);
        if ($select.find('option').filter(function () { return this.value === id; }).length) return;
        $('<option selected></option>').val(id).text(text || titles[id] || '#' + id).appendTo($select);
    }

    // The saved ids, with their titles (asked of the server for ones not seen yet).
    function searchFill($select, v) {
        var ids = $.isArray(v) ? v.map(String) : [];
        $select.empty();
        ids.forEach(function (id) { searchAdd($select, id); });
        searchChips($select);

        var unknown = ids.filter(function (id) { return !titles[id]; });
        if (!unknown.length) return;

        searchRequest($.extend(searchKind($select.closest('.mo-ee-search')), {ids: unknown.join(',')})).done(function (res) {
            if (!res || !res.success) return;
            // Ids the server didn't find (no longer published) keep their number: don't ask again.
            unknown.forEach(function (id) { titles[id] = titles[id] || '#' + id; });
            res.data.forEach(function (item) {
                titles[item.id] = item.text;
                $select.find('option').filter(function () { return this.value === item.id; }).text(item.text);
            });
            searchChips($select);
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

        // Above the classic editor as a whole, when the field is one; inside a block's link field,
        // at its end.
        var $wrap = $field.closest('.wp-editor-wrap');
        if ($wrap.length) $wrap.before($menu);
        else if ($field.is('input') && $field.closest('.mo-ee-block-form').length) {
            $field.wrap('<div class="mo-ee-inputgroup"></div>').after($menu);
            $menu.addClass('mo-ee-ph-inline').find('summary').removeClass('button button-small').text('{ } ' + (i18n.placeholder || 'Placeholder'));
        } else $field.before($menu);
    }

    // {{key|fallback}} in a text block's HTML, as chips for the classic editor (text nodes only:
    // a placeholder in a link's href stays as it is) and back.
    var phLabels = null;
    function phLabel(key) {
        if (!phLabels) {
            phLabels = {};
            (cfg.placeholders || []).forEach(function (g) { (g.items || []).forEach(function (it) { phLabels[it.key] = it.label; }); });
        }
        return phLabels[key] || key;
    }

    function chipsIn(html) {
        if (html.indexOf('{{') === -1) return html;
        var doc = new DOMParser().parseFromString('<!doctype html><body>' + html, 'text/html'), re = /\{\{\s*([a-z][a-z0-9_.]*)\s*(?:\|([^}]*))?\}\}/gi;
        var walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT), nodes = [], n;
        while ((n = walker.nextNode())) if (n.nodeValue.indexOf('{{') !== -1 && !(n.parentNode.closest && n.parentNode.closest('.mo-ph'))) nodes.push(n);
        if (!nodes.length) return html;
        nodes.forEach(function (node) {
            var text = node.nodeValue, frag = doc.createDocumentFragment(), last = 0, m;
            re.lastIndex = 0;
            while ((m = re.exec(text))) {
                if (m.index > last) frag.appendChild(doc.createTextNode(text.slice(last, m.index)));
                var chip = doc.createElement('span');
                chip.className = 'mo-ph';
                chip.setAttribute('contenteditable', 'false');
                chip.setAttribute('data-mo-ph', m[0]);
                chip.setAttribute('title', m[0]);
                chip.textContent = phLabel(m[1]) + (m[2] && m[2].trim() ? ' · ' + m[2].trim() : '');
                frag.appendChild(chip);
                last = re.lastIndex;
            }
            if (last < text.length) frag.appendChild(doc.createTextNode(text.slice(last)));
            node.parentNode.replaceChild(frag, node);
        });
        return doc.body.innerHTML;
    }

    function chipsOut(html) {
        if (html.indexOf('mo-ph') === -1) return html;
        return html.replace(/<span\b[^>]*\bdata-mo-ph="([^"]*)"[^>]*>[\s\S]*?<\/span>/g, function (all, raw) {
            var t = document.createElement('textarea');
            t.innerHTML = raw;
            // Back to text in HTML: the fallback's own &, < and > stay escaped.
            return t.value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        });
    }

    function insertAt($field, text) {
        var ed = $field[0].id && window.tinymce ? tinymce.get($field[0].id) : null;

        // The classic editor's field is HTML (in either tab): a fallback's own <, > and & go in as text.
        if ($field.hasClass('mo-ee-richtext')) text = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

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
        // The open block may not be in the state now shown (another version loaded on a conflict).
        if (openId && !blockById(openId)) closeBlock();
        if (cfg.code) {
            var html = state.code || '';
            if (codeMirror && codeMirror.getValue() !== html) { filling = true; codeMirror.setValue(html); filling = false; }
            else if (!codeMirror) $('#mo-ee-code').val(html);
        }
        $('.mo-ee-template-input').each(function () { this.checked = this.value === state.template; });
        fill($('[data-group="design"]'), state.design);
        fill($('[data-group="settings"]'), state.settings);
        if (openId) fill($('.mo-ee-block-form'), withDefaults(blockById(openId)));
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
        var layout = 'columns_layout' === name && open && open.columns && obj === open.settings;
        if (layout) fitColumns(open);
        changed(layout);
        if (open && obj === open.settings) refreshControls($('.mo-ee-block-form'));
    }

    function openBlock(id) {
        var block = blockById(id);
        if (!block) return;

        closeBlock();
        openId = id;

        var $form = $('.mo-ee-block-form').html($('#mo-ee-form-' + block.type).html() || '');
        $('.mo-ee-block-title').text((cfg.blocks[block.type] || {}).title || block.type);
        // The icon is the server's own markup (Icons::html), not anything typed in.
        $('.mo-ee-block-icon').html((cfg.blocks[block.type] || {}).iconHtml || '');
        showPane('block');
        updateBlockHead();

        fill($form, withDefaults(block));
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
                    content_style: '.mo-ph{display:inline-block;padding:0 6px;border:1px solid #c5d9ed;border-radius:4px;background:#f0f6fc;color:#135e96;font:13px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;cursor:default;white-space:nowrap}',
                    setup: function (ed) {
                        ed.on('input change keyup', function () { if (filling) return; ed.save(); onField($ta); });
                        // Placeholders show as chips here; what's saved stays {{key|fallback}}.
                        ed.on('BeforeSetContent', function (e) { if (e.content) e.content = chipsIn(e.content); });
                        ed.on('PostProcess', function (e) { if (e.get && e.content) e.content = chipsOut(e.content); });
                    }
                },
                quicktags: true, mediaButtons: true
            });
        });

        // Text and links can hold placeholders.
        // Links take a placeholder; "Remove read more link" (a checkbox) doesn't.
        $form.find('.mo-ee-richtext, [data-name$="_link"]:not(:checkbox)').each(function () { placeholderMenu($(this)); });

        renderList();
        outlineOpen();
    }

    function closeBlock() {
        if (!openId) return;

        $('.mo-ee-block-form .mo-ee-richtext').each(function () {
            if (window.wp && wp.editor && this.id) wp.editor.remove(this.id);
        });

        $('.mo-ee-block-form').empty();
        openId = null;
        outlineOpen();
        showPane('content');
        renderList();
    }

    function showPane(name) {
        $('.mo-ee-pane').removeClass('is-on').filter('[data-pane="' + name + '"]').addClass('is-on');
        // A block's own header stands in for the page's tabs while it's open.
        $('body').toggleClass('mo-ee-block-open', 'block' === name);
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

        // The design and template as sent: the switched colours that come back are for these.
        var sentDesign = JSON.stringify([state.template, state.design]), sentTemplate = state.template;
        previewXhr = $.post(cfg.ajax, {action: 'mo_email_editor_preview', id: cfg.id, nonce: cfg.nonce, state: JSON.stringify(state), defaults: cfg.code || blockDefaults[sentTemplate] ? 0 : 1});
        previewXhr.done(function (res) {
            if (res && res.success && res.data.blocks) blockDefaults[sentTemplate] = res.data.blocks;
            // A newer change already asked for a newer preview.
            if (seq !== previewSeq || !res || !res.success) return;

            // Another template: its colours where the old template's defaults were. Not over a
            // colour changed (or an undo) since this was asked for: the next preview switches again.
            if (res.data.design && JSON.stringify([state.template, state.design]) === sentDesign) {
                state.design = res.data.design;
                state.design_template = res.data.design_template;
                fill($('[data-group="design"]'), state.design);
            }

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

            frameClicks(doc);
            showChecks(res.data.checks || []);
        }).always(function () {
            if (seq === previewSeq) {
                previewXhr = null;
                $('#mo-ee-updating').prop('hidden', true);
            }
        });
    }

    // The open element outlined in the preview.
    function outlineOpen() {
        var frame = document.getElementById('mo-ee-frame'), doc = frame && frame.contentDocument;
        if (!doc) return;
        $(doc).find('.mo-ee-on').removeClass('mo-ee-on');
        if (openId) $(doc).find('[data-id="' + openId + '"]').addClass('mo-ee-on');
    }

    // A click on an element in the preview opens its settings; links there go nowhere.
    function frameClicks(doc) {
        var style = doc.createElement('style');
        style.textContent = '[data-id]{cursor:pointer}[data-id]:hover{outline:2px dashed #2271b1;outline-offset:2px}[data-id].mo-ee-on{outline:2px solid #2271b1;outline-offset:2px}html.mo-ee-dragging [data-id]:hover{outline:none}';
        (doc.head || doc.documentElement).appendChild(style);

        if (openId) {
            var on = doc.querySelector('[data-id="' + openId + '"]');
            if (on) on.classList.add('mo-ee-on');
        }

        doc.addEventListener('click', function (e) {
            e.preventDefault();
            var el = e.target.closest ? e.target.closest('[data-id]') : null;
            var id = el ? el.getAttribute('data-id') : '';
            if (id && blockById(id)) openBlock(id);
        });

        frameDrops(doc);
    }

    /* ---------------------------------------------------------------- drag a block into the preview */

    // What's being dragged from the palette: {type} or {preset}, else null (so drags of anything
    // else, such as a file or text, are left alone).
    var dragging = null;

    function dragLabel() {
        return dragging ? dragging.label : '';
    }

    // Where a drop at (x, y) in the preview goes: {column: [columnsId, i] | null, index, line: {top, left, width}}.
    function dropTarget(doc, x, y) {
        var win = doc.defaultView, intoColumn = dragging && !dragging.preset && 'columns' !== dragging.type;
        var top = function (el) { return el.getBoundingClientRect().top; };

        // Inside a column of a Columns block (a column never holds columns).
        var el = doc.elementFromPoint(x, y);
        var wrap = el && el.closest ? el.closest('[data-id]') : null;
        while (intoColumn && wrap) {
            var parent = blockById(wrap.getAttribute('data-id'));
            if (parent && 'columns' === parent.type) {
                // The outermost MJML columns in the block; "don't stack" layouts wrap them in one
                // full-width group, so look inside it.
                var outermost = function (root) {
                    return Array.prototype.filter.call(root.querySelectorAll('[class*="mj-column-"]'), function (d) {
                        var up = d.parentElement && d.parentElement.closest('[class*="mj-column-"]');
                        return !up || !root.contains(up) || up === root;
                    });
                };
                var cols = outermost(wrap), want = (parent.columns || []).length;
                if (1 === cols.length && want > 1) cols = outermost(cols[0]).filter(function (d) { return d !== cols[0]; });
                // The column under the pointer: by its box, else (an empty column has no height) by x.
                var c = -1, byX = -1;
                cols.forEach(function (d, i) {
                    var r = d.getBoundingClientRect();
                    if (x >= r.left && x <= r.right) {
                        if (y >= r.top && y <= r.bottom) c = i;
                        else if (byX < 0) byX = i;
                    }
                });
                if (c < 0) c = byX;
                if (c >= 0 && parent.columns && parent.columns[c]) {
                    var colRect = cols[c].getBoundingClientRect(), kids = parent.columns[c], idx = kids.length, lineY = colRect.bottom;
                    var tops = kids.map(function (k) { var e = doc.querySelector('[data-id="' + k.id + '"]'); return e ? top(e) : null; });
                    for (var i = 0; i < kids.length; i++) {
                        if (null === tops[i]) continue;
                        var next = i + 1 < kids.length && null !== tops[i + 1] ? tops[i + 1] : colRect.bottom;
                        if (y < (tops[i] + next) / 2) { idx = i; lineY = tops[i]; break; }
                    }
                    if (!kids.length) lineY = colRect.height > 8 ? colRect.top + colRect.height / 2 : Math.min(y, wrap.getBoundingClientRect().bottom - 4);
                    return {column: [parent.id, c], index: idx, line: {top: lineY + win.scrollY, left: colRect.left + win.scrollX, width: colRect.width}};
                }
            }
            wrap = wrap.parentElement ? wrap.parentElement.closest('[data-id]') : null;
        }

        // Between the email's top-level blocks.
        var found = [];
        state.content.forEach(function (b, i) { var e = doc.querySelector('[data-id="' + b.id + '"]'); if (e) found.push({i: i, el: e}); });
        if (!found.length) {
            var body = doc.body.getBoundingClientRect();
            return {column: null, index: state.content.length, line: {top: y + win.scrollY, left: body.left + win.scrollX + 20, width: Math.max(80, body.width - 40)}};
        }
        // The last block may be several rows (Posts, Products) with its id on the first: its end is
        // the last of the rows that follow it, up to the next block's.
        var last = found[found.length - 1], lastRect = last.el.getBoundingClientRect(), end = lastRect.bottom;
        var row = last.el.closest('tr');
        if (row && row.contains(last.el)) {
            for (var r = row.nextElementSibling; r && !r.querySelector('[data-id]') && !r.hasAttribute('data-id'); r = r.nextElementSibling) end = Math.max(end, r.getBoundingClientRect().bottom);
        }
        if (end - lastRect.top < 40) end = lastRect.top + 40;
        var index = state.content.length, at = end, ref = lastRect;
        for (var j = 0; j < found.length; j++) {
            var t = top(found[j].el), n = j + 1 < found.length ? top(found[j + 1].el) : end;
            if (y < (t + n) / 2) { index = found[j].i; at = t; ref = found[j].el.getBoundingClientRect(); break; }
        }
        return {column: null, index: index, line: {top: at + win.scrollY, left: ref.left + win.scrollX, width: ref.width}};
    }

    function showDropLine(doc, target) {
        var line = doc.getElementById('mo-ee-dropline');
        if (!line) {
            line = doc.createElement('div');
            line.id = 'mo-ee-dropline';
            line.setAttribute('aria-hidden', 'true');
            line.style.cssText = 'position:absolute;z-index:2147483647;height:4px;margin-top:-2px;background:#2271b1;border-radius:2px;pointer-events:none;box-shadow:0 0 0 2px rgba(255,255,255,.8)';
            var tag = doc.createElement('span');
            tag.style.cssText = 'position:absolute;left:50%;top:-11px;transform:translateX(-50%);white-space:nowrap;background:#2271b1;color:#fff;font:600 11px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:5px 9px;border-radius:99px';
            line.appendChild(tag);
            doc.body.appendChild(line);
        }
        line.style.top = Math.round(target.line.top) + 'px';
        line.style.left = Math.round(target.line.left) + 'px';
        line.style.width = Math.round(target.line.width) + 'px';
        var text = (i18n.dropHere || 'Drop %s here').replace('%s', function () { return dragLabel(); });
        if (line.firstChild.textContent !== text) line.firstChild.textContent = text;
    }

    function hideDropLine(doc) {
        if (doc && doc.documentElement) doc.documentElement.classList.remove('mo-ee-dragging');
        var line = doc && doc.getElementById('mo-ee-dropline');
        if (line) line.parentNode.removeChild(line);
    }

    function frameDrops(doc) {
        doc.addEventListener('dragover', function (e) {
            if (!dragging) return;
            e.preventDefault();
            if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
            // The drop line shows where it lands; a hover outline would only add noise.
            doc.documentElement.classList.add('mo-ee-dragging');
            showDropLine(doc, dropTarget(doc, e.clientX, e.clientY));
        });
        doc.addEventListener('dragleave', function (e) {
            // Left the preview (not just moved between its elements).
            if (!e.relatedTarget) hideDropLine(doc);
        });
        doc.addEventListener('drop', function (e) {
            if (!dragging) return;
            e.preventDefault();
            var target = dropTarget(doc, e.clientX, e.clientY), what = dragging;
            hideDropLine(doc);
            dragging = null;
            if (what.preset) addPreset(what.preset, target.index);
            else addBlock(what.type, target.column, target.index);
        });
    }

    /* ---------------------------------------------------------------- checks before sending */

    var warnings = {};

    function showChecks(checks) {
        var $box = $('#mo-ee-checks'), $list = $box.find('.mo-ee-checks-list').empty();
        var count = 0;
        warnings = {};

        checks.forEach(function (check) {
            var $li = $('<li>').toggleClass('is-ok', !!check.ok).toggleClass('is-warn', !check.ok);
            $('<span class="mo-ee-check-mark" aria-hidden="true">').text(check.ok ? '✓' : '!').appendTo($li);
            $('<span class="mo-ee-check-text">').text(check.text).appendTo($li);
            if (!check.ok) {
                count++;
                if (check.block && blockById(check.block)) {
                    warnings[check.block] = check.text;
                    $('<button type="button" class="button-link mo-ee-check-show">').text(i18n.show).attr('data-id', check.block).appendTo($li);
                }
            }
            $list.append($li);
        });

        $box.prop('hidden', !checks.length).toggleClass('has-warnings', count > 0);
        $box.find('summary').text(count ? i18n.toCheck.replace('%d', count) : '✓ ' + i18n.allGood);

        // Marked in place: the list isn't rebuilt under a drag, an open menu or the keyboard focus.
        $('#mo-ee-blocks li.mo-ee-item').each(function () {
            var text = warnings[$(this).attr('data-id')];
            $(this).toggleClass('is-warn', !!text).attr('title', text || null);
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
                // A new template saved: its own Design fields (Market's offer strip) need the page again.
                // Saved: the reload mustn't ask "Leave without saving?" (unless typed in since).
                if (res.data.reload) { savedJson = posted; try { localStorage.removeItem(draftKey); } catch (e) {} window.location.reload(); return result.resolve(res); }
                savedJson = JSON.stringify(objects(res.data.state));
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
                        state = objects(data.state);
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
            state = objects(draft.state);
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
            $form.find('.mo-ee-subtab').removeClass('is-on').attr('aria-selected', 'false').filter(this).addClass('is-on').attr('aria-selected', 'true');
            $form.find('.mo-ee-subpanel').prop('hidden', true).filter('[data-tab="' + tab + '"]').prop('hidden', false);
        });

        // Tiles can be dragged into the preview; a click still adds at the end.
        $('.mo-ee-tile').attr('draggable', 'true').on('dragstart', function (e) {
            var dt = e.originalEvent && e.originalEvent.dataTransfer;
            dragging = $(this).data('preset') ? {preset: String($(this).data('preset'))} : {type: String($(this).data('type'))};
            dragging.label = $.trim($(this).find('.mo-ee-tile-label').text());
            // A type of our own: Firefox needs some data to start a drag, and fields don't take it as text.
            if (dt) { dt.effectAllowed = 'copy'; dt.setData('application/x-mailoptin-block', dragging.preset || dragging.type); }
            $('body').addClass('mo-ee-is-dragging');
        }).on('dragend', function () {
            dragging = null;
            $('body').removeClass('mo-ee-is-dragging');
            var frame = document.getElementById('mo-ee-frame');
            hideDropLine(frame && frame.contentDocument);
        });

        $('.mo-ee-add-btn').on('click', function () {
            if ($(this).data('preset')) addPreset($(this).data('preset'));
            else addBlock($(this).data('type'));
        });
        $('#mo-ee-blocks')
            .on('click', '.mo-ee-item-open', function () { openBlock($(this).closest('li').attr('data-id')); })
            .on('click', '.mo-ee-dup', function () {
                var at = locate($(this).closest('li').attr('data-id'));
                if (!at) return;
                beginEdit(true);
                at.list.splice(at.index + 1, 0, withNewIds(clone(at.block)));
                changed(true);
            })
            .on('click', '.mo-ee-del', function () {
                var id = $(this).closest('li').attr('data-id');
                if (!window.confirm(i18n.delete)) return;
                var at = locate(id);
                if (!at) return;
                beginEdit(true);
                if (id === openId || (openId && at.block.columns && JSON.stringify(at.block.columns).indexOf('"' + openId + '"') !== -1)) closeBlock();
                at.list.splice(at.index, 1);
                changed(true);
                focusAfterRemove(at);
            });

        $('.mo-ee-block-done').on('click', closeBlock);
        $('.mo-ee-block-up').on('click', function () { moveOpen(-1); });
        $('.mo-ee-block-down').on('click', function () { moveOpen(1); });
        $('.mo-ee-block-dup').on('click', function () {
            var at = openId ? locate(openId) : null;
            if (!at) return;
            beginEdit(true);
            at.list.splice(at.index + 1, 0, withNewIds(clone(at.block)));
            changed(true);
        });
        $('.mo-ee-block-del').on('click', function () {
            var at = openId ? locate(openId) : null;
            if (!at || !window.confirm(i18n.delete)) return;
            beginEdit(true);
            closeBlock();
            at.list.splice(at.index, 1);
            changed(true);
            focusAfterRemove(at);
        });

        // A row of buttons for a short list: the hidden select is the field.
        $('.mo-ee-block-form').on('click', '.mo-ee-seg-btn', function () {
            $(this).closest('.mo-ee-seg').prev('.mo-ee-seg-source').val(String($(this).data('value'))).trigger('change');
        }).on('click', '.mo-ee-pick-image-btn', function (e) {
            e.preventDefault();
            var $input = $(this).closest('.mo-ee-image').find('.mo-ee-input'), frame = wp.media({multiple: false, library: {type: 'image'}});
            frame.on('select', function () { $input.val(frame.state().get('selection').first().toJSON().url).trigger('change'); });
            frame.open();
        }).on('click', '.mo-ee-image-remove', function () {
            $(this).closest('.mo-ee-image').find('.mo-ee-input').val('').trigger('change');
        }).on('click', '.mo-ee-reset-style', function () {
            // Back to the template's look: every Style setting to the block's default for this email.
            var block = openId ? blockById(openId) : null, defaults = block ? defaultsOf(block.type) : null;
            if (!block) return;
            var names = {};
            $('.mo-ee-block-form .mo-ee-subpanel[data-tab="style"] .mo-ee-input').each(function () { names[$(this).data('name')] = true; });
            beginEdit(true);
            Object.keys(names).forEach(function (n) {
                if (defaults[n] === undefined) delete block.settings[n];
                else block.settings[n] = clone(defaults[n]);
            });
            // A layout put back must keep the columns in step, as choosing one does.
            if (block.columns) fitColumns(block);
            fill($('.mo-ee-block-form'), withDefaults(block));
            changed(!!block.columns);
        });

        // The "+" and "Add to this column" menus act on a click or Enter, not on arrow keys (some
        // browsers fire change for each arrow press on a closed select).
        var pickMenu = function ($sel) {
            var type = $sel.val();
            $sel.removeData('moKeys');
            if (!type) return;
            if ($sel.hasClass('mo-ee-insert-add')) {
                addBlock(type, null, parseInt($sel.closest('.mo-ee-insert').data('index'), 10));
            } else {
                addBlock(type, [$sel.closest('.mo-ee-cols').closest('li').attr('data-id'), parseInt($sel.data('col'), 10)]);
            }
            $sel.val('');
        };
        $('#mo-ee-blocks')
            .on('keydown', '.mo-ee-insert-add, .mo-ee-col-add', function (e) {
                if ('Enter' === e.key) { e.preventDefault(); pickMenu($(this)); } else if ('Tab' !== e.key) $(this).data('moKeys', true);
            })
            .on('change', '.mo-ee-insert-add, .mo-ee-col-add', function () {
                if (!$(this).data('moKeys')) pickMenu($(this));
            })
            .on('blur', '.mo-ee-insert-add, .mo-ee-col-add', function () { $(this).removeData('moKeys').val(''); });

        $('#mo-ee-checks').on('click', '.mo-ee-check-show', function () {
            $('#mo-ee-checks').prop('open', false);
            showPane('content');
            openBlock($(this).attr('data-id'));
        });

        // The template: the email's frame, drawn around the same content.
        $('#mo-ee').on('change', '.mo-ee-template-input', function () {
            if (!this.checked || this.value === state.template) return;
            beginEdit(true);
            state.template = this.value;
            changed(true);
        });

        $('#mo-ee').on('input change', '.mo-ee-input:not(.mo-ee-color)', function () { onField($(this)); });

        // Searched lists: type to search, pick a result to add it, × to take it out.
        // Only the latest search shows: an older reply arriving late, or one for a box since
        // cleared, is dropped.
        var searchTimer = null, searchSeq = 0;
        $('#mo-ee').on('input', '.mo-ee-search-input', function () {
            var $input = $(this), $box = $input.closest('.mo-ee-search'), $results = $box.find('.mo-ee-search-results'), term = $.trim($input.val());
            var seq = ++searchSeq;
            clearTimeout(searchTimer);
            if (term.length < 2) { $results.empty(); return; }
            searchTimer = setTimeout(function () {
                searchRequest($.extend(searchKind($box), {term: term})).done(function (res) {
                    if (seq !== searchSeq || $.trim($input.val()) !== term) return;
                    $results.empty();
                    if (!res || !res.success) return;
                    if (!res.data.length) $('<li class="mo-ee-search-none"></li>').text(i18n.noResults || '').appendTo($results);
                    res.data.forEach(function (item) {
                        titles[item.id] = item.text;
                        $('<li role="option" tabindex="0" class="mo-ee-search-result"></li>').text(item.text).data('id', item.id).appendTo($results);
                    });
                });
            }, 250);
        });
        $('#mo-ee').on('click keydown', '.mo-ee-search-result', function (e) {
            if ('keydown' === e.type && 'Enter' !== e.key && ' ' !== e.key) return;
            e.preventDefault();
            var $box = $(this).closest('.mo-ee-search'), $select = $box.find('.mo-ee-search-value');
            searchAdd($select, $(this).data('id'), $(this).text());
            searchChips($select);
            clearTimeout(searchTimer); searchSeq++;
            $box.find('.mo-ee-search-results').empty();
            $box.find('.mo-ee-search-input').val('').trigger('focus');
            $select.trigger('change');
        });
        $('#mo-ee').on('click', '.mo-ee-search-remove', function () {
            var id = String($(this).data('id')), $select = $(this).closest('.mo-ee-search').find('.mo-ee-search-value');
            $select.find('option').filter(function () { return this.value === id; }).remove();
            searchChips($select);
            $select.trigger('change');
        });

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
