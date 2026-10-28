import { before, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';

let PasswordInput;

before(async () => {
    const bundle = await rolldown({
        input: path.resolve(import.meta.dirname, '../../resources/js/Components/PasswordInput.jsx'),
        platform: 'node', transform: { jsx: 'react-jsx' },
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{
            name: 'external-react',
            resolveId(id) {
                if (/^react(?:-dom)?(?:\/|$)/.test(id)) return { id: import.meta.resolve(id), external: true };
            },
        }],
    });
    try {
        const { output } = await bundle.generate({ format: 'esm', codeSplitting: false });
        ({ default: PasswordInput } = await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`));
    } finally { await bundle.close(); }
});

test('a left-to-right password keeps the show button and its room on the same side', () => {
    const html = renderToStaticMarkup(createElement(PasswordInput, { id: 'password', dir: 'ltr', value: '', onChange() {} }));

    assert.match(html, /^<div class="relative" dir="ltr"><input[^>]* dir="ltr"/);
});

test('a password asked to guess its direction follows the page, as its masked dots give none', () => {
    const html = renderToStaticMarkup(createElement(PasswordInput, { id: 'password', dir: 'auto', value: '', onChange() {} }));

    assert.doesNotMatch(html, /dir=/);
});
