import { spawn, execFileSync } from 'node:child_process';
import { appendFileSync, existsSync, mkdirSync, openSync, closeSync, readFileSync, realpathSync, unlinkSync, writeFileSync } from 'node:fs';
import { createHash, randomUUID } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { planChecks, relevantFile } from './plan.mjs';

export const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
export const resultsDir = path.join(root, 'storage/logs/automation');
const normalize = (file) => file.replaceAll('\\', '/');
export const git = (...args) => execFileSync('git', args, { cwd: root, encoding: 'utf8', windowsHide: true }).trim();

export function changedFiles(base = null) {
    const lists = [git('diff', '--no-renames', '--name-only', '-z', 'HEAD'), git('ls-files', '--others', '--exclude-standard', '-z')];
    if (base && /^[a-f0-9]{40,64}$/i.test(base)) {
        try { lists.push(git('diff', '--no-renames', '--name-only', '-z', base, 'HEAD')); }
        catch { /* Rebased-away session bases do not prevent checking current edits. */ }
    }
    return [...new Set(lists.flatMap((text) => text.split('\0')).filter(relevantFile))];
}

export function safeFile(file) {
    if (!relevantFile(file)) return false;
    const absolute = path.resolve(root, file);
    if (!existsSync(absolute)) return false;
    const relative = path.relative(root, realpathSync(absolute));
    return relative !== '..' && !relative.startsWith(`..${path.sep}`) && !path.isAbsolute(relative);
}

export function fingerprint(files) {
    const hash = createHash('sha256');
    for (const file of [...new Set(files)].sort()) {
        hash.update(normalize(file));
        hash.update(safeFile(file) ? readFileSync(path.resolve(root, file)) : '<deleted>');
    }
    return hash.digest('hex');
}

export function commandFor(step, platform = process.platform) {
    if (step.command === 'npm' && process.env.npm_execpath?.endsWith('npm-cli.js')) {
        return [process.execPath, [process.env.npm_execpath, ...step.args]];
    }
    if (platform === 'win32' && ['npm', 'flutter', 'composer'].includes(step.command)) {
        if (!step.args.every((arg) => /^[a-zA-Z0-9:./=-]+$/.test(arg))) throw new Error('Unexpected shell argument');
        return [process.env.ComSpec ?? 'cmd.exe', ['/d', '/s', '/c', `${step.command} ${step.args.join(' ')}`]];
    }
    return [step.command, step.args];
}

export function testEnvironment(logDir = resultsDir) {
    return {
        ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', DB_URL: '',
        CACHE_STORE: 'array', SESSION_DRIVER: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array',
        APP_CONFIG_CACHE: path.join(logDir, `uncached-${randomUUID()}.php`),
    };
}

export async function execute(step, logPath, quiet = false, { timeoutMs = 1800000, signal } = {}) {
    const [command, args] = commandFor(step);
    return new Promise((resolve) => {
        const child = spawn(command, args, {
            cwd: path.resolve(root, step.cwd), env: step.testing ? testEnvironment() : process.env,
            windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'], detached: process.platform !== 'win32',
        });
        let error = null;
        let interrupted = false;
        const record = (chunk) => { appendFileSync(logPath, chunk); if (!quiet) process.stdout.write(chunk); };
        const stop = () => {
            interrupted = true;
            if (!child.pid) return;
            try {
                if (process.platform === 'win32') execFileSync('taskkill', ['/pid', String(child.pid), '/T', '/F'], { windowsHide: true, stdio: 'ignore', timeout: 5000 });
                else process.kill(-child.pid, 'SIGTERM');
            } catch { child.kill(); }
        };
        const timer = setTimeout(() => { record('\nCheck exceeded its time limit.\n'); stop(); }, timeoutMs);
        signal?.addEventListener('abort', stop, { once: true });
        if (signal?.aborted) stop();
        child.stdout.on('data', record);
        child.stderr.on('data', record);
        child.on('error', (failure) => { error = failure.message; record(`\nUnable to start ${step.command}: ${error}\n`); });
        child.on('close', (code) => {
            clearTimeout(timer);
            signal?.removeEventListener('abort', stop);
            resolve({ ...step, exitCode: interrupted ? 124 : code ?? 1, passed: code === 0 && error === null && !interrupted });
        });
    });
}

