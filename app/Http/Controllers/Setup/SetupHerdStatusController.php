<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Setup\StepResult;
use App\Setup\Wizard\HerdStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** §5.3.4, RL-6: the Herd stage's status, which the wizard's browser polls; HerdStage holds the rules. */
final class SetupHerdStatusController extends Controller
{
    public function __invoke(Request $request, HerdStage $stage): JsonResponse
    {
        return response()->json(['state' => $stage->state($request)->result instanceof StepResult ? 'done' : 'running']);
    }
}
