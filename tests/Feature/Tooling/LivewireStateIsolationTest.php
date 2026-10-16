<?php

declare(strict_types=1);

/** covers §7.2 */

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

/*
| Livewire keeps `@assets` it rendered in static properties until a new request cycle flushes them. A php-fpm worker
| starts every request fresh, but a test process renders many requests, so the assets of one test (the passkey
| script on the login page) were injected into the next test's HTML. These two tests run in this order, in one process:
| the second depends on the first, so run alone (--filter, another order) it is skipped instead of passing vacuously.
*/

it('registers an asset the way a page with @assets outside a Livewire component does', function (): void {
    Blade::render('@assets<script src="/build/leaked-asset.js"></script>@endassets');

    Route::get('/livewire-isolation-probe', fn (): string => '<!DOCTYPE html><html><head></head><body></body></html>');

    expect($this->get('/livewire-isolation-probe')->assertOk()->getContent())->toContain('/build/leaked-asset.js');
});

it('starts every test without the assets an earlier test rendered', function (): void {
    Route::get('/livewire-isolation-probe', fn (): string => '<!DOCTYPE html><html><head></head><body></body></html>');

    expect($this->get('/livewire-isolation-probe')->assertOk()->getContent())->not->toContain('/build/leaked-asset.js');
})->depends('it registers an asset the way a page with @assets outside a Livewire component does');
