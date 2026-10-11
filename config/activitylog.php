<?php

declare(strict_types=1);

use App\Actions\Audit\CleanActivityLog;
use App\Actions\Audit\LogActivity;
use App\Models\Activity;

/*
| spatie/laravel-activitylog 5.1 (§4.8). Kit entries are written by App\Actions\Audit\RecordActivity only;
| the keys below are the ones the package reads.
*/
return [

    // The audit log cannot be switched off: RecordActivity does not consult this flag.
    'enabled' => true,

    // activitylog:clean removes entries older than this many days (DEC-14).
    'clean_after_days' => (int) env('ACTIVITYLOG_RETENTION_DAYS', 365),

    'default_log_name' => 'default',

    'default_auth_driver' => null,

    'include_soft_deleted_subjects' => false,

    'activity_model' => Activity::class,

    'default_except_attributes' => [],

    // Buffered inserts skip model events and the log action's enrichment; keep it off.
    'buffer' => [
        'enabled' => false,
    ],

    'actions' => [
        'log_activity' => LogActivity::class,
        'clean_log' => CleanActivityLog::class,
    ],

];
