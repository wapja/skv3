<?php

declare(strict_types=1);

/** covers §4.1, §6.2 */

use App\Models\Organisation;
use App\Tenancy\Hosts;
use Illuminate\Http\Request;

it('takes the scheme and port of APP_URL, not of the current request (CD-4, G1-04)', function (): void {
    config(['app.url' => 'https://localhost:8443']);
    app()->instance('request', Request::create('http://localhost:6666/anything'));

    expect(tenant_url('acme', '/dashboard'))->toBe('https://acme.localhost:8443/dashboard');
});

it('omits APP_URL\'s default port (CD-4)', function (string $appUrl, string $expected): void {
    config(['app.url' => $appUrl]);
    app()->instance('request', Request::create('https://localhost:8443/anything'));

    expect(tenant_url('acme'))->toBe($expected);
})->with([
    'https' => ['https://localhost', 'https://acme.localhost/'],
    'https on 443' => ['https://localhost:443', 'https://acme.localhost/'],
    'http on 80' => ['http://localhost:80/', 'http://acme.localhost/'],
]);

it('uses APP_URL outside a web request', function (): void {
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
