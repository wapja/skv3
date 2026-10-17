<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\Organisation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A queued job that writes one audit entry, for the console-origin tests (CD-7, G6-02, G6-04). */
final class RecordOrganisationCreatedJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $organisationId) {}

    public function handle(RecordActivity $recordActivity): void
    {
        $recordActivity->handle(ActivityEvent::OrganisationCreated, Organisation::query()->findOrFail($this->organisationId));
    }
}
