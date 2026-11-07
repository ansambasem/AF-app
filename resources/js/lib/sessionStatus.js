export function watchSessionStatus(onUnauthorized, browser = window) {
    let stopped = false;
    let controller = null;

    async function check() {
        if (stopped || controller || browser.document.visibilityState === 'hidden') return;
        controller = new AbortController();
        const timeout = browser.setTimeout(() => controller?.abort(), 10000);
        try {
            const response = await browser.fetch('/auth/session', {
                credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' }, signal: controller.signal,
            });
            if (!stopped && response.status === 401) {
                stop();
                onUnauthorized();
            }
        } catch {
            // A network interruption does not end an authenticated session.
        } finally {
            browser.clearTimeout(timeout);
            controller = null;
        }
    }

    function stop() {
        stopped = true;
        browser.clearInterval(timer);
        browser.removeEventListener('focus', check);
        browser.document.removeEventListener('visibilitychange', check);
        controller?.abort();
    }

    const timer = browser.setInterval(check, 15000);
    browser.addEventListener('focus', check);
    browser.document.addEventListener('visibilitychange', check);
    return stop;
}
