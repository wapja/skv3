<?php

declare(strict_types=1);

use App\Authorization\Permission;
use Illuminate\Support\Facades\Route;

/*
| Administration pages (§4.3–§4.8). Shared routes keep one name and answer on every host; on central
| hosts only super admins pass (VerifyTenantMembership). Single-host pages are grouped with
| Route::tenant() / Route::central(), never Route::domain().
*/

Route::middleware(['auth'])->group(function (): void {
    // Any host: on central hosts only super admins pass; the page reads users through HostScope (RL-8).
    Route::livewire('users', 'pages::users.index')->can(Permission::UsersView)->name('users.index');

    // Doubles as the show page (users.view); the page re-reads the user through HostScope, never a bound model (R-7).
    Route::livewire('users/{user}/edit', 'pages::users.edit')
        ->whereNumber('user')
        ->can(Permission::UsersView)
        ->name('users.edit');
});

// Signed routes that guests open (invitations.accept, Task 11) go below, outside the auth group.
