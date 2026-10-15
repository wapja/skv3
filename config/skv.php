<?php

declare(strict_types=1);

use App\Support\Skv;

return [

    /*
    | Invitations (§4.5). Links stop working on the domain expiry (expires_in_days, renewed by a
    | reminder); the URL signature lives signature_grace_days longer, so a just-expired link still
    | reaches the friendly "expired" page and only older links get a 403.
    */
    'invitations' => [
        'expires_in_days' => (int) env('INVITATION_EXPIRES_IN_DAYS', 7),
        'signature_grace_days' => 30,
    ],

    /*
    | Localisation (§4.11). The default locale is read once from APP_LOCALE: App::setLocale() rewrites
    | app.locale during a request, so Locale::default() reads this key instead (RL-8).
    */
    'locale' => [
        'default' => env('APP_LOCALE', 'nl'),
    ],

    /*
    | Optional modules (§6.8). Setup writes the MODULE_* keys; re-enabling = change the key and run
    | `php artisan skv:doctor --fix`. Migrations always run whatever the flags say.
    */
    'modules' => [
        'impersonation' => (bool) env('MODULE_IMPERSONATION', true),
        'passkeys' => (bool) env('MODULE_PASSKEYS', true),
        // §6.8: database-only backups need PostgreSQL; on SQLite the module is always off.
        'backups' => Skv::backupsAvailable((string) env('DB_CONNECTION', 'sqlite'), (bool) env('MODULE_BACKUPS', true)),
    ],

    /*
    | /health-check (§4.9, RL-5): authorised only by the X-Health-Check-Key header, compared with hash_equals();
    | a blank key always answers 403. Results are cached for cache_seconds (CD-5).
    */
    'health_check' => [
        'key' => env('HEALTH_CHECK_KEY'),
        'cache_seconds' => 30,
    ],

    /*
    | The cache store of the ops layer (CD-5): the health report, the health-check throttle and the scheduler
    | heartbeat. Not the default database store, so /health-check still answers while the database is down.
    */
    'ops_cache_store' => env('OPS_CACHE_STORE', 'file'),

];
