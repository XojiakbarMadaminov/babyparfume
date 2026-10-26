<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\PrintProfile> */
class PrintProfileFactory extends Factory
{
    public function definition(): array
    {
        return ['printer_id' => \App\Models\PrintPrinter::factory(),
            'store_id'       => fn (array $a) => \App\Models\PrintPrinter::query()->findOrFail($a['printer_id'])->agent->store_id,
            'name'           => 'Test 30×20', 'document_type' => 'barcode', 'width_mm' => 30, 'height_mm' => 20,
            'layout'         => ['margin_mm' => 1, 'symbology' => 'auto', 'show_price' => false], 'is_default' => true];
    }
}
