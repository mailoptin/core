/**
 * Compiles the email blocks' MJML into HTML templates for PHP (src/EmailBlocks/mjml/).
 *
 * Each block is compiled twice, with two sets of marker values (A and B). Every marker, and every
 * value MJML works out from them (an Outlook width = the container less the padding), becomes a
 * __MO_name__ slot that PHP fills in. If the two results still differ, MJML derived something not
 * accounted for here: the build fails rather than ship a template with a frozen number in it.
 *
 * Then, for real sample values (every option of each choice included), the template filled in must
 * be exactly what MJML itself makes of them.
 *
 *   node build.js           write the templates
 *   node build.js --check   fail if the committed templates are not what this produces (CI)
 */
'use strict';

const fs = require('fs');
const path = require('path');
const mjml2html = require('mjml');

const OUT = path.resolve(__dirname, '../../src/EmailBlocks/mjml');
// MJML's own output for the samples: the PHP tests draw the same through the blocks.
const FIXTURES = path.resolve(__dirname, '../../tests/EmailBlocks/fixtures/mjml-samples.json');
const START = '<!--MO-START-->', END = '<!--MO-END-->';

const slot = (name) => `__MO_${name}__`;
const px = (n) => `${n}px`;
const pad = (v) => `${v.pad_t}px ${v.pad_r}px ${v.pad_b}px ${v.pad_l}px`;

// Marker values: odd numbers that appear nowhere in MJML's own output, different in A and B.
const PADDING = {pad_t: [211, 223], pad_r: [227, 229], pad_b: [233, 239], pad_l: [241, 251]};
const CONTAINER = {container: [1577, 1563]};

/** A block that sits in a column of the email (or of a Columns block): its <tr> row. */
function leaf(tag) {
    return (v) => `<mjml><mj-body width="${v.container}px"><mj-section padding="0"><mj-column>
        <mj-raw>${START}</mj-raw>${tag(v)}<mj-raw>${END}</mj-raw>
    </mj-column></mj-section></mj-body></mjml>`;
}

const blocks = {};

// Real values to check the templates against (strings fill __MO_name__ slots as they are).
const SAMPLE_PAD = {pad_t: 10, pad_r: 25, pad_b: 12, pad_l: 20};

blocks.text = {
    samples: [{container: 570, ...SAMPLE_PAD, font_size: 16, font_family: 'Arial, sans-serif', color: '#74787e', content: '<p>Hello there</p>'}],
    vars: Object.assign({}, CONTAINER, PADDING, {font_size: [307, 311]}),
    mjml: leaf((v) => `<mj-text css-class="mo-content-text-color mo-email-builder-element" padding="${pad(v)}" font-family="${slot('font_family')}" font-size="${v.font_size}px" color="${slot('color')}" line-height="1.5">${slot('content')}</mj-text>`),
};

for (const linked of [true, false]) {
    blocks[linked ? 'image-link' : 'image'] = {
        vars: Object.assign({}, CONTAINER, PADDING, {width: [313, 317]}),
        samples: ['left', 'center', 'right'].map((align) => ({container: 570, ...SAMPLE_PAD, width: 300, align, src: 'https://example.com/a.png', alt: 'A', href: 'https://example.com/'})),
        mjml: leaf((v) => `<mj-image css-class="mo-email-builder-element" padding="${pad(v)}" width="${v.width}px" align="${slot('align')}" src="${slot('src')}" alt="${slot('alt')}"${linked ? ` href="${slot('href')}" target="_blank"` : ''}/>`),
    };
}

blocks.button = {
    vars: Object.assign({}, CONTAINER, PADDING, {
        font_size: [331, 337], radius: [347, 349], width: [83, 79],
        in_t: [353, 359], in_r: [367, 373], in_b: [379, 383], in_l: [389, 397],
    }),
    samples: ['left', 'center', 'right'].flatMap((align) => ['normal', 'bold', '600'].map((font_weight) => ({
        container: 275, ...SAMPLE_PAD, font_size: 18, radius: 4, width: 100, in_t: 12, in_r: 24, in_b: 12, in_l: 24,
        align, font_weight, href: 'https://example.com/', background: '#dc4d2f', color: '#ffffff', font_family: 'Arial, sans-serif', text: 'Shop now',
    }))),
    mjml: leaf((v) => `<mj-button css-class="mo-email-builder-element" padding="${pad(v)}" inner-padding="${v.in_t}px ${v.in_r}px ${v.in_b}px ${v.in_l}px" width="${v.width}%" align="${slot('align')}" href="${slot('href')}" background-color="${slot('background')}" color="${slot('color')}" font-family="${slot('font_family')}" font-size="${v.font_size}px" font-weight="${slot('font_weight')}" border-radius="${v.radius}px" line-height="120%">${slot('text')}</mj-button>`),
};

