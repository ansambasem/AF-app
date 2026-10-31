import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, writeFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { planChecks, relevantFile } from '../../.claude/automation/plan.mjs';
import { root, commandFor, testEnvironment, runChecks, execute } from '../../.claude/automation/check.mjs';
import { cachedResultApplies, stopFeedback } from '../../.claude/automation/hook.mjs';

function cleanup(outputDir) {
    const resolved = path.resolve(outputDir);
    assert.equal(path.dirname(resolved), path.resolve(tmpdir()));
    assert.ok(path.basename(resolved).startsWith('powercollect-check-'));
    rmSync(resolved, { recursive: true, force: true });
}

test('checks target the repository and never select credentials, exports, generated files or external paths', () => {
    assert.equal(existsSync(path.join(root, '.claude/automation/check.mjs')), true);
    for (const file of ['.env', '.env.production', 'mobile/android/key.properties', 'mobile/android/release.keystore', 'mobile/android/key.jks', 'app/keys/private.pem', '../app/Foo.php', 'C:/outside/file.php', '/etc/passwd', 'database/database.sqlite', 'resources/export.csv', 'storage/logs/laravel.log', 'bootstrap/cache/config.php', 'mobile/android/.gradle/config.json']) assert.equal(relevantFile(file), false, file);
    for (const file of ['app/Http/Controllers/MeterReadingController.php', 'resources/js/Pages/Dashboard.jsx', 'mobile/lib/main.dart', 'mobile/test/reading_flow_test.dart', '.claude/settings.json', '.claude/automation/check.mjs']) assert.equal(relevantFile(file), true, file);
});

test('affected checks keep PHP, JavaScript and mobile work separate and do not build on every save', () => {
    const web = planChecks({ files: ['resources/js/Pages/Dashboard.jsx'] });
    assert.deepEqual(web.map((step) => step.command), ['npm']);
    const mobile = planChecks({ files: ['mobile/lib/main.dart'] });
    assert.deepEqual(mobile.map((step) => step.args[0]), ['analyze', 'test']);
    assert.ok(mobile.every((step) => step.cwd === 'mobile' && step.args.includes('--no-pub')));
    const backend = planChecks({ files: ['app/Http/Controllers/MeterReadingController.php'] });
    assert.deepEqual(backend[0].args, ['-l', 'app/Http/Controllers/MeterReadingController.php']);
    assert.ok(backend[1].args.includes('tests/Feature/MeterReadings'));
    assert.equal(backend[1].testing, true);
    for (const file of ['app/Http/Controllers/MeterReadingAdjustmentController.php', 'app/Http/Concerns/FiltersReadingSheet.php']) {
        assert.ok(planChecks({ files: [file] }).some((step) => step.args.includes('tests/Feature/MeterReadings')), file);
    }
    assert.equal([...web, ...mobile, ...backend].some((step) => step.args.includes('build')), false);
});

test('deleting source still triggers regressions without linting a nonexistent file', () => {
    const steps = planChecks({ files: ['app/Models/MeterReading.php', 'resources/js/old.js'], existingFiles: [] });
    assert.equal(steps.some((step) => step.args[0] === '-l'), false);
    assert.ok(steps.some((step) => step.testing));
    assert.ok(steps.some((step) => step.command === 'npm'));
});

test('report backend edits automatically run financial reporting regressions', () => {
    for (const file of ['app/Http/Controllers/ReportController.php', 'app/Support/ReportDetails.php']) {
        const checks = planChecks({ files: [file] });
        assert.ok(checks.some((step) => step.testing && step.args.includes('tests/Feature/BranchReportTest.php')), file);
    }
});

test('full checks include both applications but APK building is explicit', () => {
    const steps = planChecks({ full: true });
    assert.deepEqual(steps.map((step) => step.name), ['Composer configuration', 'Website PHP tests', 'Website JavaScript and loading regressions', 'Website production build', 'Mobile static analysis', 'Mobile workflow and widget tests']);
    assert.equal(planChecks({ full: true, scope: 'web' }).some((step) => step.command === 'flutter'), false);
    assert.ok(planChecks({ full: true, scope: 'mobile', apk: true }).some((step) => step.args.includes('apk')));
});

test('PHP test environment avoids live databases and cached local app configuration', () => {
    const env = testEnvironment(tmpdir());
    assert.equal(env.APP_ENV, 'testing');
    assert.equal(env.DB_CONNECTION, 'sqlite');
    assert.equal(env.DB_DATABASE, ':memory:');
    assert.equal(env.DB_URL, '');
    assert.equal(env.CACHE_STORE, 'array');
    assert.equal(existsSync(env.APP_CONFIG_CACHE), false);
    assert.notEqual(env.APP_CONFIG_CACHE, testEnvironment(tmpdir()).APP_CONFIG_CACHE);
});

test('dynamic PHP paths use native argument arrays and Windows shell commands reject injected arguments', () => {
    assert.deepEqual(commandFor({ command: 'php', args: ['-l', 'app/space & special.php'] }, 'win32'), ['php', ['-l', 'app/space & special.php']]);
    assert.throws(() => commandFor({ command: 'flutter', args: ['test', 'x & echo bad'] }, 'win32'), /Unexpected shell argument/);
});

