<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Deployment

The `.env.example` file is the *development* template; its first lines list what the live server must set (`APP_ENV=production`, `APP_DEBUG=false`, an `https://` `APP_URL`, `LOG_LEVEL=warning`, `SESSION_SECURE_COOKIE=true`). Serve the site over HTTPS only (if a separate proxy or load balancer ends the TLS in front of the app, trust it with `$middleware->trustProxies(at: ['<its address>'])` in `bootstrap/app.php`, or Laravel sees plain HTTP and neither HSTS nor the secure cookie default applies), then run `php artisan config:cache route:cache view:cache` and `php artisan migrate --force` after every deploy.

**Security headers.** The app itself sends `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, HSTS (over HTTPS) and, in production, a Content-Security-Policy (`CSP_MODE=report-only` tries it without blocking, `off` disables it). Also hide the server's own banners: `server_tokens off;` and `fastcgi_hide_header X-Powered-By;` in nginx (or `expose_php = Off` in `php.ini`).

**Scheduler (cron).** Laravel checks the weekly box cutoff every minute and runs the backup nightly. A single cron entry must invoke the scheduler every minute:

```
* * * * * cd /var/www/powercollect && php artisan schedule:run >> /dev/null 2>&1
```

**Queue worker (supervisor).** The queue is the `database` one; keep a worker alive:

```
[program:powercollect-worker]
command=php /var/www/powercollect/artisan queue:work --sleep=3 --tries=3 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopasgroup=true
stdout_logfile=/var/www/powercollect/storage/logs/worker.log
```

Run `php artisan queue:restart` after each deploy so the worker picks up the new code.

**Backups.** `php artisan backup:run` (scheduled daily at 02:30 UTC, following `app.timezone`) creates three checked files in `storage/app/private/backups`: the database dump (`mysqldump`, or a SQLite snapshot), the private uploads archive (`storage/app/private`, including proofs and imported company forms), and a separate public uploads archive (`storage/app/public`, including bank logos). The existing private archive layout is unchanged. Backups older than `BACKUP_KEEP_DAYS` (14) are removed only after all archives are checked and every configured upload succeeds. A failed upload returns failure, preserves the older backups, and is reported by the scheduler.

These copies are on the same server, so set `BACKUP_DISK` to a configured off-server filesystem disk. Its driver must be installed: the optional S3 driver requires `league/flysystem-aws-s3-v3:^3.0` and valid bucket credentials; setting `BACKUP_DISK=s3` alone does not install it. If `FILESYSTEM_DISK` points to external storage, also back up that storage separately: this command archives the local private and public disks. Preserve the production `APP_KEY` and deployment configuration securely outside these archives. The server needs `mysqldump` installed. To restore into a scratch database and separate empty directories (do this before launch, and periodically afterward):

```
gunzip -c powercollect-YYYYMMDD-HHMMSS-database.sql.gz | mysql -u root -p powercollect_restore
mkdir -p /tmp/restored-private /tmp/restored-public
tar -xzf powercollect-YYYYMMDD-HHMMSS-files.tar.gz -C /tmp/restored-private
tar -xzf powercollect-YYYYMMDD-HHMMSS-public-files.tar.gz -C /tmp/restored-public
```

then compare a few counts (`select count(*) from subscriptions`, the latest `closings` row) with the live system.

**Field app (Android).** Before the first release: create a release keystore and put its details in `mobile/android/key.properties` (git-ignored: `storeFile` (absolute path), `storePassword`, `keyAlias`, `keyPassword`); a release build without it fails rather than being signed with the debug key. The app's identity is `applicationId` in `mobile/android/app/build.gradle.kts` (`com.abuzayed.powercollect`; change it **before** the first Play Store upload, it can never change afterwards). Build with the server address, which a release build refuses to run without:

```
flutter build apk --release --dart-define=API_BASE_URL=https://your-domain
```

Raise `version:` in `mobile/pubspec.yaml` for every upload, and put the download link in `MOBILE_APP_URL` so the website can point field accounts to it. Raise `appBuildNumber` in `mobile/lib/api_client.dart` with the build number (a test checks they match). When a release changes what the mobile API sends or expects, set `MOBILE_APP_MIN_BUILD` to its build number: older apps are then told to update (with the `MOBILE_APP_URL` link) instead of failing, and the readings saved on their phones stay there until they do.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
