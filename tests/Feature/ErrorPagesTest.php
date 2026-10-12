<?php

declare(strict_types=1);

/** covers §4.9, §6.5 */

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config(['app.debug' => false]);

    Route::middleware('web')->get('_error/{status}', fn (int $status) => abort($status))->whereNumber('status');
    Route::middleware('web')->get('_error/crash', fn () => throw new RuntimeException('Boom'));
});

it('renders every error page without Vite, Flux or Livewire, with a dark mode (§6.5)', function (string $path, int $status): void {
    onApex();

    $response = $this->get($path)->assertStatus($status);
    $html = (string) $response->getContent();

    expect($html)->toContain('<style>')
        ->toContain('prefers-color-scheme: dark')
        ->not->toContain('/build/')
        ->not->toContain('@vite')
        ->not->toContain('livewire')
        ->not->toContain('flux');
})->with([
    '403' => ['/_error/403', 403],
    '404' => ['/does-not-exist', 404],
    '419' => ['/_error/419', 419],
    '429' => ['/_error/429', 429],
    '500' => ['/_error/crash', 500],
    '503' => ['/_error/503', 503],
]);

it('speaks the visitor\'s language on an unknown route, where no web middleware runs', function (): void {
    onApex();

    $this->get('/does-not-exist', ['Accept-Language' => 'nl-NL,nl;q=0.9'])
        ->assertNotFound()
        ->assertSee('<html lang="nl"', false)
        ->assertSee('Pagina niet gevonden');
});

it('speaks the signed-in user\'s language on a 403', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $this->actingAs(User::factory()->for($organisation)->create(['locale' => 'nl']));
    onTenant($organisation);

    $this->get(route('users.index'))->assertForbidden()->assertSee('<html lang="nl"', false);
});

it('offers contextual actions (§6.5)', function (): void {
    onApex();
    $this->get('/_error/419')->assertSee('location.reload()', false)->assertSee(route('login'), false);
    $this->get('/_error/403')->assertSee(route('login'), false);

    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($organisation)->create();
    $this->actingAs($member);
    onTenant($organisation);
    $this->get(route('users.index'))->assertForbidden()->assertSee(tenant_url($organisation), false);
});
