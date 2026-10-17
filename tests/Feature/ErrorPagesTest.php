<?php

declare(strict_types=1);

/** covers §4.9, §6.5 */

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config(['app.debug' => false]);

    Route::middleware('web')->get('_error/{status}', fn (int $status) => abort($status))->whereNumber('status');
    Route::middleware('web')->post('_error/{status}', fn (int $status) => abort($status))->whereNumber('status');
    Route::middleware('web')->get('_error/crash', fn () => throw new RuntimeException('Boom'));
    Route::middleware('web')->get('_error/detail', fn () => abort(403, 'Secret detail'));
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
    '401, from the kit instead of the framework (G3-04)' => ['/_error/401', 401],
    '402, from the kit instead of the framework (G3-04)' => ['/_error/402', 402],
    '405, through the 4xx fallback (G3-04)' => ['/logout', 405],
    '502, through the 5xx fallback (G3-04)' => ['/_error/502', 502],
]);

it('words a status without a page of its own in the visitor\'s language (G3-04)', function (string $path, int $status, string $title): void {
    onApex();

    $this->get($path, ['Accept-Language' => 'nl-NL,nl;q=0.9'])
        ->assertStatus($status)
        ->assertSee('<html lang="nl"', false)
        ->assertSee('<div class="code">'.$status.'</div>', false)
        ->assertSee($title)
        ->assertDontSee('Oops! An Error Occurred');
})->with([
    'GET on a POST-only route' => ['/logout', 405, 'Er klopt iets niet aan dit verzoek'],
    'an unauthorised abort' => ['/_error/401', 401, 'Er klopt iets niet aan dit verzoek'],
    'a payment-required abort' => ['/_error/402', 402, 'Er klopt iets niet aan dit verzoek'],
    'a bad gateway' => ['/_error/502', 502, 'Er ging iets mis'],
]);

it('lets "Try again" reload the same address with its query string, root-relative (G3-05)', function (string $status): void {
    onApex();

    // Root-relative, so a Host header that a misconfigured proxy rewrote never reaches the link.
    $this->get('/_error/'.$status.'?page=2')->assertSee('href="/_error/'.$status.'?page=2"', false);
})->with(['429', '500', '502', '503']);

it('sends the 404 page\'s and the 4xx fallback\'s home link to the root of the same origin', function (): void {
    onApex();

    $this->get('/logout')->assertStatus(405)->assertSee('href="/"', false);
    $this->get('/does-not-exist')->assertNotFound()->assertSee('href="/"', false);
});

it('sends an expired form post back to its page instead of posting the stale token again (G3-05)', function (): void {
    onApex();

    // A reload of a POST resubmits the same expired _token and answers 419 again.
    $this->post('/_error/419', [], ['Referer' => url('/dashboard?page=2')])
        ->assertStatus(419)
        ->assertSee('href="/dashboard?page=2"', false)
        ->assertDontSee('location.reload()', false);
});

it('points "Try again" after a failed POST only at this host (G2-02, G3-05)', function (string $referer): void {
    onApex();

    $this->post('/_error/429', [], ['Referer' => $referer])
        ->assertTooManyRequests()
        ->assertSee('href="/"', false)
        ->assertDontSee('evil.example');
})->with([
    'another site' => ['https://evil.example/phish'],
    'a protocol-relative URL' => ['//evil.example/phish'],
]);

it('shows no exception message on an error page (§6.5)', function (string $path, int $status, string $detail): void {
    onApex();

    $this->get($path)->assertStatus($status)->assertDontSee($detail);
})->with([
    'an exception behind a 500' => ['/_error/crash', 500, 'Boom'],
    'the message of an abort' => ['/_error/detail', 403, 'Secret detail'],
]);

it('never points "Try again" at a POST-only address (G3-05)', function (): void {
    onApex();
    $from = ['Referer' => url('/dashboard?page=2')];

    // The language toggle's own limit: the 31st POST in a minute is refused.
    foreach (range(1, 30) as $attempt) {
        $this->post(route('locale.update'), ['locale' => 'en'], $from)->assertRedirect();
    }

    $this->post(route('locale.update'), ['locale' => 'en'], $from)
        ->assertTooManyRequests()
        ->assertSee('href="/dashboard?page=2"', false)
        ->assertDontSee('href="'.route('locale.update').'"', false);
});

it('reloads the page around Livewire\'s error dialog instead of opening the update address (G3-05)', function (): void {
    onApex();

    $html = (string) $this->post('/_error/500', [], ['X-Livewire' => '1', 'Referer' => url('/dashboard')])
        ->assertStatus(500)
        ->getContent();

    expect($html)->toContain('onclick="window.top.location.reload()"')
        ->not->toContain('href="'.url('/_error/500').'"');
});

it('points the 403 page\'s "Back" only at this host (G2-02)', function (string $referer, string $back): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    $this->actingAs(User::factory()->for($organisation)->create());
    onTenant($organisation);

    $this->get(route('users.index'), ['Referer' => $referer])
        ->assertForbidden()
        ->assertSee('href="'.$back.'"', false)
        ->assertDontSee('evil.example');
})->with([
    'another site' => ['https://evil.example/phish', '/'],
    'this host' => ['http://acme.localhost/dashboard?page=2', '/dashboard?page=2'],
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