test('runner records failed steps, continues other checks and releases its lock', async () => {
    const outputDir = mkdtempSync(path.join(tmpdir(), 'powercollect-check-'));
    try {
        let count = 0;
        const result = await runChecks({ full: true, scope: 'web', files: [], quiet: true, outputDir, invoke: async (step) => ({ ...step, passed: count++ !== 0, exitCode: count === 1 ? 1 : 0 }) });
        assert.equal(count, 4);
        assert.equal(result.passed, false);
        const saved = JSON.parse(readFileSync(path.join(outputDir, 'latest.json'), 'utf8'));
        assert.equal(saved.steps[0].passed, false);
        assert.equal(saved.steps[3].passed, true);
        assert.equal(existsSync(path.join(outputDir, 'running.lock')), false);
    } finally { cleanup(outputDir); }
});

test('a running check is reported busy rather than starting overlapping jobs', async () => {
    const outputDir = mkdtempSync(path.join(tmpdir(), 'powercollect-check-'));
    try {
        writeFileSync(path.join(outputDir, 'running.lock'), String(process.pid));
        let invoked = false;
        const result = await runChecks({ files: [], outputDir, quiet: true, invoke: async () => { invoked = true; } });
        assert.equal(result.status, 'busy');
        assert.equal(result.passed, false);
        assert.equal(invoked, false);
    } finally { cleanup(outputDir); }
});

test('edits made while checks run cannot inherit a passing result for earlier source', async () => {
    const outputDir = mkdtempSync(path.join(tmpdir(), 'powercollect-check-'));
    try {
        let reads = 0;
        const result = await runChecks({ full: true, scope: 'web', files: [], quiet: true, outputDir,
            fingerprintFiles: () => reads++ === 0 ? 'before-edit' : 'after-edit',
            invoke: async (step) => ({ ...step, passed: true, exitCode: 0 }),
        });
        assert.equal(result.status, 'stale');
        assert.equal(result.passed, false);
        assert.equal(result.fingerprint, 'before-edit');
    } finally { cleanup(outputDir); }
});

test('a hung check is terminated and recorded as a timeout rather than a pass', async () => {
    const outputDir = mkdtempSync(path.join(tmpdir(), 'powercollect-check-'));
    try {
        const result = await execute({ name: 'Timeout fixture', command: process.execPath, args: ['-e', 'setInterval(()=>{},1000)'], cwd: '.' }, path.join(outputDir, 'check.log'), true, { timeoutMs: 150 });
        assert.equal(result.passed, false);
        assert.equal(result.exitCode, 124);
    } finally { cleanup(outputDir); }
});

test('a mobile-only check cannot suppress website checks with an identical source fingerprint', () => {
    assert.equal(cachedResultApplies({ fingerprint: 'same', scope: 'mobile', mode: 'full', passed: true }, 'same'), false);
    assert.equal(cachedResultApplies({ fingerprint: 'same', scope: 'all', mode: 'changed', passed: true }, 'same'), true);
    assert.equal(cachedResultApplies({ fingerprint: 'different', scope: 'all', mode: 'full', passed: true }, 'same'), false);
});

test('failed or busy results reach Claude once without an endless Stop continuation', () => {
    const message = 'Checks failed; do not claim success.';
    assert.equal(stopFeedback(message).hookSpecificOutput.additionalContext, message);
    assert.equal(stopFeedback(message, true).systemMessage, message);
    assert.equal(stopFeedback(message, true).hookSpecificOutput, undefined);
});

test('an empty changed check preserves earlier failure reports', async () => {
    const outputDir = mkdtempSync(path.join(tmpdir(), 'powercollect-check-'));
    try {
        const previous = '{"passed":false,"mode":"full"}';
        writeFileSync(path.join(outputDir, 'latest.json'), previous);
        const result = await runChecks({ files: [], outputDir, quiet: true });
        assert.equal(result.status, 'no_checks');
        assert.equal(result.passed, null);
        assert.equal(readFileSync(path.join(outputDir, 'latest.json'), 'utf8'), previous);
    } finally { cleanup(outputDir); }
});

test('the hook deadline is explicit while manual affected checks keep the normal per-step limit', async () => {
    const outputDir = mkdtempSync(path.join(tmpdir(), 'powercollect-check-'));
    try {
        const limits = [];
        const invoke = async (step, log, quiet, options) => { limits.push(options.timeoutMs); return { ...step, passed: true, exitCode: 0 }; };
        await runChecks({ files: ['tests/Unit/automation.test.js'], outputDir, quiet: true, invoke });
        await runChecks({ files: ['tests/Unit/automation.test.js'], outputDir, quiet: true, invoke, budgetMs: 1000 });
        assert.equal(limits[0], 1800000);
        assert.ok(limits[1] > 0 && limits[1] <= 1000);
    } finally { cleanup(outputDir); }
});
