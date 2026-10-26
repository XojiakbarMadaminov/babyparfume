<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\PrintDocument> */
class PrintDocumentFactory extends Factory
{
    public function definition(): array
    {
        return ['profile_id'   => \App\Models\PrintProfile::factory(),
            'store_id'         => fn (array $a) => \App\Models\PrintProfile::query()->findOrFail($a['profile_id'])->store_id,
            'user_id'          => \App\Models\User::factory(), 'document_type' => 'barcode',
            'profile_snapshot' => function (array $a): array {
                $p = \App\Models\PrintProfile::query()->with('printer')->findOrFail($a['profile_id']);

                return ['id'     => $p->id, 'version' => $p->version, 'name' => $p->name, 'width_mm' => 30, 'height_mm' => 20,
                    'printer_id' => $p->printer_id, 'agent_id' => $p->printer->agent_id, 'printer_name' => $p->printer->name, 'local_key' => $p->printer->local_key];
            }, 'payload' => ['labels' => []], 'copies' => 1, 'total_labels' => 1,
            'path' => 'printing/fixture.pdf', 'sha256' => hash('sha256', 'fixture'), 'token_hash' => hash('sha256', fake()->uuid()), 'expires_at' => now()->addMinutes(10)];
    }
}
