<?php

declare(strict_types=1);

/** covers §4.8 */

use App\Actions\Audit\ConsoleOrigin;
use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Setup\Support\Toolchain;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Livewire;

// The console kernel dispatches CommandStarting/CommandFinished in tests only with this trait.
uses(WithConsoleEvents::class);

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([]);
    app()->instance(Toolchain::class, new Toolchain(storage_path('framework/testing/setup/home-'.Str::uuid())));
    config(['queue.default' => 'sync', 'mail.default' => 'array']);
});

it('tags every entry a command writes with the command (CD-7)', function (): void {
    User::factory()->superAdmin()->create(['email' => 'root@example.com']);

    Artisan::call('skv:create-organisation', ['name' => 'Acme', 'slug' => 'acme', 'admin-email' => 'boss@example.com', '--no-interaction' => true]);

    foreach ([ActivityEvent::OrganisationCreated, ActivityEvent::InvitationSent] as $event) {
        $entry = recordedActivity($event)->sole();

        expect($entry->getProperty('via'))->toBe('console', $event->value)
            ->and($entry->getProperty('command'))->toBe('skv:create-organisation');
    }

    expect(ConsoleOrigin::command())->toBeNull();
});

it('leaves entries outside a command untagged (CD-7)', function (): void {
    $entry = app(RecordActivity::class)->handle(ActivityEvent::OrganisationCreated, Organisation::factory()->create());

    expect($entry->fresh()?->getProperty('via'))->toBeNull();
});

it('never tags the queue workers or the scheduler itself (RL-9)', function (string $worker): void {
    ConsoleOrigin::enter($worker);

    expect(ConsoleOrigin::command())->toBeNull();
})->with(ConsoleOrigin::WORKERS);

it('keeps the outer command through a nested Artisan::call (RL-9)', function (): void {
    ConsoleOrigin::enter('skv:install');
    ConsoleOrigin::enter('migrate');
    $during = ConsoleOrigin::command();
    ConsoleOrigin::leave('migrate');
    $after = ConsoleOrigin::command();
    ConsoleOrigin::leave('skv:install');

    expect([$during, $after, ConsoleOrigin::command()])->toBe(['skv:install', 'skv:install', null]);
});

it('names command-line entries in the viewer (CD-7)', function (): void {
    $actor = User::factory()->superAdmin()->create(['name' => 'Root Admin']);
    ConsoleOrigin::enter('skv:create-organisation');
    app(RecordActivity::class)->handle(ActivityEvent::OrganisationCreated, Organisation::factory()->create(['name' => 'Cli Org']), causer: $actor);
    app(RecordActivity::class)->handle(ActivityEvent::SuperAdminGranted, $actor, defaultCauser: false);
    ConsoleOrigin::leave('skv:create-organisation');
    asSuperAdmin();
    onAdminHost();

    Livewire::test('pages::activity.index')
        ->assertSee(__(':name via the command line', ['name' => 'Root Admin']))
        ->assertSee(__('Command line'));
});
