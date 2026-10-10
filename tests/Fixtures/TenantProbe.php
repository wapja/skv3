<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Livewire\Component;

final class TenantProbe extends Component
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function render(): string
    {
        return '<div>{{ $count }}</div>';
    }
}
