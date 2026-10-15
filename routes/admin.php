<?php

declare(strict_types=1);

use App\Authorization\Permission;
use App\Http\Middleware\SetReferrerPolicy;
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

    // §4.5: any host. A tenant host invites into its own organisation; on a central host a super admin picks one.
    Route::livewire('users/invite', 'pages::users.invite')
        ->can(Permission::UsersCreate)
        ->name('users.invite');

    Route::livewire('invitations/pending', 'pages::invitations.pending')
        ->can(Permission::UsersView)
        ->name('invitations.pending');

    // §4.3, §6.2: any host. A tenant host shows its own organisation; on central hosts only super admins get here.
    Route::livewire('organisations', 'pages::organisations.index')
        ->can(Permission::OrganisationsView)
        ->name('organisations.index');

    // §4.3: the wizard is a platform action, on the apex and the admin host only (a tenant host answers 404).
    Route::central(function (): void {
        Route::livewire('organisations/create', 'pages::organisations.create')
            ->can(Permission::OrganisationsCreate)
            ->name('organisations.create');

        // §6.5: the System page, platform-wide, super admins only (system.health is super-only).
        Route::livewire('system', 'pages::system.index')
            ->can(Permission::SystemHealth)
            ->name('system.index');
    });

    // Any host; the page resolves the id per request inside the host's scope (OR-M5).
    Route::livewire('organisations/{organisation}/edit', 'pages::organisations.edit')
        ->whereNumber('organisation')
        ->can(Permission::OrganisationsUpdate)
        ->name('organisations.edit');

    // §4.6: any host. A tenant host shows its own team's roles; on central hosts only super admins get here.
    Route::livewire('roles', 'pages::roles.index')->can(Permission::RolesView)->name('roles.index');

    // §4.8: any host. A tenant host shows its own organisation's entries; on central hosts a super admin sees all.
    Route::livewire('activity', 'pages::activity.index')->can(Permission::ActivityLogView)->name('activity.index');
});

// §4.5 / §6.2: guests open the accept link, so it lives outside the auth group, on tenant hosts only.
// The signature is relative (it survives scheme and host rewrites); the page looks the token up under the
// tenant scope, so another host answers 404 (W14). The token is in the URL, so the page sends no referrer (RK-M12).
Route::tenant(function (): void {
    Route::livewire('invitations/accept/{token}', 'pages::invitations.accept')
        ->middleware(['signed:relative', SetReferrerPolicy::class])
        ->name('invitations.accept');
});
