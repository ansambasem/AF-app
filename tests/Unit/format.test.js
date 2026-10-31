import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    formatClock,
    formatDate,
    formatDayLabel,
    formatMoney,
    formatNumber,
    formatShortDay,
    initials,
    normalizeDecimalInput,
    percentOf,
    timeAgo,
} from '../../resources/js/lib/format.js';

test('avatar initials take the first letters of the first two words, skipping a title', () => {
    assert.equal(initials("Ahmad O'Keefe"), 'AO');
    assert.equal(initials('Mrs. Ernestine Krajcik II'), 'EK');
    assert.equal(initials('karrada.admin'), 'KA');
    assert.equal(initials('محمد خالد'), 'مخ');
});

test('avatar initials use one letter for a single word and nothing for an empty name', () => {
    assert.equal(initials('admin'), 'A');
    assert.equal(initials('  '), '');
    assert.equal(initials(null), '');
});

test('money gets thousands separators and decimals only when it has them', () => {
    assert.equal(formatMoney(12170), '12,170');
    assert.equal(formatMoney('58.234'), '58.23');
    assert.equal(formatMoney(1255.5), '1,255.50');
    assert.equal(formatMoney(null), '0');
    assert.equal(formatNumber(1234.6), '1,235');
});

test('balances show a minus for subscriber credit and no plus for company debt', () => {
    assert.equal(formatMoney('1255.50'), '1,255.50');
    assert.equal(formatMoney('-1255.50'), '-1,255.50');
    assert.equal(formatMoney('0.00'), '0');
    assert.equal(formatMoney('-0.004'), '0');
    assert.equal(formatMoney(-0), '0');
    assert.equal(formatMoney('-50.00'), '-50');
});

test('a share is a whole percentage, and nothing of an empty whole', () => {
    assert.equal(percentOf(96, 159), 60);
    assert.equal(percentOf(0, 12), 0);
    assert.equal(percentOf(5, 0), 0);
});

test('typed amounts keep plain digits and one decimal point', () => {
    assert.equal(normalizeDecimalInput('١٬٢٥٠٫٥٧٩'), '1250.57');
    assert.equal(normalizeDecimalInput('۲۵'), '25');
    assert.equal(normalizeDecimalInput('1,250.5'), '1250.5');
    assert.equal(normalizeDecimalInput('12.5.3'), '12.53');
    assert.equal(normalizeDecimalInput('3.71234', 4), '3.7123');
    assert.equal(normalizeDecimalInput('.5'), '.5');
    assert.equal(normalizeDecimalInput('abc'), '');
});

test('calendar days read in Arabic without shifting across time zones', () => {
    assert.equal(formatDayLabel('2026-09-26'), 'السبت، 26 سبتمبر');
    assert.equal(formatShortDay('2026-09-01'), '1 سبتمبر');
    assert.equal(formatShortDay('2026-10-29'), '29 أكتوبر');
});

test('a date the server sent reads day first, with whatever follows it kept', () => {
    assert.equal(formatDate('2026-10-29'), '29/10/2026');
    assert.equal(formatDate('2026-01-05 18:58'), '05/01/2026 18:58');
    assert.equal(formatDate('2026-10-29 6:58 م'), '29/10/2026 6:58 م');
    assert.equal(formatDate('29/10/2026'), '29/10/2026');
    assert.equal(formatDate(null), '');
});

test('time ago picks minutes, hours or days and never says "in the future"', () => {
    const now = new Date('2026-09-26T12:00:00Z');

    assert.match(timeAgo('2026-09-26T11:59:50Z', now), /قبل/);
    assert.match(timeAgo('2026-09-26T07:00:00Z', now), /5 ساعات/);
    assert.match(timeAgo('2026-09-24T12:00:00Z', now), /يومين|2 يوم/);
    assert.match(timeAgo('2026-09-26T15:00:00Z', now), /قبل/);
    assert.equal(timeAgo(null, now), '—');
});

test('wall-clock times read as 12-hour Arabic times', () => {
    assert.equal(formatClock('17:05'), '5:05 م');
    assert.equal(formatClock('00:30'), '12:30 ص');
    assert.equal(formatClock('12:00'), '12:00 م');
});
