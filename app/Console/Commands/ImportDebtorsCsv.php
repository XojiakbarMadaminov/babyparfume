<?php

namespace App\Console\Commands;

use SplFileObject;
use App\Models\Store;
use App\Models\Client;
use App\Models\Debtor;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportDebtorsCsv extends Command
{
    protected $signature = 'debtors:import
        {file : Path to the CSV file}
        {--delimiter=, : CSV delimiter}
        {--no-header : Treat file as having no header row}
        {--store-id= : Default store id when not provided per row}
        {--store= : Default store name when not provided per row}
        {--update : Update existing records (client name, debtor amount, currency, note)}
    ';

    protected $description = 'Import debtors from a CSV file. Creates/updates Client (full_name, phone) and Debtor tied to a store.';

    public function handle(): int
    {
        $path         = (string) $this->argument('file');
        $delimiter    = (string) $this->option('delimiter');
        $hasHeader    = !(bool) $this->option('no-header');
        $shouldUpdate = (bool) $this->option('update');

        if (!is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $defaultStoreId = $this->resolveDefaultStoreId();

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV|SplFileObject::SKIP_EMPTY|SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($delimiter);

        $header    = [];
        $rowNumber = 0;
        $created   = 0;
        $updated   = 0;
        $skipped   = 0;

        if ($hasHeader) {
            $header = $this->readRow($file) ?? [];
            $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        }

        while (!$file->eof()) {
            $rowNumber++;
            $row = $this->readRow($file);
            if ($row === null) {
                break;
            }
            if ($row === []) {
                continue;
            }

            $data = $this->normalizeRow($row, $header, $hasHeader);

            $fullName   = trim((string) Arr::get($data, 'full_name', ''));
            $phoneRaw   = trim((string) Arr::get($data, 'phone', ''));
            $phone      = $this->normalizePhone($phoneRaw);
            $amount     = $this->toIntNullable(Arr::get($data, 'amount')) ?? 0;
            $currency   = strtoupper(trim((string) Arr::get($data, 'currency', 'UZS')));
            $note       = trim((string) Arr::get($data, 'note', '')) ?: null;
            $rawDate    = Arr::get($data, 'date', Arr::get($data, 'debtor_date'));
            $date       = $this->parseDateTimeNullable($rawDate);
            $rowStoreId = $this->resolveRowStoreId($data, $defaultStoreId);
            $debtorId   = $this->toIntNullable(Arr::get($data, 'debtor_id', Arr::get($data, 'id')));

            if ($phone === '' && $fullName === '') {
                $skipped++;
                $this->line("[skip] Row {$rowNumber}: missing both phone and full_name");
                continue;
            }

            if (!$rowStoreId) {
                $skipped++;
                $this->line("[skip] Row {$rowNumber}: store cannot be determined (pass --store-id or --store, or add store column)");
                continue;
            }

            DB::beginTransaction();

            try {
                // Create or find client by phone (unique) or fallback by name when no phone
                $client = null;
                if ($phone !== '') {
                    $client = Client::query()->firstOrCreate(['phone' => $phone]);
                } else {
                    $client = Client::query()->firstOrCreate(['full_name' => $fullName]);
                }

                if ($shouldUpdate) {
                    // Update name or phone if present
                    $dirty = false;
                    if ($fullName !== '' && $client->full_name !== $fullName) {
                        $client->full_name = $fullName;
                        $dirty             = true;
                    }
                    if ($phone !== '' && $client->phone !== $phone) {
                        $client->phone = $phone;
                        $dirty         = true;
                    }
                    if ($dirty) {
                        $client->save();
                    }
                } else {
                    // Set missing fields if empty
                    $dirty = false;
                    if ($client->full_name === null && $fullName !== '') {
                        $client->full_name = $fullName;
                        $dirty             = true;
                    }
                    if ($client->phone === null && $phone !== '') {
                        $client->phone = $phone;
                        $dirty         = true;
                    }
                    if ($dirty) {
                        $client->save();
                    }
                }

                // Resolve debtor with optional explicit ID
                $debtor = null;
                if ($debtorId) {
                    $debtor = Debtor::query()->find($debtorId);
                    if ($debtor) {
                        if ($rowStoreId && (int) $debtor->store_id !== (int) $rowStoreId) {
                            DB::rollBack();
                            $skipped++;
                            $this->line("[skip] Row {$rowNumber}: debtor #{$debtorId} belongs to another store");
                            continue;
                        }
                        if ($client && $debtor->client_id !== $client->id) {
                            DB::rollBack();
                            $skipped++;
                            $this->line("[skip] Row {$rowNumber}: debtor #{$debtorId} belongs to another client");
                            continue;
                        }
                    }
                }

                $exists = $debtor !== null;
                if (!$exists && !$debtorId) {
                    // Fallback to unique by (store, client)
                    $debtor = Debtor::query()->where('store_id', $rowStoreId)->where('client_id', $client->id)->first();
                    $exists = $debtor !== null;
                }

                if (!$exists) {
                    $attributes = [
                        'store_id'  => $rowStoreId,
                        'client_id' => $client->id,
                        'amount'    => (int) $amount,
                        'currency'  => $currency,
                        'note'      => $note,
                    ];
                    if ($date !== null) {
                        $attributes['date'] = $date;
                    }
                    if ($debtorId) {
                        $attributes['id'] = (int) $debtorId;
                    }
                    $debtor = new Debtor($attributes);
                    $debtor->save();
                    $created++;
                    $this->line("[create] Row {$rowNumber}: client='{$client->full_name}' phone='{$client->phone}' debtor=#{$debtor->id}");
                } else {
                    if ($shouldUpdate) {
                        $debtor->amount   = (int) $amount;
                        $debtor->currency = $currency;
                        $debtor->note     = $note;
                        if ($date !== null) {
                            $debtor->date = $date;
                        }
                        $debtor->save();
                        $updated++;
                        $this->line("[update] Row {$rowNumber}: debtor=#{$debtor->id}");
                    } else {
                        $skipped++;
                        $this->line("[skip] Row {$rowNumber}: debtor exists");
                    }
                }

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                $skipped++;
                $this->error("[error] Row {$rowNumber}: " . $e->getMessage());
            }
        }

        $this->info("Done. Created: {$created}, Updated: {$updated}, Skipped: {$skipped}");

        return self::SUCCESS;
    }

    protected function readRow(SplFileObject $file): ?array
    {
        if ($file->eof()) {
            return null;
        }
        $row = $file->fgetcsv();
        if ($row === false) {
            return null;
        }
        if (isset($row[0])) {
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
        }
        $row = array_map(fn ($v) => is_null($v) ? '' : trim((string) $v), $row);
        if (count(array_filter($row, fn ($v) => $v !== '')) === 0) {
            return [];
        }

        return $row;
    }

    protected function normalizeRow(array $row, array $header, bool $hasHeader): array
    {
        $data = [];
        if ($hasHeader) {
            foreach ($row as $i => $value) {
                $key        = $header[$i] ?? (string) $i;
                $data[$key] = $value;
            }
            // Normalize alternate column names
            if (array_key_exists('debtor_date', $data) && !array_key_exists('date', $data)) {
                $data['date'] = $data['debtor_date'];
            }
            if (array_key_exists('debtor_note', $data) && !array_key_exists('note', $data)) {
                $data['note'] = $data['debtor_note'];
            }
        } else {
            // Positional: 0=full_name, 1=phone, 2=amount, 3=currency, 4=note, 5=store or store_id
            $data['full_name'] = $row[0] ?? '';
            $data['phone']     = $row[1] ?? '';
            $data['amount']    = $row[2] ?? '';
            $data['currency']  = $row[3] ?? '';
            $data['note']      = $row[4] ?? '';
            $data['store']     = $row[5] ?? '';
            $data['id']        = $row[6] ?? '';
            $data['date']      = $row[7] ?? '';
        }

        return $data;
    }

    protected function parseDateTimeNullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            // Use start of day if time is not supplied
            $dt = Carbon::parse($raw);
            if (
                str_contains($raw, ':') === false &&
                preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $raw)
            ) {
                $dt = $dt->startOfDay();
            }

            return $dt->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function toIntNullable(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $normalized = preg_replace('/[^0-9\-]/', '', $value);
        if ($normalized === '' || $normalized === '-') {
            return null;
        }

        return (int) $normalized;
    }

    protected function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        // Keep leading + and digits; otherwise strip non-digits
        $digits = preg_replace('/[^0-9\+]/', '', $phone);
        // If multiple +, keep only first
        if (Str::startsWith($digits, '++')) {
            $digits = '+' . ltrim($digits, '+');
        }

        return $digits;
    }

    protected function resolveDefaultStoreId(): ?int
    {
        $storeIdOpt = $this->option('store-id');
        if ($storeIdOpt) {
            $store = Store::query()->find((int) $storeIdOpt);
            if (!$store) {
                $this->error("Store not found by id: {$storeIdOpt}");

                return null;
            }

            return (int) $store->id;
        }
        $storeNameOpt = $this->option('store');
        if ($storeNameOpt) {
            $store = Store::query()->where('name', $storeNameOpt)->first();
            if (!$store) {
                $this->error("Store not found by name: {$storeNameOpt}");

                return null;
            }

            return (int) $store->id;
        }
        $stores = Store::query()->get(['id']);
        if ($stores->count() === 1) {
            return (int) $stores->first()->id;
        }

        // No default resolvable
        return null;
    }

    protected function resolveRowStoreId(array $data, ?int $defaultStoreId): ?int
    {
        $storeId = Arr::get($data, 'store_id');
        if ($storeId) {
            return (int) $storeId;
        }
        $storeName = Arr::get($data, 'store');
        if ($storeName) {
            $store = Store::query()->where('name', $storeName)->first();
            if ($store) {
                return (int) $store->id;
            }
        }

        return $defaultStoreId;
    }
}
