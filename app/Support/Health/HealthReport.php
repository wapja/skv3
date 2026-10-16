<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\Checks\DatabaseCheck;
use App\Setup\Doctor\Checks\FailedJobsCheck;
use App\Setup\Doctor\Checks\LastBackupCheck;
use App\Setup\Doctor\Checks\MailCheck;
use App\Setup\Doctor\Checks\QueueCheck;
use App\Setup\Doctor\Doctor;
use App\Setup\Doctor\DoctorContext;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * The health results of §4.9 (RL-1, RL-3): the doctor's database, queue, failed-jobs and mail checks plus the last
 * backup, run against a runtime context without detection (RL-2, CD-5) and cached briefly on the ops store
 * (OpsCache), once per locale because labels and messages are translated when it runs. /health-check and the System
 * page show the same report. It leaves the server, so it carries no fix commands. Its messages stay: the mail row
 * names the SMTP host and port, which only a holder of the key or a super admin sees (DEC-32 RL-5; O2 kept, CD-2).
 *
 * @phpstan-type Report array{checked_at: string, healthy: bool, checks: array<string, array{label: string, status: string, message: string}>}
 */
final readonly class HealthReport
{
    public const string CACHE_KEY = 'health:report';

    /**
     * @param  list<Check>|null  $checks  the checks to run; null runs the kit's (tests pass their own)
     * @param  string|null  $basePath  the project whose .env the checks read; tests pass a sandbox
     */
    public function __construct(private ?array $checks = null, private ?string $basePath = null) {}

    /** @return list<Check> */
    public function checks(): array
    {
        return $this->checks ?? [new DatabaseCheck, new QueueCheck, new FailedJobsCheck, new MailCheck, new LastBackupCheck];
    }

    /** @return Report */
    public function run(): array
    {
        $checks = $this->checks();
        $results = new Doctor($checks)->run(DoctorContext::withoutDetection($this->basePath));
        $rows = [];

        foreach ($checks as $check) {
            $result = $results[$check->key()];
            $rows[$check->key()] = ['label' => $check->label(), 'status' => $result->status->value, 'message' => $result->message];
        }

        return [
            'checked_at' => Date::now()->toIso8601String(),
            'healthy' => Doctor::isGreen($results),
            'checks' => $rows,
        ];
    }

    /** @return Report the cached report, or a fresh one that is then cached; throws when the store cannot be read */
    public function cached(): array
    {
        $cached = OpsCache::store()->get($this->key());

        if (is_array($cached) && isset($cached['checked_at'], $cached['healthy'], $cached['checks'])) {
            /** @var Report $cached */
            return $cached;
        }

        return $this->refresh();
    }

    /**
     * @return Report a fresh report, cached for the next requests. A store that cannot be written (a full disk, a
     *                read-only replica) is reported and the report still returned (L-30), so key holders get the
     *                real 200 or 503; the checks then run on each such request.
     */
    public function refresh(): array
    {
        $report = $this->run();

        try {
            OpsCache::store()->put($this->key(), $report, max(1, (int) config('skv.health_check.cache_seconds', 30)));
        } catch (Throwable $exception) {
            report($exception);
        }

        return $report;
    }

    /** The cache key of the current locale's report: labels and messages are translated when the report runs. */
    public function key(): string
    {
        return self::CACHE_KEY.':'.app()->getLocale();
    }
}
