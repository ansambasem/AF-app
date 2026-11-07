import { test } from 'node:test';
import assert from 'node:assert/strict';
import { extraFields, missingForSending, phoneAs, requestPreview } from '../../resources/js/lib/smsSettings.js';

const settings = {
    method: 'post',
    url: 'https://sms.example.ps/api/send',
    to_param: 'to',
    message_param: 'text',
    sender_param: 'from',
    sender: 'AF-Power',
    username_param: 'user',
    username: 'af',
    password_param: 'pass',
    password: '',
    phone_format: 'international',
    country_code: '970',
    extra_params: 'type=unicode&text=ignored',
};

test('a phone goes to the provider in the format it expects', () => {
    assert.equal(phoneAs('international', '970', '0599123456'), '970599123456');
    assert.equal(phoneAs('local', '970', '0599 123 456'), '0599123456');
});

test('fixed fields are read from a query string', () => {
    assert.deepEqual(extraFields(' type=unicode & lang=ar+sa &=x&flag'), [
        { name: 'type', value: 'unicode' },
        { name: 'lang', value: 'ar sa' },
        { name: 'flag', value: '' },
    ]);
});

test('the request preview builds the fields as the gateway does and hides secrets', () => {
    const preview = requestPreview(settings, { hasPassword: true, hasToken: false, message: 'مرحبا' });

    assert.equal(preview.method, 'POST');
    assert.deepEqual(preview.fields.map((field) => [field.name, field.value]), [
        ['type', 'unicode'],
        ['text', 'مرحبا'],
        ['to', '970599123456'],
        ['from', 'AF-Power'],
        ['user', 'af'],
        ['pass', '••••••••'],
    ]);
    assert.deepEqual(preview.headers, []);
    assert.equal(requestPreview({ ...settings, token: 'abc' }).headers[0].value, 'Bearer ••••••••');
    assert.equal(requestPreview(settings, { hasPassword: true }).fields.some((field) => field.name === 'pass'), true);
    assert.equal(requestPreview({ ...settings, clear_password: true }, { hasPassword: true }).fields.some((field) => field.name === 'pass'), false);
});

test('the provider cannot be turned on without an address and its two fields', () => {
    assert.deepEqual(missingForSending({ ...settings, url: '', to_param: '' }), ['رابط الإرسال', 'اسم حقل الرقم']);
    assert.deepEqual(missingForSending(settings), []);
});
