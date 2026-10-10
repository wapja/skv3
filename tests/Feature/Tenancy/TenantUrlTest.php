<?php

declare(strict_types=1);

/** covers §4.1, §6.2 */

use App\Models\Organisation;
use App\Tenancy\Hosts;
use Illuminate\Http\Request;

it('keeps the scheme and port of the current request', function (): void {
    app()->instance('request', Request::create('https://localhost:8443/anything'));

    expect(tenant_url('acme', '/dashboard'))->toBe('https://acme.localhost:8443/dashboard');
});

it('omits default ports', function (): void {
    app()->instance('request', Request::create('https://localhost/anything'));

    expect(tenant_url('acme'))->toBe('https://acme.localhost/');
});

it('falls back to APP_URL outside a web request', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);

    expect(tenant_url($organisation, 'settings'))->toBe('http://acme.localhost/settings');
});

it('derives the central hosts from the config', function (): void {
    config(['tenancy.apex_domain' => 'Example.test', 'tenancy.admin_subdomain' => 'beheer']);

    expect(Hosts::apex())->toBe('example.test')
        ->and(Hosts::admin())->toBe('beheer.example.test')
        ->and(Hosts::tenant('acme'))->toBe('acme.example.test')
        ->and(Hosts::origin(Hosts::admin()))->toBe('http://beheer.example.test');
});
