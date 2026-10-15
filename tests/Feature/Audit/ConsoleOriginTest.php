<?php

declare(strict_types=1);

/** covers §4.8 */

use App\Actions\Audit\ConsoleOrigin;
use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use App\Models\User;
use App\Setup\Support\Toolchain;
use App\Tenancy\Hosts;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
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

it('leaves entries a web request writes during Artisan::call untagged (CD-7)', function (): void {
    // The setup wizard runs db:seed inline in its Livewire request: ConsoleOrigin holds the command, but the
    // entry belongs to the web request.
    Route::middleware('web')->group(function (): void {
        Route::central(function (): void {
            Route::get('/_console-origin-probe', function (): string {
                ConsoleOrigin::enter('db:seed');
                app(RecordActivity::class)->handle(ActivityEvent::OrganisationCreated, Organisation::factory()->create());
                ConsoleOrigin::leave('db:seed');

                return 'ok';
            });
        });
    });

    $this->get(Hosts::url(Hosts::apex(), '/_console-origin-probe'))->assertOk();

    $entry = recordedActivity(ActivityEvent::OrganisationCreated)->sole();

    expect($entry->ip_address)->not->toBeNull()
        ->and($entry->getProperty('via'))->toBeNull()
        ->and($entry->getProperty('command'))->toBeNull();
});

// A literal list, not ConsoleOrigin::WORKERS: the dataset must not shrink with the list it checks.
it('never tags the queue workers or the scheduler itself (RL-9)', function (string $worker): void {
    ConsoleOrigin::enter($worker);

    expect(ConsoleOrigin::command())->toBeNull();
})->with(['queue:work', 'queue:listen', 'schedule:run', 'schedule:work', 'schedule:test', 'horizon', 'horizon:work', 'octane:start']);

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
