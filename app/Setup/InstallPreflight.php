<?php

declare(strict_types=1);

namespace App\Setup;

use App\Models\User;
use App\Setup\Support\EnvFile;
use App\Tenancy\Facades\Tenant;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * The checks skv:install and the wizard run on the answers before anything is written (A15, DEC-30): the installed
 * answers that stand in for the defaults on a re-run, and the refusal of the built-in demo default next to an
 * existing super admin.
 */
final readonly class InstallPreflight
{
    public function __construct(private SetupContext $context) {}

    /**
     * The public answers of an earlier install, from the marker, or none on a first install. A re-run takes every
     * answer it is not given from here instead of from the §5.3.2 defaults, which describe a first install (DEC-30).
     * The MODULE_* keys in .env are the modules' switch (§6.8), so a flag the owner changed there wins over the
     * marker, and over the defaults without one.
     *
     * @return array<string, mixed>
     *
     * @throws JsonException when the marker is not valid JSON
     */
    public function installedAnswers(): array
    {
        $marker = Marker::read($this->context);
        $answers = is_array($marker['answers'] ?? null) ? $marker['answers'] : [];
        $env = new EnvFile($this->context->path('.env'));
        $modules = is_array($answers['modules'] ?? null) ? $answers['modules'] : Answers::MODULES;
        $switched = false;

        foreach (Answers::MODULES as $module) {
            $flag = $env->get('MODULE_'.Str::upper($module));

            if ($flag === null) {
                continue;
            }

            $switched = true;
            $modules = $this->isOn($flag) ? [...$modules, $module] : array_diff($modules, [$module]);
        }

        if ($switched) {
            $answers['modules'] = array_values(array_intersect(Answers::MODULES, $modules));
        }

        return $answers;
    }

    /**
     * Only the built-in default, never a chosen or installed start: demo data would add a super admin whose password
     * everyone knows next to the owner's own (DEC-30 (29)).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $installed
     */
    public function refusesDemoDefault(Answers $answers, array $input, array $installed, Detection $detection): bool
    {
        $demoByDefault = $answers->startWith === 'demo' && ! array_key_exists('start_with', $input) && ! array_key_exists('start_with', $installed);

        return $demoByDefault && $this->ownSuperAdminExists($detection);
    }

    /** A MODULE_* value as config/skv.php reads it: env() casts, then (bool). */
    private function isOn(string $flag): bool
    {
        return ! in_array(Str::lower(trim($flag)), ['', '0', 'false', '(false)', 'empty', '(empty)', 'null', '(null)'], true);
    }

    /** A super admin other than the demo one, in the database the app opens now; none when it cannot be read. */
    private function ownSuperAdminExists(Detection $detection): bool
    {
        $demoSuperAdmins = [DemoSeeder::accounts()[0]['email'], DemoSeeder::accounts($detection->host)[0]['email']];

        try {
            return Tenant::bypass(fn (): bool => User::query()->where('is_super_admin', true)->whereNotIn('email', $demoSuperAdmins)->exists());
        } catch (Throwable) {
            return false;
        }
    }
}
