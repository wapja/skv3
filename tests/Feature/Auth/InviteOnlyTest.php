<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

it('has no registration', function (): void {
    expect(Route::has('register'))->toBeFalse();
    $this->get('/register')->assertNotFound();
});

it('has no email verification', function (): void {
    expect(Route::has('verification.notice'))->toBeFalse()
        ->and(Route::has('verification.verify'))->toBeFalse();
});

it('offers no self-service account deletion', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('delete-user', escape: false);

    $deleteViews = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file): bool => str_contains($file->getPathname(), 'delete-user'));

    expect($deleteViews)->toBeEmpty();
});

it('redirects the root url to the dashboard', function (): void {
    $this->get('/')->assertRedirect('/dashboard');
});

it('sends guests from the dashboard to the login page', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
