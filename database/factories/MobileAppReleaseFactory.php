<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MobileAppRelease>
 */
class MobileAppReleaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version_name'  => '0.1.5',
            'version_code'  => fake()->unique()->numberBetween(1, 100000),
            'file_path'     => 'mobile-apk/' . fake()->uuid() . '.apk',
            'file_size'     => 45000000,
            'release_notes' => null,
            'uploaded_by'   => \App\Models\User::factory(),
        ];
    }
}
