<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| Administration pages (§4.3–§4.8). Shared routes keep one name and answer on every host; on central
| hosts only super admins pass (VerifyTenantMembership). Single-host pages are grouped with
| Route::tenant() / Route::central(), never Route::domain().
*/

Route::middleware(['auth'])->group(function (): void {
    // Admin pages are added here, one task at a time (users, invitations, organisations, roles, activity).
});

// Signed routes that guests open (invitations.accept, Task 11) go below, outside the auth group.
