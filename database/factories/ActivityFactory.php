<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActivityEvent;
use App\Models\Activity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Activity> */
class ActivityFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $event = ActivityEvent::OrganisationUpdated;

        return [
            'log_name' => $event->group(),
            'description' => $event->value,
            'event' => $event->value,
            'properties' => [],
        ];
    }

    public function forEvent(ActivityEvent $event): static
    {
        return $this->state(fn (array $attributes): array => [
            'log_name' => $event->group(),
            'description' => $event->value,
            'event' => $event->value,
        ]);
    }
}
