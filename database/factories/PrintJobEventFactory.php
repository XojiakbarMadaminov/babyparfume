<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\PrintJobEvent> */
class PrintJobEventFactory extends Factory
{
    public function definition(): array
    {
        return ['job_id' => \App\Models\PrintJob::factory(), 'event_id' => fake()->uuid(), 'status' => 'queued', 'data' => []];
    }
}
