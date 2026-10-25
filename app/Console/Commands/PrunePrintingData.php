<?php

namespace App\Console\Commands;

use App\Models\PrintJob;
use App\Models\PrintDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PrunePrintingData extends Command
{
    protected $signature   = 'printing:prune';
    protected $description = 'Remove old completed print documents while preserving pending and unknown jobs';

    public function handle(): int
    {
        if (!config('printing.enabled') || !Schema::hasTable('print_documents')) {
            return self::SUCCESS;
        }
        $waiting = ['queued', 'claimed', 'rendering', 'submitting', 'unknown', 'failed_before_submission'];
        $removed = 0;
        PrintDocument::query()->where('created_at', '<', now()->subDays(7))
            ->whereDoesntHave('jobs', fn ($q) => $q->whereIn('status', $waiting))
            ->select(['id', 'path'])->chunkById(100, function ($documents) use (&$removed): void {
                foreach ($documents as $document) {
                    if (preg_match('/^printing\/[1-9][0-9]*\/[a-f0-9-]{36}\.pdf$/D', $document->path)) {
                        if (Storage::disk('local')->exists($document->path)) {
                            Storage::disk('local')->delete($document->path);
                            $removed++;
                        }
                    }
                }
            });
        PrintJob::query()->where('created_at', '<', now()->subDays(90))->whereIn('status', ['spooled', 'completed_reported', 'cancelled', 'expired'])
            ->whereDoesntHave('reprints')->chunkById(100, function ($jobs): void {
                foreach ($jobs as $job) {
                    $job->delete();
                }
            });
        PrintDocument::query()->where('created_at', '<', now()->subDays(90))->whereDoesntHave('jobs')->delete();
        $this->info($removed . ' completed print files removed.');

        return self::SUCCESS;
    }
}
