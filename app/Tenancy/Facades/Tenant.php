<?php

declare(strict_types=1);

namespace App\Tenancy\Facades;

use App\Models\Organisation;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Organisation|null current()
 * @method static bool check()
 * @method static void set(Organisation $organisation)
 * @method static void forget()
 * @method static mixed run(Organisation $organisation, Closure $callback)
 * @method static mixed bypass(Closure $callback)
 * @method static bool isBypassed()
 *
 * @see CurrentTenant
 */
final class Tenant extends Facade
{
    /** The scoped instance is replaced between queue jobs; never hold on to an old one. */
    protected static $cached = false;

    protected static function getFacadeAccessor(): string
    {
        return CurrentTenant::class;
    }
}
