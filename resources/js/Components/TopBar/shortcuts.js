/**
 * Whether a page's own window (a modal or a dialog) is open, so the bar's
 * shortcuts stay out of its way. The bar's own windows (search, keys, the
 * phone menu) carry data-top-bar-layer and do not count.
 */
export function pageWindowOpen() {
    return Boolean(document.querySelector('.modal-scroll, [aria-modal="true"]:not([data-top-bar-layer])'));
}

/** Keep the global search shortcut available before the search component is downloaded. */
export function handlePaletteShortcut(event, open, onOpenChange, hasPageWindow = pageWindowOpen) {
    if (event.defaultPrevented || !(event.ctrlKey || event.metaKey) || event.altKey
        || !(event.code === 'KeyK' || event.key.toLowerCase() === 'k') || (!open && hasPageWindow())) {
        return false;
    }
    event.preventDefault();
    onOpenChange(!open);
    return true;
}
