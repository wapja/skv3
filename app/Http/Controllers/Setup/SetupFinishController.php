<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Setup\StepResult;
use App\Setup\Wizard\HerdStage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * §5.3.4, RL-6: the Herd stage's finishing page while the Herd step runs, and the summary from the marker once it
 * has a result. When the answer is overdue (the page's poll timed out, the step's process exited without a result,
 * or the poll's 60 seconds are over) the server confirms with Herd itself, and offers the manual command while Herd
 * confirms nothing; so the finishing page never refreshes past the poll window. HerdStage holds the rules.
 */
final class SetupFinishController extends Controller
{
    public function __invoke(Request $request, HerdStage $stage): Response|View
    {
        $secret = (string) $request->query('secret');
        $state = $stage->state($request);

        if (! $state->result instanceof StepResult && $request->boolean('without_https')) {
            $stage->continueWithoutHttps($state);
        } elseif (! $state->result instanceof StepResult) {
            if (! $request->boolean('timeout') && ! $stage->isOverdue($state)) {
                return response()->view('setup.finishing')->header('Refresh', '2');
            }

            if (! $stage->confirm($state) instanceof StepResult) {
                return view('setup.manual', [
                    'command' => $stage->manualCommand($state),
                    'https' => $state->answers->https,
                    'continueUrl' => route('setup.finish', ['secret' => $secret, 'without_https' => 1], absolute: false),
                    'reloadUrl' => route('setup.finish', ['secret' => $secret], absolute: false),
                ]);
            }
        }

        return view('setup.finish', [
            'summary' => $stage->summary(),
            'loginUrl' => route('setup.login', ['secret' => $secret], absolute: false),
            'superAdminEmail' => $state->answers->startWith === 'super_admin' ? $state->answers->superAdminEmail : null,
        ]);
    }
}
