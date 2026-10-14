<?php

declare(strict_types=1);

use App\Setup\Http\EnsureSetupAccess;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** §5.3.4: the browser wizard. Task 10 adds the questions and the steps. */
new #[Layout('layouts::setup')] #[Title('Setup')] class extends Component
{
    public function mount(): void
    {
        abort_unless(EnsureSetupAccess::authorised(request()), 404);
    }
};
