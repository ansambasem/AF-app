import { spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { createWriteStream, existsSync, mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const suite = process.argv[2] === '--print' ? 'print-templates.cjs' : process.argv[2] === '--distribution' ? 'reading-distribution.cjs' : process.argv[2] === '--readings' ? 'weekly-readings.cjs' : 'company-workflow.cjs';
if (process.argv.slice(2).some((argument) => !['--print', '--distribution', '--readings'].includes(argument)) || process.argv.length > 3) throw new Error('Usage: node tests/Browser/run.mjs [--print|--distribution|--readings]');
const work = mkdtempSync(path.join(tmpdir(), 'powercollect-browser-'));
const output = path.join(root, 'storage/logs/browser-tests', path.basename(work));
mkdirSync(output, { recursive: true });
const database = path.join(work, 'app.sqlite');
writeFileSync(database, '');
const listener = createServer();
await new Promise((resolve, reject) => {
    listener.once('error', reject);
    listener.listen(0, '127.0.0.1', resolve);
});
const port = listener.address().port;
await new Promise((resolve) => listener.close(resolve));
const baseUrl = `http://127.0.0.1:${port}`;
const env = {
    ...process.env,
    APP_ENV: 'local', APP_DEBUG: 'true', APP_URL: baseUrl,
    APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
    APP_CONFIG_CACHE: path.join(work, 'config.php'),
    APP_ROUTES_CACHE: path.join(work, 'routes.php'),
    APP_EVENTS_CACHE: path.join(work, 'events.php'),
    DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '',
    SESSION_DRIVER: 'file', SESSION_COOKIE: 'powercollect_browser_tests', SESSION_SECURE_COOKIE: 'false',
    CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array',
    BRANCH_DOMAINS_ENABLED: 'false', BASE_URL: baseUrl, OUT_DIR: output,
};

let serverRouter = path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php');
if (suite === 'weekly-readings.cjs') {
    env.SESSION_DRIVER = 'database';
    env.BROWSER_TEST_CLOCK = path.join(work, 'clock.txt');
    env.BROWSER_TEST_AUTOLOAD = path.join(root, 'vendor/autoload.php');
    env.BROWSER_TEST_ROUTER = serverRouter;
    const firstReadingDay = new Date();
    firstReadingDay.setUTCDate(firstReadingDay.getUTCDate() + ((4 - firstReadingDay.getUTCDay() + 7) % 7 || 7));
    writeFileSync(env.BROWSER_TEST_CLOCK, `${firstReadingDay.toISOString().slice(0, 10)}T10:00:00+03:00`);
    serverRouter = path.join(work, 'clock-router.php');
    writeFileSync(serverRouter, `<?php
require_once getenv('BROWSER_TEST_AUTOLOAD');
Illuminate\\Support\\Facades\\Date::setTestNow(new DateTimeImmutable(trim(file_get_contents(getenv('BROWSER_TEST_CLOCK')))));
return require getenv('BROWSER_TEST_ROUTER');
`);
}

function run(command, args) {
    return new Promise((resolve, reject) => {
        const child = spawn(command, args, { cwd: root, env, windowsHide: true, stdio: 'inherit' });
        child.once('error', reject);
        child.once('exit', (code, signal) => {
            if (code === 0) resolve();
            else reject(new Error(`${command} failed (${signal ?? code}).`));
        });
    });
}

let server;
let serverClosed;
const log = createWriteStream(path.join(output, 'server.log'));
try {
    if (!existsSync(path.join(root, 'public/build/manifest.json'))) {
        throw new Error('Build the website first: npm run build');
    }
    await run('php', ['artisan', 'migrate', '--force', '--no-interaction', '--quiet']);
    for (const seeder of ['PermissionSeeder', 'CurrencyRateSeeder']) {
        await run('php', ['artisan', 'db:seed', `--class=${seeder}`, '--force', '--no-interaction', '--quiet']);
    }
    await run('php', ['artisan', 'tinker', '--no-interaction', '--execute',
        'App\\Models\\User::factory()->superAdmin()->create(["name" => "مالك الشركة", "username" => "owner", "password" => "Strong-Pass-2026"]);']);
    if (suite === 'reading-distribution.cjs') {
        await run('php', ['artisan', 'tinker', '--no-interaction', '--execute', 'require base_path("tests/Browser/reading-distribution-fixture.php");']);
    }
    if (suite === 'weekly-readings.cjs') {
        await run('php', ['artisan', 'tinker', '--no-interaction', '--execute', 'require base_path("tests/Browser/weekly-readings-fixture.php");']);
    }
    server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', path.join(root, 'public'), serverRouter],
        { cwd: path.join(root, 'public'), env, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    server.stdout.pipe(log);
    server.stderr.pipe(log);
    serverClosed = new Promise((resolve) => server.once('close', resolve));
    let startupError;
    server.once('error', (error) => { startupError = error; });
    let ready = false;
    for (let attempt = 0; attempt < 50; attempt++) {
        if (startupError) throw startupError;
        if (server.exitCode !== null) throw new Error('Browser test server exited. See server.log.');
        try {
            ready = (await fetch(`${baseUrl}/login`, { signal: AbortSignal.timeout(5000) })).ok;
        } catch { /* Wait for the local test server to listen. */ }
        if (ready) break;
        await new Promise((resolve) => setTimeout(resolve, 200));
    }
    if (!ready) throw new Error('Browser test server did not become ready. See server.log.');
    await run(process.execPath, [`tests/Browser/${suite}`]);
} catch (error) {
    console.error(error.message);
    process.exitCode = 1;
} finally {
    if (server && server.exitCode === null) server.kill();
    if (serverClosed) await serverClosed;
    log.end();
    if (path.dirname(work) === path.resolve(tmpdir()) && path.basename(work).startsWith('powercollect-browser-')) {
        rmSync(work, { recursive: true, force: true });
    }
    console.log(`Browser test evidence: ${output}`);
}
