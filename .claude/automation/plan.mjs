import path from 'node:path';

export function relevantFile(file) {
    const normalized = file.replaceAll('\\', '/').replace(/^\.\//, '');
    if (path.posix.isAbsolute(normalized) || /^[a-z]:/i.test(normalized) || normalized.split('/').includes('..')) return false;
    if (/(^|\/)(?:\.env(?:\..*)?|auth\.json|key\.properties|.*\.(?:jks|keystore|pem|key))$/i.test(normalized)) return false;
    if (/^(?:bootstrap\/cache\/|mobile\/android\/(?:\.gradle|build)\/|mobile\/android\/local\.properties$)/.test(normalized)) return false;
    if (!/\.(?:php|jsx?|tsx?|mjs|css|json|ya?ml|dart|xml|gradle|kts|properties|lock|md)$/.test(normalized)) return false;
    return /^(?:(?:app|bootstrap|config|database|routes|resources|tests|\.github|\.claude\/automation)\/|\.claude\/settings\.json$|mobile\/(?:lib|test|android)\/|mobile\/(?:pubspec\.(?:yaml|lock)|analysis_options\.yaml)$|(?:composer\.(?:json|lock)|package(?:-lock)?\.json|phpunit\.xml|vite\.config\.js|tailwind\.config\.js)$)/.test(normalized);
}

export function planChecks({ full = false, scope = 'all', files = [], existingFiles = files, apk = false }) {
    const changed = [...new Set(files.map((file) => file.replaceAll('\\', '/')).filter(relevantFile))];
    const steps = [];
    const add = (name, command, args, cwd = '.', testing = false) => steps.push({ name, command, args, cwd, testing });
    const web = scope !== 'mobile';
    const mobile = scope !== 'web';
    const backend = changed.filter((file) => /\.php$/.test(file) && !file.startsWith('resources/'));
    if (web) {
        for (const file of backend.filter((file) => existingFiles.includes(file))) add(`PHP syntax: ${file}`, 'php', ['-l', file]);
        if (full) {
            add('Composer configuration', 'composer', ['validate', '--no-check-publish', '--no-interaction']);
            add('Website PHP tests', 'php', ['artisan', 'test', '--compact'], '.', true);
        } else if (backend.length) {
            const tests = new Set(backend.filter((file) => /^tests\/.*Test\.php$/.test(file) && existingFiles.includes(file)));
            for (const file of backend) {
                if (/MeterReadingController|Models\/MeterReading\.php/.test(file)) tests.add('tests/Feature/MeterReadings');
                if (/(?:ReadingCycle|ReadingAssignment|PaperSheet|ReadingPrint|PrintSelection)/.test(file)) tests.add('tests/Feature/ReadingPrintCustomizationTest.php');
                if (/Middleware|Policies\/|Auth\//.test(file)) tests.add('tests/Feature/Auth');
            }
            if (!tests.size) tests.add('tests/Feature/PageLoadingPerformanceTest.php');
            add('Affected PHP regression tests', 'php', ['artisan', 'test', '--compact', ...tests], '.', true);
        }
        if (full || changed.some((file) => /^(resources\/|tests\/Unit\/.*\.js$|\.claude\/(?:automation\/|settings\.json)|package|vite\.|tailwind\.)/.test(file))) {
            add('Website JavaScript and loading regressions', 'npm', ['test']);
        }
        if (full) add('Website production build', 'npm', ['run', 'build']);
    }
    if (mobile && (full || changed.some((file) => file.startsWith('mobile/')))) {
        add('Mobile static analysis', 'flutter', ['analyze', '--no-pub'], 'mobile');
        add('Mobile workflow and widget tests', 'flutter', ['test', '--no-pub', '--reporter', 'expanded'], 'mobile');
        if (apk) add('Android debug build', 'flutter', ['build', 'apk', '--debug', '--no-pub'], 'mobile');
    }
    return steps;
}
