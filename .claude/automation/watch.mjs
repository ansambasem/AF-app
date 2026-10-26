import { existsSync, watch } from 'node:fs';
import path from 'node:path';
import { root, runChecks } from './check.mjs';
import { relevantFile } from './plan.mjs';

const pending = new Set();
let timer;
let running = false;
let stopping = false;
async function flush() {
    if (stopping || running || !pending.size) return;
    running = true;
    const files = [...pending];
    pending.clear();
    try {
        const result = await runChecks({ files });
        if (result.status === 'busy' || result.status === 'stale') files.forEach((file) => pending.add(file));
    }
    catch (error) { console.error(`[PowerCollect] ${error.message}`); }
    finally { running = false; if (!stopping && pending.size) timer = setTimeout(flush, 3000); }
}
const watchers = [];
const changed = (file) => {
    if (stopping || !relevantFile(file)) return;
    pending.add(file);
    clearTimeout(timer);
    timer = setTimeout(flush, 1200);
};
for (const folder of ['app', 'bootstrap', 'config', 'database', 'routes', 'resources', 'tests', '.claude/automation', 'mobile/lib', 'mobile/test', 'mobile/android']) {
    const absolute = path.join(root, folder);
    if (!existsSync(absolute)) continue;
    watchers.push(watch(absolute, { recursive: true }, (_, filename) => {
        if (!filename) return;
        const file = `${folder}/${filename}`.replaceAll('\\', '/');
        if (!relevantFile(file) || /(^|\/)(?:build|\.gradle|\.dart_tool)(\/|$)/.test(file)) return;
        changed(file);
    }));
}
for (const folder of ['', 'mobile']) watchers.push(watch(path.join(root, folder), (_, filename) => {
    if (filename) changed([folder, String(filename)].filter(Boolean).join('/'));
}));
console.log('PowerCollect is watching website and mobile source changes. Checks are debounced and run one at a time. Stop with Ctrl+C. Full suite: npm run check.');
const stop = () => { stopping = true; clearTimeout(timer); watchers.forEach((watcher) => watcher.close()); process.exitCode = 0; };
process.on('SIGINT', stop);
process.on('SIGTERM', stop);
