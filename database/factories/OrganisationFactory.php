<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Organisation> */
class OrganisationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => trim(Str::limit(Str::slug($name), 63, ''), '-'),
            'description' => fake()->optional()->sentence(),
            'require_second_factor' => false,
        ];
    }
}
