import { test } from 'node:test';
import assert from 'node:assert/strict';
import { childOptions, nestFilterGroups, parentChange, parentValue } from '../../resources/js/lib/filterGroups.js';

const names = { key: 'meter_box_name', label: 'الطبلون', options: [{ value: 'camp', label: 'camp' }, { value: 'club', label: 'club' }] };
const numbers = {
    key: 'meter_box_id',
    label: 'رقم الطبلون',
    dependsOn: 'meter_box_name',
    options: [
        { value: '7', label: '1 (1234)', parent: 'camp' },
        { value: '8', label: '2 (1243)', parent: 'camp' },
        { value: '9', label: '(5000)', parent: 'club' },
    ],
};
const tariff = { key: 'tariff_id', label: 'نوع الاشتراك', options: [] };

test('a dependent group sits with its parent, which always stays shown', () => {
    const { topLevel, childOf, hideable } = nestFilterGroups([names, numbers, tariff]);
    assert.deepEqual(topLevel.map((group) => group.key), ['meter_box_name', 'tariff_id']);
    assert.deepEqual(hideable.map((group) => group.key), ['tariff_id']);
    assert.equal(childOf.meter_box_name, numbers);
    assert.equal(childOf.tariff_id, undefined);
});

test("the child lists only the chosen name's boxes, and nothing before a name is chosen", () => {
    assert.deepEqual(childOptions(numbers, 'camp').map((option) => option.label), ['1 (1234)', '2 (1243)']);
    assert.deepEqual(childOptions(numbers, ''), []);
});

test('picking a new name clears the box chosen under the old one', () => {
    assert.deepEqual(parentChange('meter_box_name', numbers, 'club'), { meter_box_name: 'club', meter_box_id: '' });
    assert.deepEqual(parentChange('tariff_id', undefined, '3'), { tariff_id: '3' });
});

test('a link carrying only a box still shows its name above it', () => {
    assert.equal(parentValue('meter_box_name', numbers, { meter_box_id: '9' }), 'club');
    assert.equal(parentValue('meter_box_name', numbers, { meter_box_name: 'camp', meter_box_id: '' }), 'camp');
    assert.equal(parentValue('meter_box_name', numbers, {}), '');
});