blocks.divider = {
    vars: Object.assign({}, CONTAINER, PADDING, {thickness: [401, 409], width: [71, 67]}),
    // MJML's Outlook width: (container - padding) x width%.
    derived: (v) => ({outlook_width: (v.container - v.pad_l - v.pad_r) * v.width / 100}),
    samples: ['solid', 'dashed', 'dotted', 'double'].map((style) => ({container: 570, ...SAMPLE_PAD, thickness: 2, width: 100, style, color: '#cccccc'})),
    mjml: leaf((v) => `<mj-divider css-class="mo-email-builder-element" padding="${pad(v)}" width="${v.width}%" border-width="${v.thickness}px" border-style="${slot('style')}" border-color="${slot('color')}"/>`),
};

blocks.spacer = {
    vars: Object.assign({}, CONTAINER, PADDING, {height: [419, 421]}),
    samples: [{container: 570, ...SAMPLE_PAD, height: 30}],
    mjml: leaf((v) => `<mj-spacer css-class="mo-email-builder-element" padding="${pad(v)}" height="${v.height}px"/>`),
};

const LAYOUTS = {'50-50': [50, 50], '33-67': [33, 67], '67-33': [67, 33], '33-33-33': [33.33, 33.33, 33.33]};

for (const [layout, widths] of Object.entries(LAYOUTS)) {
    for (const valign of ['top', 'middle']) {
        for (const stack of [true, false]) {
            const columns = (v) => widths.map((w, i) => {
                const left = 0 === i ? 0 : v.half_gap, right = widths.length - 1 === i ? 0 : v.half_gap;
                return `<mj-column width="${w}%" vertical-align="${valign}" padding="0 ${right ? 'R' : 0} 0 ${left ? 'L' : 0}"><mj-raw>${slot('col' + i)}</mj-raw></mj-column>`
                    .replace('R', px(v.half_gap)).replace('L', px(v.half_gap));
            }).join('');

            blocks[`columns-${layout}-${valign}${stack ? '' : '-nostack'}`] = {
                vars: Object.assign({}, CONTAINER, PADDING, {half_gap: [431, 433]}),
                // MJML's Outlook cell for each column: its share of the section, less the section's padding.
                // MJML's Outlook cells: the section less its padding; each column's share of that (in a group,
                // whole pixels).
                derived: (v) => {
                    const inner = v.container - v.pad_l - v.pad_r, d = {inner_width: inner};
                    // Named by share, not position: columns of the same share have the same cell.
                    widths.forEach((w) => {
                        const key = String(w).replace('.', '_');
                        d['w' + key] = inner * w / 100;
                        if (!stack) d['gw' + key] = Math.floor(inner * w / 100);
                    });
                    return d;
                },
                mjml: (v) => `<mjml><mj-body width="${v.container}px"><mj-raw>${START}</mj-raw>
                    <mj-section css-class="mo-email-builder-element" padding="${pad(v)}" background-color="${slot('background')}">
                        ${stack ? columns(v) : `<mj-group>${columns(v)}</mj-group>`}
                    </mj-section>
                <mj-raw>${END}</mj-raw></mj-body></mjml>`,
                css: true,
                samples: [{container: 570, ...SAMPLE_PAD, half_gap: 10, background: '#f5f5f5', ...Object.fromEntries(widths.map((w, i) => ['col' + i, `<tr><td>Column ${i}</td></tr>`]))}],
            };
        }
    }
}

function render(mjml, strict) {
    const html = mjml2html(mjml, {validationLevel: strict ? 'strict' : 'skip', minify: false, keepComments: true, beautify: false}).html;
    const from = html.indexOf(START), to = html.indexOf(END);
    if (from < 0 || to < 0) throw new Error('No block markers in the output');

    return {html, body: html.slice(from + START.length, to).trim()};
}

// MJML's own output for real values, against the template filled in with them.
const fixtures = {};

