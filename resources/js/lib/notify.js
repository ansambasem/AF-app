/**
 * Shows a flash notification from anywhere in the page, the way a saved
 * action does: `type` is 'success', 'warning' or 'error'. FlashNotifications
 * listens for it, so the message appears over the page instead of moving
 * what is on it.
 */
export function notify(message, type = 'warning') {
    window.dispatchEvent(new CustomEvent('app:notify', { detail: { message, type } }));
}
