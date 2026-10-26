import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { changedFiles, fingerprint, git, resultsDir, root, runChecks } from './check.mjs';
import { relevantFile } from './plan.mjs';

export function cachedResultApplies(latest, current) {
    return latest?.fingerprint === current && latest.scope === 'all' && ['changed', 'full'].includes(latest.mode);
}

export function stopFeedback(message, active = false) {
    return {
        systemMessage: message,
        ...(!active ? { hookSpecificOutput: { hookEventName: 'Stop', additionalContext: message } } : {}),
    };
}

export async function handleHook(event) {
    const sessionKey = createHash('sha256').update(String(event.session_id ?? 'local')).digest('hex').slice(0, 24);
    mkdirSync(resultsDir, { recursive: true });
    const statePath = path.join(resultsDir, `session-${sessionKey}.json`);
    const state = existsSync(statePath) ? JSON.parse(readFileSync(statePath, 'utf8')) : { base: git('rev-parse', 'HEAD'), files: [] };
    if (event.hook_event_name === 'SessionStart') {
        writeFileSync(statePath, JSON.stringify(state));
        return { systemMessage: 'PowerCollect local checks are active. Affected checks run after edits when this turn finishes. Full checks: /powercollect-check or npm run check. Saved results: npm run check:status.' };
    } else if (event.hook_event_name === 'PostToolUse') {
        const candidate = event.tool_input?.file_path;
        const file = candidate ? path.relative(root, path.resolve(event.cwd ?? root, candidate)).replaceAll('\\', '/') : '';
        if (relevantFile(file)) {
            state.files = [...new Set([...state.files, file])];
            writeFileSync(statePath, JSON.stringify(state));
        }
    } else if (event.hook_event_name === 'Stop') {
        const files = [...new Set([...state.files, ...changedFiles(state.base)])];
        const current = fingerprint(files);
        const latestPath = path.join(resultsDir, 'latest.json');
        const latest = existsSync(latestPath) ? JSON.parse(readFileSync(latestPath, 'utf8')) : null;
        if (files.length && cachedResultApplies(latest, current)) {
            if (!latest.passed) return stopFeedback(`PowerCollect's last checks did not pass (${latest.status}). No newer passing result exists. Read ${latest.logPath}. Interrupted automatic checks can be rerun manually through npm run check:changed without the hook's time limit. Do not claim success.`, event.stop_hook_active);
        } else if (files.length) {
            const result = await runChecks({ files, quiet: true, budgetMs: 150000 });
            if (result.status === 'busy') return stopFeedback('PowerCollect checks are already running. Use npm run check:status after completion; do not claim they passed yet.', event.stop_hook_active);
            if (result.status === 'no_checks') return { systemMessage: 'No runnable affected checks were selected. Previous results were preserved.' };
            if (!result.passed) return stopFeedback(`PowerCollect checks did not pass (${result.status}): ${result.steps.filter((step) => !step.passed).map((step) => step.name).join(', ')}. Read ${result.logPath}, fix regressions within this task, and rerun through npm run check:changed. Report unrelated failures. Do not claim a stale, skipped or interrupted check passed.`, event.stop_hook_active);
            return { systemMessage: `PowerCollect affected checks passed (${result.steps.length}). Full suite has not been run by this hook. Results: ${latestPath}` };
        }
    }
    return null;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    let event;
    try {
        let input = '';
        for await (const chunk of process.stdin) input += chunk;
        event = JSON.parse(input);
        const output = await handleHook(event);
        if (output) console.log(JSON.stringify(output));
    } catch (error) {
        const message = `PowerCollect automation could not complete: ${error.message}. Run npm run check manually; this is not a passing check.`;
        console.log(JSON.stringify(event?.hook_event_name === 'Stop' ? stopFeedback(message, event.stop_hook_active) : { systemMessage: message }));
    }
}
