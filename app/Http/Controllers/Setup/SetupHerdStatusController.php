<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Setup\StepResult;
use App\Setup\Wizard\HerdStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §5.3.4, RL-6: the Herd stage's status, which the wizard's browser polls: done once the Herd step has a result,
 * timeout once that answer is overdue (the page then asks the finish page, which confirms with Herd), else running.
 * HerdStage holds the rules.
 */
final class SetupHerdStatusController extends Controller
{
    public function __invoke(Request $request, HerdStage $stage): JsonResponse
    {
        $state = $stage->state($request);

        return response()->json(['state' => match (true) {
            $state->result instanceof StepResult => 'done',
            $stage->isOverdue($state) => 'timeout',
            default => 'running',
        }]);
    }
}
