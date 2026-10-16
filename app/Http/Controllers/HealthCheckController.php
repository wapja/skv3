<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Health\HealthCheckKey;
use App\Support\Health\HealthReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * GET /health-check (§4.9, RL-5): authorised only by the X-Health-Check-Key header, compared with hash_equals()
 * (HealthCheckKey). A configured key that is blank or shorter than 32 characters always answers 403 (CD-4). Returns
 * the cached health report: 200 when healthy, 503 when a check fails. Registered outside the web group, so it
 * carries no session, cookie, tenant or locale. When the ops store cannot be read (CD-5), the error is reported and
 * the endpoint answers 503 with JSON, never a 500. A store that reads but cannot be written still gets the fresh
 * report (HealthReport::refresh(), L-30).
 */
final readonly class HealthCheckController
{
    public function __invoke(Request $request, HealthReport $report): JsonResponse
    {
        if (! HealthCheckKey::matches($request->header(HealthCheckKey::HEADER))) {
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
