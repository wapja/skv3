<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Tenancy\Attributes\RequiresTenant;
use Livewire\Component;

final class RequiresTenantProbe extends Component
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
}
