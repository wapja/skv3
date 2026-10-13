<?php

declare(strict_types=1);

namespace App\Setup\Doctor;

use App\Enums\CheckStatus;
use App\Setup\Doctor\Checks\ApexCheck;
use App\Setup\Doctor\Checks\AppKeyCheck;
use App\Setup\Doctor\Checks\AssetsCheck;
use App\Setup\Doctor\Checks\AuthenticateSessionCheck;
use App\Setup\Doctor\Checks\ClaudeFilesCheck;
use App\Setup\Doctor\Checks\DatabaseCheck;
use App\Setup\Doctor\Checks\FluxProCredentialsCheck;
use App\Setup\Doctor\Checks\GdCheck;
use App\Setup\Doctor\Checks\GitHooksCheck;
use App\Setup\Doctor\Checks\HealthCheckKeyCheck;
use App\Setup\Doctor\Checks\HerdCheck;
use App\Setup\Doctor\Checks\HerdProCheck;
use App\Setup\Doctor\Checks\HerdSecuredCheck;
use App\Setup\Doctor\Checks\HerdSiteCheck;
use App\Setup\Doctor\Checks\MailCheck;
use App\Setup\Doctor\Checks\McpCheck;
use App\Setup\Doctor\Checks\MigrationsCheck;
use App\Setup\Doctor\Checks\NodeCheck;
use App\Setup\Doctor\Checks\OrganisationAdminRoleCheck;
use App\Setup\Doctor\Checks\PermissionsCheck;
use App\Setup\Doctor\Checks\PgDumpCheck;
use App\Setup\Doctor\Checks\PhpCheck;
use App\Setup\Doctor\Checks\PlaywrightCheck;
use App\Setup\Doctor\Checks\QueueCheck;
use App\Setup\Doctor\Checks\SchemaToolsCheck;
use App\Setup\Doctor\Checks\SessionDriverCheck;
use App\Setup\Doctor\Checks\SetupTokenCheck;
use App\Setup\Doctor\Checks\StorageLinkCheck;
use App\Setup\Doctor\Checks\SubdomainsCheck;
use App\Setup\Doctor\Checks\SuperAdminBadgesCheck;
use App\Setup\Doctor\Checks\SuperAdminCheck;
use App\Setup\Doctor\Checks\SuperpowersCheck;
use App\Setup\Doctor\Checks\TestDatabaseCheck;
use Throwable;

/**
 * Runs the checks of §5.4 in report order (RL-11). With $fix, every check that warns or fails and has a safe repair
 * runs it, and its re-run result replaces the first one. A check that throws is reported as failed, so one broken
 * check never hides the others; the exception message is not shown, because it may hold SQL bindings.
 */
final readonly class Doctor
{
    /** @param  list<Check>|null  $checks  the checks to run; null runs the kit's own (tests pass their own) */
    public function __construct(private ?array $checks = null) {}

    /** @param  array<string, CheckResult>  $results */
    public static function isGreen(array $results): bool
    {
        return array_all($results, fn (CheckResult $result): bool => $result->status !== CheckStatus::Fail);
    }

    /** @return list<Check> in report order */
    public function checks(): array
    {
        return $this->checks ?? [
            new PhpCheck,
            new GdCheck,
            new AppKeyCheck,
            new HerdCheck,
            new HerdProCheck,
            new HerdSiteCheck,
            new HerdSecuredCheck,
            new SubdomainsCheck,
            new ApexCheck,
            new DatabaseCheck,
            new MigrationsCheck,
            new TestDatabaseCheck,
            new SchemaToolsCheck,
            new PermissionsCheck,
            new OrganisationAdminRoleCheck,
            new SuperAdminCheck,
            new SuperAdminBadgesCheck,
            new SessionDriverCheck,
            new AuthenticateSessionCheck,
            new HealthCheckKeyCheck,
            new NodeCheck,
            new AssetsCheck,
            new MailCheck,
            new PgDumpCheck,
            new FluxProCredentialsCheck,
            new QueueCheck,
            new GitHooksCheck,
            new PlaywrightCheck,
            new ClaudeFilesCheck,
            new McpCheck,
            new SuperpowersCheck,
            new SetupTokenCheck,
            new StorageLinkCheck,
        ];
    }

    /** @return array<string, CheckResult> keyed by check key */
    public function run(DoctorContext $context, bool $fix = false): array
    {
        $context->forgetSync();
        $results = [];

        foreach ($this->checks() as $check) {
            try {
                $result = $check->run($context);
            } catch (Throwable $exception) {
                $result = CheckResult::fail(__('The check could not run (:exception).', ['exception' => class_basename($exception)]));
            }

            if ($fix && in_array($result->status, [CheckStatus::Warn, CheckStatus::Fail], true)) {
                try {
                    $fixed = $check->fix($context);
                } catch (Throwable) {
                    // A repair that throws leaves the first result, with its fix command, in the report.
                    $fixed = null;
                }

                if ($fixed !== null) {
                    $result = $fixed;
                    // The next check must see the repair, not the sync result cached before it.
                    $context->forgetSync();
                }
            }

            $results[$check->key()] = $result;
        }

        return $results;
    }
}