function verify(name, block, template) {
    fixtures[name] = [];
    for (const sample of block.samples || []) {
        // Slots MJML only copies are given as their slot: the real value goes in both ways.
        const v = new Proxy(sample, {get: (t, k) => k in t ? t[k] : undefined});
        let mjml = block.mjml(v);
        for (const [k, value] of Object.entries(sample)) mjml = mjml.split(slot(k)).join(String(value));
        const expected = render(mjml, true).body;
        fixtures[name].push({sample, html: expected});

        const values = Object.assign({}, sample, block.derived ? block.derived(sample) : {});
        let filled = template;
        for (const [k, value] of Object.entries(values)) filled = filled.split(slot(k)).join(String(value));

        if (filled !== expected) {
            let i = 0;
            while (filled[i] === expected[i]) i++;
            throw new Error(`${name}: the template filled with ${JSON.stringify(sample).slice(0, 120)}... is not MJML's output, near:\n  template: ${filled.slice(Math.max(0, i - 60), i + 60)}\n  mjml:     ${expected.slice(Math.max(0, i - 60), i + 60)}`);
        }
        if (/__MO_\w+__/.test(filled)) throw new Error(`${name}: an unfilled slot: ${filled.match(/__MO_\w+__/)[0]}`);
    }
}

function compile(block, side) {
    const v = {};
    for (const [name, pair] of Object.entries(block.vars)) v[name] = pair[side];

    const {html, body: raw} = render(block.mjml(v), false);
    let body = raw;

    // The longest values first, so 3333 is not taken for 333.
    const values = Object.assign({}, v, block.derived ? block.derived(v) : {});
    const entries = Object.entries(values).sort((a, b) => String(b[1]).length - String(a[1]).length);
    for (const [name, value] of entries) {
        const re = new RegExp('(?<![0-9.])' + String(value).replace('.', '\\.') + '(?![0-9])', 'g');
        body = body.replace(re, slot(name));
    }

    // The column widths MJML sets for screens wider than a phone (and for Thunderbird): its page
    // reset (body, p margins...) is left out, as the template has its own.
    const css = block.css ? [...html.matchAll(/\.mj-column-per-[\w-]+ \{[^}]*\}/g)].map((m) => m[0]) : [];
    if (block.css && !/\.mj-outlook-group-fix \{ width:100% !important; \}/.test(html)) throw new Error('MJML no longer writes .mj-outlook-group-fix: check the head styles');

    return {body, css};
}

const check = process.argv.includes('--check');
const files = {};
const css = new Set();
let failed = false;

for (const [name, block] of Object.entries(blocks)) {
    const a = compile(block, 0), b = compile(block, 1);

    if (a.body !== b.body) {
        failed = true;
        let i = 0;
        while (a.body[i] === b.body[i]) i++;
        console.error(`${name}: MJML derived a value this build doesn't fill in, near:\n  ${a.body.slice(Math.max(0, i - 80), i + 80)}`);
        continue;
    }

    verify(name, block, a.body);
    files[`${name}.html`] = a.body + '\n';
    if (block.css) a.css.forEach((rule) => css.add(rule));
}

if (failed) process.exit(1);

// The columns' styles, as MJML writes them in its <head>: added to an email after its CSS is
// inlined (the inliner would drop rules for classes it can't find, such as .moz-text-html).
const rules = [...css].sort();
files['columns-head.html'] = [
    '<style type="text/css">',
    '@media only screen and (min-width:480px) {',
    ...rules.map((rule) => '  ' + rule),
    '}',
    ...rules.map((rule) => '.moz-text-html ' + rule),
    '</style>',
    '<!--[if mso | IE]><style type="text/css">.mj-outlook-group-fix { width:100% !important; }</style><![endif]-->',
].join('\n') + '\n';

const header = '<!-- Generated by bin/email-blocks/build.js from MJML. Do not edit: change the build and run it. -->\n';

const fixtureJson = JSON.stringify(fixtures, null, 1) + '\n';
const stale = [];
if (check) {
    if (!fs.existsSync(FIXTURES) || fs.readFileSync(FIXTURES, 'utf8') !== fixtureJson) stale.push(path.basename(FIXTURES));
} else {
    fs.mkdirSync(path.dirname(FIXTURES), {recursive: true});
    fs.writeFileSync(FIXTURES, fixtureJson);
}

for (const [file, content] of Object.entries(files)) {
    const target = path.join(OUT, file);
    const full = header + content;

    if (check) {
        if (!fs.existsSync(target) || fs.readFileSync(target, 'utf8') !== full) stale.push(file);
    } else {
        fs.mkdirSync(OUT, {recursive: true});
        fs.writeFileSync(target, full);
    }
}

if (check) {
    const extra = fs.existsSync(OUT) ? fs.readdirSync(OUT).filter((f) => !(f in files)) : [];
    if (stale.length || extra.length) {
        console.error('The compiled email blocks are out of date: run `npm run build` in bin/email-blocks and commit.\n' + stale.concat(extra).join('\n'));
        process.exit(1);
    }
    console.log('Compiled email blocks are up to date.');
} else {
    console.log(`Wrote ${Object.keys(files).length} files to ${path.relative(process.cwd(), OUT)}`);
}
