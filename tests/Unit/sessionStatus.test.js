import { test } from 'node:test';
import assert from 'node:assert/strict';
import { watchSessionStatus } from '../../resources/js/lib/sessionStatus.js';

function browserFor(fetchResponse) {
    const listeners = new Map();
    const visibility = new Map();
    const browser = {
        document: {
            visibilityState: 'visible',
            addEventListener: (event, callback) => visibility.set(event, callback),
            removeEventListener: (event) => visibility.delete(event),
        },
        fetch: fetchResponse,
        setInterval(callback) { this.tick = callback; return 1; },
        clearInterval() { this.tick = null; },
        setTimeout() { return 2; },
        clearTimeout() {},
        addEventListener: (event, callback) => listeners.set(event, callback),
        removeEventListener: (event) => listeners.delete(event),
        focus: () => listeners.get('focus')?.(),
        reveal: () => visibility.get('visibilitychange')?.(),
    };
    return browser;
}

test('a replaced browser automatically leaves the protected page and stops polling', async () => {
    let redirects = 0;
    const browser = browserFor(async (url, options) => {
        assert.equal(url, '/auth/session');
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.headers.Accept, 'application/json');
        return {status:401};
    });
    const stop = watchSessionStatus(() => redirects++, browser);
    await browser.tick();
    assert.equal(redirects, 1);
    assert.equal(browser.tick, null);
    assert.equal(browser.focus(), undefined);
    stop();
});

test('valid sessions and connection failures keep the existing page open', async () => {
    const browser = browserFor(async () => ({status:204}));
    let redirects = 0;
    const stop = watchSessionStatus(() => redirects++, browser);
    await browser.tick();
    browser.fetch = async () => { throw new Error('offline'); };
    await browser.focus();
    browser.fetch = async () => ({status:503});
    await browser.tick();
    assert.equal(redirects, 0);
    stop();
});

test('hidden tabs pause checks and a visible tab rechecks without duplicate requests', async () => {
    let finish;
    let requests = 0;
    const pending = new Promise((resolve) => finish = resolve);
    const browser = browserFor(() => { requests++; return pending; });
    const stop = watchSessionStatus(() => assert.fail('unexpected logout'), browser);
    browser.document.visibilityState = 'hidden';
    await browser.tick();
    assert.equal(requests, 0);
    browser.document.visibilityState = 'visible';
    const check = browser.reveal();
    await browser.focus();
    assert.equal(requests, 1);
    finish({status:204});
    await check;
    stop();
});

test('unmounting aborts a pending check and ignores a late unauthorized response', async () => {
    let finish;
    let signal;
    const browser = browserFor((url, options) => { signal=options.signal; return new Promise((resolve) => finish=resolve); });
    const stop = watchSessionStatus(() => assert.fail('late redirect'), browser);
    const check = browser.tick();
    stop();
    assert.equal(signal.aborted, true);
    finish({status:401});
    await check;
});
