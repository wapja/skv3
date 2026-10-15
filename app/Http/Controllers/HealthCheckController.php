<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Health\HealthReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * GET /health-check (§4.9, RL-5): authorised only by the X-Health-Check-Key header, compared with hash_equals();
 * a blank configured key always answers 403. Returns the cached health report: 200 when healthy, 503 when a check
 * fails. Registered outside the web group, so it carries no session, cookie, tenant or locale. When the report cannot
 * be read or cached (a broken ops store, CD-5), the error is reported and the endpoint answers 503 with JSON, never a
 * 500; it does not run the checks uncached, because the throttle shares that store and is then off as well.
 */
final readonly class HealthCheckController
{
    public function __invoke(Request $request, HealthReport $report): JsonResponse
    {
        $key = config('skv.health_check.key');
        $given = $request->header('X-Health-Check-Key');

        // env() turns HEALTH_CHECK_KEY=true into bool true: only a non-empty string is a key, never '1'.
        if (! is_string($key) || $key === '' || ! is_string($given) || ! hash_equals($key, $given)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403, ['Cache-Control' => 'no-store']);
        }

        try {
            $result = $report->cached();
        } catch (Throwable $exception) {
            report($exception);

            return new JsonResponse(['healthy' => false, 'message' => 'The health report is unavailable.'], 503, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse($result, $result['healthy'] ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}
