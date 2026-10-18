<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;

/**
 * §5.4: ext-gd is present (image handling, logo validation), and it writes WebP: profile photos are stored as WebP, so
 * a GD without it fails every photo upload with a server error (E3, DEC-35).
 */
final readonly class GdCheck implements Check
{
    /**
     * @param  bool|null  $loaded  null asks PHP; a test passes the answer
     * @param  bool|null  $webp  whether GD supports WebP; null asks GD, a test passes the answer
     */
    public function __construct(private ?bool $loaded = null, private ?bool $webp = null) {}

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
        if (! ($this->loaded ?? extension_loaded('gd'))) {
            return CheckResult::fail(__('The gd extension is missing'), __('Install the gd extension'));
        }

        if (! ($this->webp ?? $this->gdSupportsWebp())) {
            return CheckResult::warn(__('GD has no WebP support: profile photos cannot be saved'), __('Install GD with WebP (libwebp)'));
        }

        return CheckResult::pass(__('The gd extension is loaded'));
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }

    /** Asked only once GD is loaded: imagetypes() and IMG_WEBP come with it. */
    private function gdSupportsWebp(): bool
    {
        return (imagetypes() & IMG_WEBP) !== 0;
    }
}
