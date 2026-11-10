<?php

namespace Database\Factories;

use Illuminate\Support\Str;
use App\Models\PushAnnouncement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PushAnnouncement> */
class PushAnnouncementFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'request_id'  => (string) Str::uuid(),
            'sender_name' => fake()->name(),
            'title'       => fake()->sentence(4),
            'body'        => fake()->sentence(),
        ];
    }
}
