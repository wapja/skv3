<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;

/** §5.4: the kit needs PHP 8.4 or newer (composer.json: php ^8.4). */
final readonly class PhpCheck implements Check
{
    /** @param  string  $version  the version to judge; the running PHP unless a test passes another */
    public function __construct(private string $version = PHP_VERSION) {}

    public function key(): string
    {
        return 'php';
    }

    public function label(): string
    {
        return __('PHP version');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (version_compare($this->version, '8.4.0', '>=')) {
            return CheckResult::pass(__('PHP :version', ['version' => $this->version]));
        }

        return CheckResult::fail(__('PHP :version is too old; the kit needs 8.4 or newer', ['version' => $this->version]), 'herd isolate 8.4');
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
