<?php

declare(strict_types=1);

namespace App\Setup\Steps;

use App\Setup\SetupContext;
use App\Setup\SetupStep;
use App\Setup\StepResult;
use App\Setup\Support\EnvFile;
use App\Support\Branding;
use Illuminate\Support\Str;

/**
 * §5.3.3 step 4: APP_NAME and the .env-backed branding values (§6.5). An unknown accent becomes neutral, and a logo
 * that is not an image under public/ is cleared (DEC-29).
 */
final class BrandingStep implements SetupStep
{
    public function key(): string
    {
        return 'branding';
    }

    public function label(): string
    {
        return __('Branding');
    }

    public function isComplete(SetupContext $context): bool
    {
        $env = new EnvFile($context->path('.env'));

        if (! $env->exists()) {
            return false;
        }

        foreach ($this->values($context, $env)['variables'] as $key => $value) {
            if ($env->get($key) !== $value) {
                return false;
            }
        }

        return true;
    }

    public function isLong(): bool
    {
        return false;
    }

    public function run(SetupContext $context): StepResult
    {
        $env = new EnvFile($context->path('.env'));
        ['variables' => $variables, 'notes' => $notes] = $this->values($context, $env);

        $env->set($variables);

        return StepResult::done(implode(' ', $notes));
    }

    /** @return array{variables: array{APP_NAME: string, BRANDING_ACCENT: string, BRANDING_LOGO: string}, notes: list<string>} */
    private function values(SetupContext $context, EnvFile $env): array
    {
        $notes = [];
        $accent = Str::lower(trim((string) $env->get('BRANDING_ACCENT')));
        $logo = trim((string) $env->get('BRANDING_LOGO'));

        if ($accent === '') {
            $accent = 'neutral';
        } elseif (! in_array($accent, Branding::ACCENTS, true)) {
            $notes[] = __('Unknown accent :accent; using neutral.', ['accent' => $accent]);
            $accent = 'neutral';
        }

        if ($logo !== '' && ! Branding::isValidLogoPath($logo, $context->path('public'))) {
            $notes[] = __('The logo must be a file under public/; it was cleared.');
            $logo = '';
        }

        return [
            'variables' => ['APP_NAME' => $context->answers->appName, 'BRANDING_ACCENT' => $accent, 'BRANDING_LOGO' => $logo],
            'notes' => $notes,
        ];
    }
}
