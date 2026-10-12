<?php

declare(strict_types=1);

/** covers §6.7 */

use App\Models\Organisation;
use App\Support\Skv;

beforeEach(fn () => Skv::useFluxPro(false));
afterEach(fn () => Skv::useFluxPro(null));

it('renders the free variants when Flux Pro is absent', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);
    asOrgAdmin($organisation);

    $this->get(route('users.index'))->assertOk()->assertSee('data-flux-radio-group', false)->assertDontSee('data-flux-tabs', false);
    $this->get(route('users.invite'))->assertOk()->assertSee('data-flux-checkbox-group', false);
    $this->get(route('activity.index'))->assertOk()->assertSee('type="date"', false);
    $this->get(route('dashboard'))->assertOk()->assertDontSee('data-test="login-trend"', false)->assertDontSee('data-test="command-palette"', false);
});
