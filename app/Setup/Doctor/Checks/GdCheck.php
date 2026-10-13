<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;

/** §5.4: ext-gd is present (image handling, logo validation). */
final readonly class GdCheck implements Check
{
    /** @param  bool|null  $loaded  null asks PHP; a test passes the answer */
    public function __construct(private ?bool $loaded = null) {}

    public function key(): string
    {
        return 'gd';
    }

    public function label(): string
    {
        return __('GD extension');
    }

    public function run(DoctorContext $context): CheckResult
    {
        if ($this->loaded ?? extension_loaded('gd')) {
            return CheckResult::pass(__('The gd extension is loaded'));
        }

        return CheckResult::fail(__('The gd extension is missing'), __('Install the gd extension'));
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
