<?php

declare(strict_types=1);

namespace App\Setup\Steps;

use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\SetupToken;
use App\Setup\StepResult;
use App\Setup\Summary;
use App\Setup\Wizard\WizardState;
use Illuminate\Support\Facades\File;

/**
 * §5.3.3 step 9: writes the marker with the summary so far (RL-14) and removes the setup token, the wizard state and
 * the detached steps' files, which only matter while setup is unfinished. A symlinked step directory is removed,
 * never followed (RL-5).
 */
final readonly class MarkerStep implements SetupStep
{
    /** The setup state that goes once the marker exists. */
    private const array STATE_FILES = [SetupToken::PATH, WizardState::PATH];

    public function key(): string
    {
        return 'marker';
    }

    public function label(): string
    {
        return __('Finish');
    }

    public function isComplete(SetupContext $context): bool
    {
        return Marker::exists($context);
    }

    public function isLong(): bool
    {
        return false;
    }

    public function run(SetupContext $context): StepResult
    {
        Marker::write($context, Summary::build($context));

        foreach (self::STATE_FILES as $file) {
            File::delete($context->path($file));
        }

        WizardState::removeDirectory($context->path(WizardState::STEPS));

        return StepResult::done();
    }
}
