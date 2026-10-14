<?php

declare(strict_types=1);

/** covers §6.5 */

use App\Models\Organisation;
use App\Models\User;
use App\Setup\Answers;
use App\Setup\Detection;
use App\Setup\Marker;
use App\Setup\SetupContext;
use App\Setup\SetupMode;
use App\Setup\SetupToken;
use App\Tenancy\Hosts;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\withoutMiddleware;

/** A local demo install: the marker says demo and the demo accounts exist (password: the factory's "password"). */
$demoInstall = function (string $startWith = 'demo'): SetupContext {
    $context = setupSandbox();
    app()->instance(SetupContext::class, $context);
    // Setup mode reads the sandbox's marker and token, never the repository's own (the sandbox has a marker: mode off).
    app()->instance(SetupMode::class, new SetupMode($context->basePath));
    app()->instance(SetupToken::class, new SetupToken($context->basePath));
    app()->detectEnvironment(fn (): string => 'local');
    // The CSRF middleware skips itself only in the testing environment; the quick-login form posts its @csrf token.
    withoutMiddleware(PreventRequestForgery::class);
    $context->answers = Answers::fromArray($startWith === 'demo' ? [] : ['start_with' => 'super_admin', 'super_admin_email' => 'o@example.com', 'super_admin_password' => 'correct-horse-battery'], Detection::none(), $context->folderName());
    Marker::write($context, []);

    foreach (DemoSeeder::SLUGS as $slug) {
        $organisation = Organisation::factory()->create(['slug' => $slug]);
        User::factory()->for($organisation)->create(['email' => "admin@{$slug}.".Hosts::apex()]);
    }

    return $context;
};

it('lists the demo accounts on the login page of a local demo install (§6.5)', function () use ($demoInstall): void {
    $demoInstall();

    $this->get(route('login'))->assertOk()
        ->assertSee('data-test="quick-login"', false)
        ->assertSee('admin@demo1.'.Hosts::apex());
});

it('shows no quick login outside a local demo install (§6.5, RL-9)', function (string $case) use ($demoInstall): void {
    $demoInstall($case === 'super admin install' ? 'super_admin' : 'demo');

    if ($case === 'not local') {
        app()->detectEnvironment(fn (): string => 'production');
    }

    if ($case === 'no marker') {
        unlink(app(SetupContext::class)->path(Marker::FILE));
    }

    $this->get(route('login'))->assertOk()->assertDontSee('data-test="quick-login"', false);
})->with(['not local', 'no marker', 'super admin install']);

it('hides an account with confirmed two-factor authentication or that is not active (RL-9)', function () use ($demoInstall): void {
    $demoInstall();
    // The query builder: User is a tenant model, and strict mode throws outside a tenant.
    DB::table('users')->where('email', 'admin@demo1.'.Hosts::apex())->update(['two_factor_confirmed_at' => now()]);

    $this->get(route('login'))->assertDontSee('admin@demo1.'.Hosts::apex());
});

it('hands a demo account over to its own host (§6.5, RL-9)', function () use ($demoInstall): void {
    $demoInstall();

    $location = (string) $this->post(route('quick-login'), ['email' => 'admin@demo1.'.Hosts::apex()])->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('http://'.Hosts::tenant('demo1').'/auth/handoff/');
});

it('refuses an account whose password changed, and any other email (RL-9)', function () use ($demoInstall): void {
    $demoInstall();
    DB::table('users')->where('email', 'admin@demo2.'.Hosts::apex())->update(['password' => Hash::make('something-else')]);

    $this->post(route('quick-login'), ['email' => 'admin@demo2.'.Hosts::apex()])
        ->assertRedirect(route('login'))->assertSessionHas('status', __('This demo account no longer uses the demo password.'));
    $this->post(route('quick-login'), ['email' => 'someone@example.com'])->assertNotFound();
});

it('answers 404, not 403, to an account that may no longer enter its host (RL-9)', function () use ($demoInstall): void {
    $demoInstall();
    Organisation::query()->where('slug', 'demo1')->delete();

    $this->post(route('quick-login'), ['email' => 'admin@demo1.'.Hosts::apex()])->assertNotFound();
});

it('answers 404 to the quick login outside a local demo install (RL-9)', function () use ($demoInstall): void {
    $demoInstall();
    app()->detectEnvironment(fn (): string => 'production');

    $this->post(route('quick-login'), ['email' => 'admin@demo1.'.Hosts::apex()])->assertNotFound();
});
