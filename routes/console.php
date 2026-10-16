<?php

declare(strict_types=1);

use App\Support\Scheduler\OpsSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// §4.9, CD-8: the ops schedule lives in OpsSchedule, so the System page lists it without booting this kernel.
OpsSchedule::define(app(Schedule::class));
