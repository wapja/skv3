<?php

declare(strict_types=1);

/** covers §4.8.9, §4.9.4 */

use App\Models\Activity;
use App\Models\Organisation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('force-deletes entries older than the retention period, archived ones included, and keeps newer ones (R-3)', function (): void {
    config(['activitylog.clean_after_days' => 30]);
    $organisation = Organisation::factory()->create();

    $this->travelTo(now()->subDays(31));
    Activity::factory()->for($organisation)->create();
    Activity::factory()->for($organisation)->create()->delete();
    Activity::factory()->create();
    $this->travelBack();
    $recent = Activity::factory()->for($organisation)->create();

    expect(Artisan::call('activitylog:clean', ['--force' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Deleted 3 record(s)')
        ->and(DB::table('activity_log')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([$recent->id]);
});

it('reads the retention period from the environment as an integer', function (): void {
    // Laravel's env() also reads the process environment (putenv), where Dotenv put the developer's .env
    // values at boot, so that is saved and cleared too.
    $retentionWith = function (?string $value): mixed {
        $saved = [$_ENV['ACTIVITYLOG_RETENTION_DAYS'] ?? null, $_SERVER['ACTIVITYLOG_RETENTION_DAYS'] ?? null];
        $savedPutenv = getenv('ACTIVITYLOG_RETENTION_DAYS');
        unset($_ENV['ACTIVITYLOG_RETENTION_DAYS'], $_SERVER['ACTIVITYLOG_RETENTION_DAYS']);
        putenv('ACTIVITYLOG_RETENTION_DAYS');

        if ($value !== null) {
            $_ENV['ACTIVITYLOG_RETENTION_DAYS'] = $_SERVER['ACTIVITYLOG_RETENTION_DAYS'] = $value;
            putenv('ACTIVITYLOG_RETENTION_DAYS='.$value);
        }

        try {
            /** @var array{clean_after_days: mixed} $config */
            $config = require config_path('activitylog.php');

            return $config['clean_after_days'];
        } finally {
            unset($_ENV['ACTIVITYLOG_RETENTION_DAYS'], $_SERVER['ACTIVITYLOG_RETENTION_DAYS']);

            if ($saved[0] !== null) {
                $_ENV['ACTIVITYLOG_RETENTION_DAYS'] = $saved[0];
            }

            if ($saved[1] !== null) {
                $_SERVER['ACTIVITYLOG_RETENTION_DAYS'] = $saved[1];
            }

            putenv('ACTIVITYLOG_RETENTION_DAYS');

            if ($savedPutenv !== false) {
                putenv('ACTIVITYLOG_RETENTION_DAYS='.$savedPutenv);
            }
        }
    };

    expect($retentionWith('30'))->toBe(30)
        ->and($retentionWith(null))->toBe(365);
});
