<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixDebtorsSequence extends Command
{
    protected $signature = 'db:fix-debtors-sequence';

    protected $description = 'Fix PostgreSQL debtors id sequence';

    public function handle(): int
    {
        DB::statement("
            SELECT setval(
                pg_get_serial_sequence('debtors', 'id'),
                (SELECT COALESCE(MAX(id), 1) FROM debtors)
            )
        ");

        $this->info('Debtors sequence fixed successfully.');

        return Command::SUCCESS;
    }
}
