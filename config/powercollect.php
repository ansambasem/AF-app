<?php

return [

    'mobile_app' => ['directory' => resource_path('mobile')],

    'branch_domains_enabled' => (bool) env('BRANCH_DOMAINS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Seeded Super Admin
    |--------------------------------------------------------------------------
    |
    | Credentials for the one Super Admin account the database seeder
    | creates so there's a way to log in on a fresh install. In production
    | these must be set via the environment; the seeder refuses to fall
    | back to a default password outside local/testing environments.
    |
    */

    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'المدير العام'),
        'username' => env('SUPER_ADMIN_USERNAME', 'admin'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

    /*
    | The manager of the مخيم 2 branch, added by BranchManagerSeeder.
    */

    'branch_manager' => [
        'name' => env('BRANCH_MANAGER_NAME', 'Mohammed'),
        'username' => env('BRANCH_MANAGER_USERNAME', 'mohammed'),
        'password' => env('BRANCH_MANAGER_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bank Transfer Destinations
    |--------------------------------------------------------------------------
    |
    | The banks and e-wallets offered for the source and destination of
    | a subscriber's transfer. Both show in the البنك column of the
    | account statement.
    |
    */

    'transfer_banks' => ['بنك فلسطين', 'محفظة بالباي', 'جوال باي', 'البنك الإسلامي الفلسطيني', 'البنك الإسلامي العربي'],

    /*
    |--------------------------------------------------------------------------
    | Banks Transfers Come From
    |--------------------------------------------------------------------------
    |
    | The banks and e-wallets a sender can transfer from: every bank above,
    | and the banks the company never receives into.
    |
    */

    'sender_banks' => ['بنك فلسطين', 'محفظة بالباي', 'جوال باي', 'البنك الإسلامي الفلسطيني', 'البنك الإسلامي العربي', 'بنك القدس'],

    /*
    |--------------------------------------------------------------------------
    | Usual Charge Amounts
    |--------------------------------------------------------------------------
    |
    | What a charge of each type usually comes to, in shekels, suggested in
    | the charge form (the user can still change it). Types not listed have
    | no usual amount.
    |
    */

    'usual_charges' => [
        'disconnection_fee' => (float) env('USUAL_DISCONNECTION_FEE', env('DISCONNECTION_FEE', 20)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Disconnection
    |--------------------------------------------------------------------------
    |
    | The fee charged, as a "Service Disconnection Fee" line, when a
    | disconnection from a disconnection list is confirmed as carried out.
    | A subscriber given a fee of their own pays that instead (0 for none).
    |
    */

    'disconnection' => [
        'fee' => (float) env('DISCONNECTION_FEE', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | The most a subscription fee may be, whether charged when the subscription
    | is registered or by hand later; anything above is a slip.
    |
    */

    'limits' => [
        'subscription_fee' => (float) env('SUBSCRIPTION_FEE_MAX', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments
    |--------------------------------------------------------------------------
    |
    | A payment above what the subscription owes leaves them in credit, which
    | the forms point out. One above `overpayment_multiplier` times what is
    | owed, and by at least `overpayment_confirmation_minimum` shekels, is
    | probably a slip (5000 for 50), so it is saved only once the collector
    | confirms it. Keep the same two numbers in resources/js/lib/overpayment.js.
    |
    */

    'payments' => [
        'overpayment_multiplier' => 2,
        'overpayment_confirmation_minimum' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Weekly Readings
    |--------------------------------------------------------------------------
    |
    | A week's consumption above `max_weekly_kwh` is refused when entered: no
    | subscription uses that much, so the reading was mistyped. One that is at
    | least `unusual_multiplier` times the subscription's usual (the average
    | of its last `usual_readings` readings, once it has `usual_minimum_readings`)
    | and at least `unusual_minimum_kwh` is saved but flagged: it is never
    | approved in bulk, only one by one after the approver confirms it. A
    | subscription with too few readings to have a usual is flagged from
    | `unusual_without_history_kwh` instead.
    |
    */

    'readings' => [
        'max_weekly_kwh' => (float) env('READING_MAX_WEEKLY_KWH', 20000),
        'unusual_multiplier' => 5,
        'unusual_minimum_kwh' => 200,
        'unusual_without_history_kwh' => 2000,
        'usual_readings' => 8,
        'usual_minimum_readings' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Field App Download
    |--------------------------------------------------------------------------
    |
    | Where collectors download the field app. A collector who signs in to the
    | website has little to do there, so the dashboard points them to the app,
    | with this link when it is set.
    |
    */

    'mobile_app_url' => env('MOBILE_APP_URL'),

    /*
    |--------------------------------------------------------------------------
    | Oldest Field App Served
    |--------------------------------------------------------------------------
    |
    | The lowest field app build number (the number after "+" in
    | mobile/pubspec.yaml's version) the mobile API still serves. An older
    | app is told to update, with the download link above. Raise it when a
    | release changes what the API sends or expects; unset or 0 serves every
    | build, including those from before builds were sent.
    |
    */

    'mobile_app_min_build' => (int) env('MOBILE_APP_MIN_BUILD', 0),

    /*
    |--------------------------------------------------------------------------
    | Security Headers
    |--------------------------------------------------------------------------
    |
    | In production the pages are sent with a Content-Security-Policy that
    | lets only the app's own scripts run. `csp` is "enforce" (the default),
    | "report-only" to see what it would block without blocking anything, or
    | "off". It is never sent outside production.
    |
    */

    'security' => [
        'csp' => env('CSP_MODE', 'enforce'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | `backup:run` (daily, from the scheduler) saves the database and the
    | stored files under storage/app/private/backups. `disk` names a
    | filesystem disk that is NOT this server (an S3 bucket, say) where the
    | same files are copied, and `keep_days` how long backups are kept.
    |
    */

    'backup' => [
        'disk' => env('BACKUP_DISK'),
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),
    ],

];
