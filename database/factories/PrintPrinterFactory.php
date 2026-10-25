<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\PrintPrinter> */
class PrintPrinterFactory extends Factory
{
    public function definition(): array
    {
        return ['agent_id' => \App\Models\PrintAgent::factory(), 'local_key' => hash('sha256', fake()->uuid()),
            'name'         => 'Test label printer', 'loaded_media' => 'barcode', 'available' => true, 'capabilities' => ['max_width_mm' => 76, 'dpi' => 203]];
    }
}
