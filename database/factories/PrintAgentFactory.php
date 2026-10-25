<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\PrintAgent> */
class PrintAgentFactory extends Factory
{
    public function definition(): array
    {
        return ['store_id'    => fn () => \App\Models\Store::query()->create(['name' => fake()->company(), 'address' => 'Test', 'phone' => '+998901234567'])->id,
            'active_store_id' => fn (array $a) => $a['store_id'], 'name' => 'Test PC', 'token_hash' => hash('sha256', fake()->uuid()), 'last_seen_at' => now()];
    }
}
