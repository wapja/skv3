<?php

declare(strict_types=1);

/** covers §4.1, §6.2 */

use App\Authorization\PermissionsTeam;
use App\Models\Organisation;
use App\Tenancy\Facades\Tenant;
use App\Tenancy\Hosts;
use App\Tenancy\Resolvers\SubdomainResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware('web')->get('/_probe', fn (): array => [
        'tenant' => Tenant::current()?->slug,
        'team' => PermissionsTeam::current(),
    ]);
});

it('resolves the organisation from the subdomain and sets the team', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);

    $this->getJson(tenant_url($organisation, '/_probe'))
        ->assertOk()
        ->assertExactJson(['tenant' => 'acme', 'team' => $organisation->id]);
});

it('treats the apex and the admin host as central', function (string $host): void {
    Organisation::factory()->create(['slug' => 'admin']);

    $this->getJson(Hosts::url($host === 'apex' ? Hosts::apex() : Hosts::admin(), '/_probe'))
        ->assertOk()
        ->assertExactJson(['tenant' => null, 'team' => null]);
})->with(['apex', 'admin']);

it('forgets a previously bound tenant on a central host', function (): void {
    $organisation = Organisation::factory()->create();
    $this->getJson(tenant_url($organisation, '/_probe'))->assertOk();

    $this->getJson(Hosts::url(Hosts::apex(), '/_probe'))->assertExactJson(['tenant' => null, 'team' => null]);
});

it('shows a friendly 404 with a link to the apex for unknown hosts', function (string $url): void {
    Organisation::factory()->create(['slug' => 'www']);
    Organisation::factory()->create(['slug' => 'archived'])->delete();

    $this->get($url)
        ->assertNotFound()
        ->assertSee('Organisation not found')
        ->assertSee('href="http://localhost/"', false);
})->with([
    'unknown slug' => 'http://nobody.localhost/_probe',
    'archived organisation' => 'http://archived.localhost/_probe',
    'reserved slug' => 'http://www.localhost/_probe',
    'nested subdomain' => 'http://a.b.localhost/_probe',
    'too short' => 'http://ab.localhost/_probe',
    'foreign host' => 'http://example.com/_probe',
]);

it('caches the slug lookup and forgets it on rename', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'before']);
    $this->getJson(tenant_url('before', '/_probe'))->assertOk();
    expect(Cache::get(SubdomainResolver::cacheKey('before')))->toBe($organisation->id);

    $organisation->update(['slug' => 'after']);

    expect(Cache::has(SubdomainResolver::cacheKey('before')))->toBeFalse();
    $this->get(tenant_url('before', '/_probe'))->assertNotFound();
    $this->getJson(tenant_url('after', '/_probe'))->assertExactJson(['tenant' => 'after', 'team' => $organisation->id]);
});

it('never binds a stale cached id to another slug', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'after']);
    Cache::put(SubdomainResolver::cacheKey('before'), $organisation->id, 60);

    $this->get(tenant_url('before', '/_probe'))->assertNotFound();
});

it('does not cache unknown slugs', function (): void {
    $this->get(tenant_url('nobody', '/_probe'))->assertNotFound();

    expect(Cache::has(SubdomainResolver::cacheKey('nobody')))->toBeFalse();
});

it('forgets the cached slug when the organisation is archived', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'gone']);
    $this->getJson(tenant_url('gone', '/_probe'))->assertOk();

    $organisation->delete();

    $this->get(tenant_url('gone', '/_probe'))->assertNotFound();
});
