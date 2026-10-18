<?php

declare(strict_types=1);

/** covers §6.5 */

use App\Authorization\Role;
use App\Authorization\SetSuperAdmin;
use App\Models\User;

it('shows the super admin badge in the role\'s colour on the users list and on the user\'s page (§6.5, CD-13)', function (): void {
    $root = User::factory()->superAdmin()->create(['name' => 'Rita Root']);
    app(SetSuperAdmin::class)->handle($root, true);
    asSuperAdmin();
    onAdminHost();

    // Flux's violet badge; Role::color() decides it, so a changed colour fails here as well as in the unit test.
    $violetBadge = '/<[^>]*\bbg-violet-400\/20\s[^>]*>\s*'.preg_quote(Role::SuperAdmin->label(), '/').'\s*</';

    expect((string) $this->get(route('users.index'))->assertOk()->getContent())->toMatch($violetBadge)
        ->and((string) $this->get(route('users.edit', $root->id))->assertOk()->getContent())->toMatch($violetBadge);
});
