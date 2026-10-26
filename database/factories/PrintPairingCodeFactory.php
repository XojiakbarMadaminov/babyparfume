<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\PrintPairingCode> */
class PrintPairingCodeFactory extends Factory
{
    public function definition(): array
    {
        return ['store_id' => fn () => \App\Models\Store::query()->create(['name' => 'Pairing test', 'address' => 'Test', 'phone' => '+998901234567'])->id,
            'user_id'      => \App\Models\User::factory(), 'code_hash' => hash('sha256', fake()->uuid()), 'expires_at' => now()->addMinutes(10)];
    }
}
