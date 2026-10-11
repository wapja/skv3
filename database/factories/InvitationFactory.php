<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An invitation with its pending user. Real flows go through InviteUser; the factory serves list
 * and state tests.
 *
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = Str::random(64);

        return [
            'organisation_id' => Organisation::factory(),
            'user_id' => fn (array $attributes): int => User::factory()->pending()->create(['organisation_id' => $attributes['organisation_id']])->id,
            'invited_by' => null,
            'token_hash' => hash('sha256', $token),
            'token' => $token,
            'expires_at' => now()->addDays(7),
            'reminder_sent_at' => null,
            'accepted_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'accepted_at' => now(),
            'token' => null,
        ]);
    }
}
