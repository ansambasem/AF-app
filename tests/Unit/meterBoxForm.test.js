import { before, test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';

let MeterBoxForm;
let meterBoxFormData;

before(async () => {
    const bundle = await rolldown({
        input: path.resolve(import.meta.dirname, '../../resources/js/Pages/MeterBoxes/MeterBoxForm.jsx'),
        platform: 'node',
        transform: { jsx: 'react-jsx' },
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{
            name: 'use-installed-react',
            resolveId(id) {
                if (/^react(?:-dom)?(?:\/|$)/.test(id)) {
                    return { id: import.meta.resolve(id), external: true };
                }
            },
        }],
    });

    try {
        const { output } = await bundle.generate({ format: 'esm' });
        const component = await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`);
        MeterBoxForm = component.default;
        meterBoxFormData = component.meterBoxFormData;
    } finally {
        await bundle.close();
    }
});

function renderForm(data) {
    return renderToStaticMarkup(createElement(MeterBoxForm, {
        data, setData() {}, errors: {}, branches: [], canChooseBranch: false,
        governorates: [], areas: [], subAreas: [], currentBranchAreaId: null,
    }));
}

test('the suffix is a small separate optional text input beside the name and preserves the box number', () => {
    const data = meterBoxFormData({ name: 'camp', name_suffix: '2A', box_number: '9897' });
    const html = renderForm(data);
    const suffix = html.match(/<input(?=[^>]*id="name_suffix")[^>]*>/)[0];

    assert.match(suffix, /value="2A"/);
    assert.doesNotMatch(suffix, /required|type="number"/);
    assert.match(html, /<input(?=[^>]*id="name")[^>]*value="camp"/);
    assert.match(html, /<input(?=[^>]*id="box_number")[^>]*value="9897"/);
    assert.match(html, /<bdi>camp 2A - \(9897\)<\/bdi>/);
});

test('existing boxes without a suffix keep their original name and box number in the form', () => {
    const data = meterBoxFormData({ name: 'camp', box_number: '9898' });
    const html = renderForm(data);

    assert.equal(data.name_suffix, '');
    assert.equal(data.box_number, '9898');
    assert.match(html, /<bdi>camp - \(9898\)<\/bdi>/);
});
