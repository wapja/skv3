<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Models\Organisation;
use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Tenancy\Hosts;
use Closure;

/**
 * §5.4: a wildcard subdomain of the apex resolves (`probe.{apex}`). Without Herd the fix is the /etc/hosts lines for
 * the apex, the admin host and every organisation; with Herd, restarting it restarts the resolver for its TLD.
 */
final readonly class SubdomainsCheck implements Check
{
    /** @var Closure(string): string */
    private Closure $resolve;

    /** @param  (Closure(string): string)|null  $resolve  gethostbyname() unless a test passes another resolver */
    public function __construct(?Closure $resolve = null)
    {
        $this->resolve = $resolve ?? gethostbyname(...);
    }

    public function key(): string
    {
        return 'subdomains';
    }

    public function label(): string
    {
        return __('Subdomains');
    }

    public function run(DoctorContext $context): CheckResult
    {
        $probe = 'probe.'.Hosts::apex();

        if (($this->resolve)($probe) !== $probe) {
            return CheckResult::pass(__(':host resolves', ['host' => $probe]));
        }

        if ($context->detection->herd) {
            return CheckResult::fail(__(':host does not resolve', ['host' => $probe]), 'herd restart');
        }

        return CheckResult::fail(
            __(':host does not resolve; add these lines to /etc/hosts', ['host' => $probe]),
            implode("\n", array_map(fn (string $host): string => '127.0.0.1 '.$host, $this->hosts($context))),
        );
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }

    /** @return list<string> the apex, the admin host and, when the database answers, every organisation's host */
    private function hosts(DoctorContext $context): array
    {
        $slugs = $context->databaseReachable()
            ? Organisation::query()->orderBy('slug')->pluck('slug')->map(fn (mixed $slug): string => (string) $slug)->values()->all()
            : [];

        return [Hosts::apex(), Hosts::admin(), ...array_map(Hosts::tenant(...), $slugs)];
    }
}
