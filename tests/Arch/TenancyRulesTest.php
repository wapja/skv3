<?php

declare(strict_types=1);

/** covers §6.2, §7.2 */

use Illuminate\Support\Str;

it('bypasses or removes the tenant scope only in the allowed places', function (): void {
    // §6.2 / DEC-28 (8): Livewire pages never join this list; any-host pages read through App\Tenancy\HostScope::read().
    $allowed = [
        'app/Console/', 'app/Setup/', 'app/Tenancy/', 'app/Actions/Audit/', 'database/seeders/',
        'app/Http/Controllers/HealthCheckController.php',
    ];

    $candidates = array_values(array_filter(
        kitSourceFiles('app', 'routes', 'resources/views', 'database'),
        fn (string $path): bool => ! Str::startsWith($path, $allowed),
    ));

    // Removing TenantScope through Eloquent (by class, by name, by instance, through an aliased import, or by
    // building a query without global scopes) reads across organisations just like a bypass, and suspending or
    // resuming one changes the bypass state directly.
    $pattern = '/Tenant::bypass\s*\(|->bypass\s*\(|\b(suspend|resume)Bypass\s*\('
        .'|withoutGlobalScope\s*\(\s*(\\\\?[\w\\\\]*\\\\)?TenantScope::class'
        .'|withoutGlobalScope\s*\(\s*[\'"](\\\\?[\w\\\\]*\\\\)?TenantScope[\'"]'
        .'|withoutGlobalScope\s*\(\s*new\s+(\\\\?[\w\\\\]*\\\\)?TenantScope\b'
        .'|withoutGlobalScopes\s*\(|newQueryWithoutScopes\s*\(|newModelQuery\s*\('
        .'|use\s+\\\\?App\\\\Tenancy\\\\Scopes\\\\TenantScope\s+as\s/';

    // FU3-16: the rule recognises every form it names, so it can fail.
    $forms = [
        'Tenant::bypass(fn () => 1);',
        '$tenancy->bypass(fn () => 1);',
        '$tenancy->suspendBypass();',
        'User::withoutGlobalScope(TenantScope::class);',
        'User::withoutGlobalScope(\App\Tenancy\Scopes\TenantScope::class);',
        "User::withoutGlobalScope('TenantScope');",
        'User::withoutGlobalScope(new TenantScope);',
        'User::withoutGlobalScopes();',
        '(new User)->newQueryWithoutScopes();',
        '(new User)->newModelQuery();',
        'use App\Tenancy\Scopes\TenantScope as Scope;',
    ];

    foreach ($forms as $form) {
        expect(preg_match($pattern, $form))->toBe(1, "The bypass rule misses [{$form}].");
    }

    expect(filesMatching($candidates, $pattern))->toBe([]);
});

it('keeps driver-specific SQL out of the app', function (): void {
    expect(filesMatching(kitSourceFiles('app', 'resources/views'), '/\bilike\b/i'))->toBe([]);
});

it('keeps raw like operators out of the app (FU2-10)', function (): void {
    // §7.2: no driver-specific SQL. Search with whereLike(..., caseSensitive: false); a quoted like operator
    // or LIKE inside raw SQL behaves differently on PostgreSQL and SQLite.
    $pattern = '/[\'"]\s*(not\s+)?i?like\s*[\'"]'
        .'|\b(where|orWhere|having|orHaving|select|orderBy)Raw\s*\([^;]*\blike\b'
        .'|DB::(raw|statement|select|unprepared)\s*\([^;]*\blike\b/i';

    $forms = [
        "->where('name', 'like', \$term)",
        "->where('name', 'not like', \$term)",
        '->orWhere("email", "ILIKE", $term)',
        "->whereRaw('name like ?', [\$term])",
        "DB::select('select * from users where name LIKE ?')",
    ];

    foreach ($forms as $form) {
        expect(preg_match($pattern, $form))->toBe(1, "The like rule misses [{$form}].");
    }

    expect(preg_match($pattern, "->whereLike('name', \$term, caseSensitive: false)"))->toBe(0)
        ->and(filesMatching(kitSourceFiles('app', 'resources/views', 'routes'), $pattern))->toBe([]);
});
