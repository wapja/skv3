<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Setup\StepResult;
use App\Setup\Wizard\HerdStage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** §5.3.4, RL-6: the Herd stage's single-use login once the Herd step has a result; HerdStage holds the rules. */
final class SetupLoginController extends Controller
{
    public function __invoke(Request $request, HerdStage $stage): RedirectResponse
    {
        $state = $stage->state($request);
        abort_if(! $state->result instanceof StepResult, 404);

        return redirect()->away($stage->loginUrl($state));
    }
}
