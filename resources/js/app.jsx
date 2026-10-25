import '../css/app.css';
import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import FlashNotifications from '@/Components/FlashNotifications';
import NavigationProgress from '@/Components/TopBar/NavigationProgress';

// Pages load ahead of time when a link is hovered. A save can change any
// list or count, so forget those early copies once it finishes.
router.on('finish', (event) => {
    if (event.detail.visit.method !== 'get') {
        router.flushAll();
    }
});

createInertiaApp({
    // Each page's code is downloaded only when that page is first opened.
    resolve: (name) => {
        // Forms and modals are imported by their pages, rather than compiled as separate route entries.
        const pages = import.meta.glob(['./Pages/**/*.jsx', '!./Pages/**/*Modal.jsx', '!./Pages/**/*Form.jsx']);
        return pages[`./Pages/${name}.jsx`]();
    },
    setup({ el, App, props }) {
        createRoot(el).render(
            <>
                <NavigationProgress />
                <App {...props} />
                <FlashNotifications initialStatus={props.initialPage.props.status} />
            </>,
        );
    },
    // The top bar draws its own loading line (NavigationProgress).
    progress: false,
});
