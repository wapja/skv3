<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (): RedirectResponse => to_route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth'])->group(function (): void {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
