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

it('signs a demo account in with one click on the login page (§6.5, RL-9)', function (): void {
    $context = setupSandbox();
    app()->instance(SetupContext::class, $context);
    // Setup mode reads the sandbox's marker and token, never the repository's own (the sandbox has a marker: mode off).
    app()->instance(SetupMode::class, new SetupMode($context->basePath));
    app()->instance(SetupToken::class, new SetupToken($context->basePath));
    app()->detectEnvironment(fn (): string => 'local');
    $context->answers = Answers::fromArray([], Detection::none(), $context->folderName());
    Marker::write($context, []);
    User::factory()->for(Organisation::factory()->create(['slug' => 'demo1']))->create(['email' => 'admin@demo1.localhost']);

    $page = visit('/login')->withHost('localhost')
        ->assertSee(__('Quick login (local only)'))
        // GuessLocator strips every '@', so the selector holds none after the first.
        ->press('@quick-login-admin-demo1-localhost')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();

    expect(parse_url($page->url(), PHP_URL_HOST))->toBe('demo1.localhost');
});
