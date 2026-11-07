import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { rolldown } from 'rolldown';
import { handlePaletteShortcut } from '../../resources/js/Components/TopBar/shortcuts.js';

const shortcut = (overrides = {}) => ({ code: 'KeyK', key: 'k', ctrlKey: true, preventDefault() { this.defaultPrevented = true; }, ...overrides });

test('Ctrl K opens the search before its component loads and Command K closes it', () => {
    const changes = [];
    const first = shortcut();
    assert.equal(handlePaletteShortcut(first, false, (open) => changes.push(open), () => false), true);
    assert.equal(first.defaultPrevented, true);
    assert.equal(handlePaletteShortcut(shortcut({ ctrlKey: false, metaKey: true }), true, (open) => changes.push(open), () => true), true);
    assert.deepEqual(changes, [true, false]);
});

test('the global search shortcut leaves page dialogs and handled keys alone', () => {
    const changes = [];
    for (const [event, dialog] of [[shortcut(), true], [shortcut({ defaultPrevented: true }), false], [shortcut({ altKey: true }), false], [shortcut({ ctrlKey: false }), false]]) {
        assert.equal(handlePaletteShortcut(event, false, (open) => changes.push(open), () => dialog), false);
    }
    assert.deepEqual(changes, []);
});

test('opening lists does not download dormant forms, statements or print tools', async () => {
    const bundle = await rolldown({
        input: {
            subscriptions: path.resolve(import.meta.dirname, '../../resources/js/Pages/Subscriptions/Index.jsx'),
            readings: path.resolve(import.meta.dirname, '../../resources/js/Pages/MeterReadings/Index.jsx'),
            distribution: path.resolve(import.meta.dirname, '../../resources/js/Pages/ReadingCycles/Show.jsx'),
        },
        platform: 'browser', transform: { jsx: 'react-jsx' },
        external: ['react', 'react-dom', 'react-dom/client', 'react/jsx-runtime', '@inertiajs/react'],
        resolve: { alias: { '@': path.resolve(import.meta.dirname, '../../resources/js') } },
        plugins: [{ name: 'ignore-test-styles', resolveId(id) { if (id.endsWith('.css')) return '\0css'; }, load(id) { if (id === '\0css') return ''; } }],
    });
    try {
        const { output } = await bundle.generate({ format: 'esm' });
        const chunks = new Map(output.filter((item) => item.type === 'chunk').map((chunk) => [chunk.fileName, chunk]));
        const modulesOf = (files) => files.flatMap((file) => Object.keys(chunks.get(file).modules).map((id) => id.replaceAll('\\', '/')));
        const allModules = modulesOf([...chunks.keys()]);
        for (const entry of output.filter((item) => item.type === 'chunk' && item.isEntry)) {
            const initial = new Set();
            function collect(file) {
                if (initial.has(file) || !chunks.has(file)) return;
                initial.add(file);
                chunks.get(file).imports.forEach(collect);
            }
            collect(entry.fileName);
            const initialModules = modulesOf([...initial]);
            for (const module of ['Subscriptions/SubscriptionModal.jsx', 'Subscriptions/SubscriptionDetailsModal.jsx', 'Subscriptions/ReadingHistoryModal.jsx', 'Subscriptions/StatementModal.jsx', 'Print/PrintDesigner.jsx', 'Messages/QuickMessageModal.jsx', 'Components/CommandPalette.jsx']) {
                assert.ok(allModules.some((id) => id.endsWith(module)), `${module} must remain available to open`);
                assert.equal(initialModules.some((id) => id.endsWith(module)), false, `${module} must wait until requested on ${entry.name}`);
            }
        }
    } finally {
        await bundle.close();
    }
});
