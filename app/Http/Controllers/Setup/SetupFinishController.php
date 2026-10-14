<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Setup\SetupContext;
use App\Setup\StepResult;
use App\Setup\Wizard\HerdStage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * §5.3.4, RL-6: the Herd stage's finishing page while the Herd step runs, the manual page after the poll timed out,
 * and otherwise the summary from the marker; HerdStage holds the rules.
 */
final class SetupFinishController extends Controller
{
    public function __invoke(Request $request, HerdStage $stage): Response|View
    {
        $state = $stage->state($request);
        $secret = (string) $request->query('secret');

        if (! $state->result instanceof StepResult && $request->boolean('without_https')) {
            $stage->continueWithoutHttps($state);
        } elseif (! $state->result instanceof StepResult) {
            return $request->boolean('timeout')
                ? view('setup.manual', [
                    'command' => 'herd secure '.app(SetupContext::class)->folderName(),
                    'continueUrl' => route('setup.finish', ['secret' => $secret, 'without_https' => 1], absolute: false),
                    'reloadUrl' => route('setup.finish', ['secret' => $secret], absolute: false),
                ])
                : response()->view('setup.finishing')->header('Refresh', '2');
        }

        return view('setup.finish', [
            'summary' => $stage->summary(),
            'loginUrl' => route('setup.login', ['secret' => $secret], absolute: false),
            'superAdminEmail' => $state->answers->startWith === 'super_admin' ? $state->answers->superAdminEmail : null,
        ]);
    }
}
