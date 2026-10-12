<?php

declare(strict_types=1);

/** covers §8 */

use Illuminate\Support\Str;

it('keeps CI in tests.yml with matrix PHP versions only', function (): void {
    $ci = file_get_contents(base_path('.github/workflows/tests.yml'));

    expect($ci)->toContain('php-version: ${{ matrix.php }}')
        ->and(preg_match("/php-version:\s*'\d+\.\d+'/", (string) $ci))->toBe(0)
        ->and($ci)->not->toContain('./vendor/bin/phpunit')
        ->and($ci)->toContain('postgres:18')
        ->and($ci)->toContain('composer check')
        ->and(file_exists(base_path('.github/workflows/ci.yml')))->toBeFalse();
});

it('never references Flux Pro in the committed composer files', function (): void {
    expect(file_get_contents(base_path('composer.json')))->not->toContain('flux-pro')
        ->and(file_get_contents(base_path('composer.lock')))->not->toContain('flux-pro');
});

it('reports abandoned packages in composer audit instead of failing', function (): void {
    /** @var array{config: array{audit?: array{abandoned?: string}}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['config']['audit']['abandoned'] ?? null)->toBe('report');
});

it('caches the Composer cache dir reported by Composer itself', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    expect($ci)->toContain('composer config cache-files-dir')
        ->and($ci)->toContain('${{ steps.composer-cache.outputs.dir }}')
        ->and($ci)->not->toContain('~/.composer/cache');
});

it('keeps Dependabot on actions, composer and npm', function (): void {
    $path = base_path('.github/dependabot.yml');

    expect(is_file($path))->toBeTrue();

    $dependabot = (string) file_get_contents($path);

    foreach (['github-actions', 'composer', 'npm'] as $ecosystem) {
        expect($dependabot)->toContain(sprintf('package-ecosystem: "%s"', $ecosystem));
    }
});

it('runs the sqlite-free job with the impersonation module off (IMP-12)', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    expect(Str::between($ci, '- name: sqlite-free', '- name: php85'))->toContain("impersonation: 'false'")
        ->and(Str::between($ci, '- name: main', '- name: sqlite-free'))->toContain("impersonation: 'true'")
        ->and(Str::between($ci, '- name: php85', 'services:'))->toContain("impersonation: 'true'")
        ->and($ci)->toContain('MODULE_IMPERSONATION: ${{ matrix.impersonation }}');
});

it('runs the free job with the passkeys module off (§6.8)', function (): void {
    $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    expect($workflow)->toContain('MODULE_PASSKEYS: ${{ matrix.passkeys }}')
        ->and(preg_match("/name: sqlite-free.*?passkeys: 'false'/s", $workflow))->toBe(1);
});

it('lets the environment turn the module off in tests and pins the test hosts (IMP-12, FU2-9, FU3-17)', function (): void {
    $phpunit = (string) file_get_contents(base_path('phpunit.xml'));

    expect($phpunit)
        ->toContain('<env name="MODULE_IMPERSONATION" value="true"/>')
        ->toContain('<env name="APP_URL" value="http://localhost" force="true"/>')
        ->toContain('<env name="TENANCY_APEX_DOMAIN" value="localhost" force="true"/>')
        ->toContain('<env name="TENANCY_ADMIN_SUBDOMAIN" value="admin" force="true"/>')
        ->toContain('<env name="TENANCY_STRICT" value="true" force="true"/>')
        ->toContain('<env name="PASSKEYS_RELYING_PARTY_ID" value=""/>')
        ->toContain('<env name="TRUSTED_PROXIES" value=""/>');
});

it('runs the browser suite in its own CI job (§8)', function (): void {
    $workflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    expect($workflow)->toContain('npx playwright install --with-deps chromium')
        ->toContain('composer test:browser')
        ->toContain('tests/Browser/Traces')
        ->and(preg_match('/uses: actions\/upload-artifact@[0-9a-f]{40}/', $workflow))->toBe(1);
});
