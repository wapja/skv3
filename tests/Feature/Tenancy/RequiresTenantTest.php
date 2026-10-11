<?php

declare(strict_types=1);

/** covers §6.2 */

use App\Models\Organisation;
use App\Tenancy\Attributes\RequiresTenant;
use Livewire\Component;
use Livewire\Livewire;

it('answers 404 for a marked action on a central host and runs it on a tenant host', function (string $host): void {
    $organisation = Organisation::factory()->create();
    $probe = new class extends Component
    {
        public int $runs = 0;

        #[RequiresTenant]
        public function run(): void
        {
            $this->runs++;
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };

    if ($host === 'tenant') {
        onTenant($organisation);
    } elseif ($host === 'apex') {
        onApex();
    } else {
        onAdminHost();
    }

    $component = Livewire::test($probe)->call('run');

    if ($host === 'tenant') {
        $component->assertSet('runs', 1);
    } else {
        $component->assertNotFound();
    }
})->with(['tenant', 'apex', 'admin']);