export async function runChecks({ full = false, scope = 'all', files = null, apk = false, quiet = false, invoke = execute, outputDir = resultsDir, fingerprintFiles = fingerprint, budgetMs = Infinity } = {}) {
    mkdirSync(outputDir, { recursive: true });
    const lockPath = path.join(outputDir, 'running.lock');
    let lock;
    try { lock = openSync(lockPath, 'wx'); }
    catch (error) {
        if (error.code !== 'EEXIST') throw error;
        try {
            const pid = Number(readFileSync(lockPath, 'utf8'));
            if (!Number.isInteger(pid) || pid <= 0) return { status: 'busy', passed: false, steps: [] };
            process.kill(pid, 0);
        } catch (failure) {
            if (failure.code === 'ESRCH') { unlinkSync(lockPath); return runChecks({ full, scope, files, apk, quiet, invoke, outputDir, fingerprintFiles, budgetMs }); }
            if (failure.code !== 'EPERM') throw failure;
        }
        return { status: 'busy', passed: false, steps: [] };
    }
    const controller = new AbortController();
    const interrupt = () => controller.abort();
    process.on('SIGINT', interrupt);
    process.on('SIGTERM', interrupt);
    try {
        writeFileSync(lock, String(process.pid));
        const selectedFiles = (files ?? changedFiles()).filter((file) => relevantFile(file));
        const plan = planChecks({ full, scope, files: selectedFiles, existingFiles: selectedFiles.filter(safeFile), apk });
        if (!plan.length) {
            if (!quiet) console.log('[PowerCollect] Nothing to check. Previous results were preserved.');
            return { status: 'no_checks', passed: null, steps: [] };
        }
        const logPath = path.join(outputDir, `check-${new Date().toISOString().replaceAll(':', '-')}-${randomUUID().slice(0, 8)}.log`);
        const result = { status: 'complete', mode: full ? 'full' : 'changed', scope, startedAt: new Date().toISOString(), files: selectedFiles, fingerprint: fingerprintFiles(selectedFiles), steps: [], logPath };
        const deadline = Date.now() + budgetMs;
        for (const step of plan) {
            appendFileSync(logPath, `\n=== ${step.name} ===\n`);
            if (!quiet) console.log(`\n[PowerCollect] ${step.name}`);
            if (controller.signal.aborted || Date.now() >= deadline) result.steps.push({ ...step, passed: false, exitCode: 124, skipped: true });
            else result.steps.push(await invoke(step, logPath, quiet, { timeoutMs: Math.min(1800000, deadline - Date.now()), signal: controller.signal }));
        }
        if (controller.signal.aborted || result.steps.some((step) => step.exitCode === 124)) result.status = 'interrupted';
        else if (result.fingerprint !== fingerprintFiles(selectedFiles)) result.status = 'stale';
        result.passed = result.status === 'complete' && !controller.signal.aborted && result.steps.every((step) => step.passed);
        result.finishedAt = new Date().toISOString();
        writeFileSync(path.join(outputDir, 'latest.json'), JSON.stringify(result, null, 2));
        if (!quiet) console.log(`\n[PowerCollect] ${result.passed ? 'PASS' : result.status === 'complete' ? 'FAIL' : result.status.toUpperCase()}: ${result.steps.length} checks. Results: ${path.join(outputDir, 'latest.json')}`);
        return result;
    } finally {
        process.removeListener('SIGINT', interrupt);
        process.removeListener('SIGTERM', interrupt);
        closeSync(lock);
        unlinkSync(lockPath);
    }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const args = process.argv.slice(2);
    if (args.some((arg) => !['--full', '--changed', '--web', '--mobile', '--apk', '--status'].includes(arg)) || (args.includes('--web') && args.includes('--mobile'))) {
        console.error('Usage: npm run check [-- --web|--mobile] [-- --apk], npm run check:changed, npm run check:status');
        process.exitCode = 2;
    } else if (args.includes('--status')) {
        const latest = path.join(resultsDir, 'latest.json');
        if (existsSync(path.join(resultsDir, 'running.lock'))) console.log('A check lock is present. Results below describe the last completed run, not the running check.');
        console.log(existsSync(latest) ? readFileSync(latest, 'utf8') : 'No checks have run yet. Run npm run check.');
    } else {
        try {
            const result = await runChecks({ full: args.includes('--full'), scope: args.includes('--web') ? 'web' : args.includes('--mobile') ? 'mobile' : 'all', apk: args.includes('--apk') });
            if (result.status === 'busy') console.error('Another check is running. Wait for it to finish, then retry.');
            process.exitCode = result.passed || result.status === 'no_checks' ? 0 : 1;
        } catch (error) { console.error(`[PowerCollect] ${error.message}`); process.exitCode = 1; }
    }
}
