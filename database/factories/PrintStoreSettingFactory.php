<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\PrintStoreSetting> */
class PrintStoreSettingFactory extends Factory
{
    public function definition(): array
    {
        return ['store_id' => fn () => \App\Models\Store::query()->create(['name' => 'Print test', 'address' => 'Test', 'phone' => '+998901234567'])->id, 'auto_print_receipt' => false];
    }
}
