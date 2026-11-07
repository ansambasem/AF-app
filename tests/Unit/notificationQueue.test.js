import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createNotificationQueue, NOTIFICATION_EXIT_DURATION } from '../../resources/js/lib/notificationQueue.js';

test('repeated actions stack and each message expires after its own four seconds', (context) => {
    context.mock.timers.enable({ apis: ['setTimeout', 'Date'] });
    let visible = [];
    const queue = createNotificationQueue((messages) => { visible = messages; });
    queue.push('Saved');
    context.mock.timers.tick(1000);
    queue.push('Saved');
    assert.equal(visible.length, 2);
    assert.notEqual(visible[0].id, visible[1].id);
    context.mock.timers.tick(2999);
    assert.equal(visible[0].leaving, false);
    context.mock.timers.tick(1);
    assert.equal(visible[0].leaving, true);
    context.mock.timers.tick(NOTIFICATION_EXIT_DURATION);
    assert.equal(visible.length, 1);
    context.mock.timers.tick(1000);
    context.mock.timers.tick(NOTIFICATION_EXIT_DURATION);
    assert.deepEqual(visible, []);
    queue.dispose();
});

test('errors stay up longer than successes', (context) => {
    context.mock.timers.enable({ apis: ['setTimeout', 'Date'] });
    let visible = [];
    const queue = createNotificationQueue((messages) => { visible = messages; });
    queue.push('Failed', 'error');
    context.mock.timers.tick(6999);
    assert.equal(visible[0].leaving, false);
    context.mock.timers.tick(1);
    assert.equal(visible[0].leaving, true);
    context.mock.timers.tick(NOTIFICATION_EXIT_DURATION);
    assert.deepEqual(visible, []);
    queue.dispose();
});

test('a paused message keeps the time it had left until it is resumed', (context) => {
    context.mock.timers.enable({ apis: ['setTimeout', 'Date'] });
    let visible = [];
    const queue = createNotificationQueue((messages) => { visible = messages; });
    queue.push('Saved');
    context.mock.timers.tick(3000);
    queue.pause(visible[0].id);
    context.mock.timers.tick(10000);
    assert.equal(visible[0].leaving, false);
    queue.resume(visible[0].id);
    context.mock.timers.tick(999);
    assert.equal(visible[0].leaving, false);
    context.mock.timers.tick(1);
    assert.equal(visible[0].leaving, true);
    queue.dispose();
});

test('manual dismissal leaves other messages intact and disposal clears pending timers', (context) => {
    context.mock.timers.enable({ apis: ['setTimeout', 'Date'] });
    let visible = [];
    let updates = 0;
    const queue = createNotificationQueue((messages) => { visible = messages; updates++; });
    queue.push('Saved');
    queue.push('Failed', 'error');
    queue.dismiss(visible[0].id);
    context.mock.timers.tick(NOTIFICATION_EXIT_DURATION);
    assert.deepEqual(visible.map(({ message, type }) => ({ message, type })), [{ message: 'Failed', type: 'error' }]);
    queue.dispose();
    const before = updates;
    context.mock.timers.tick(7000);
    assert.equal(updates, before);
});
