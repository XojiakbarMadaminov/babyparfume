<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\PrintJob> */
class PrintJobFactory extends Factory
{
    public function definition(): array
    {
        return ['document_id' => \App\Models\PrintDocument::factory(), 'client_job_id' => fake()->uuid(),
            'store_id'        => fn (array $a) => \App\Models\PrintDocument::query()->findOrFail($a['document_id'])->store_id,
            'user_id'         => fn (array $a) => \App\Models\PrintDocument::query()->findOrFail($a['document_id'])->user_id,
            'agent_id'        => fn (array $a) => \App\Models\PrintDocument::query()->findOrFail($a['document_id'])->profile_snapshot['agent_id'],
            'printer_id'      => fn (array $a) => \App\Models\PrintDocument::query()->findOrFail($a['document_id'])->profile_snapshot['printer_id'],
            'payload_hash'    => hash('sha256', fake()->uuid()), 'status' => 'queued', 'auto_submit_until' => now()->addMinutes(10)];
    }
}
