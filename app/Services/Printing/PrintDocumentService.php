<?php

namespace App\Services\Printing;

use App\Models\Sale;
use App\Models\User;
use App\Models\Store;
use Illuminate\Support\Str;
use App\Models\PrintProfile;
use App\Support\ReceiptData;
use App\Models\PrintDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PrintDocumentService
{
    public function profile(User $user, Store $store, array $data, bool $test = false): PrintProfile
    {
        $type = $data['document_type'];
        PrintAccess::authorize($user, $store->id, $test ? 'test_printing' : ($type === 'receipt' ? 'print_receipts' : 'print_barcodes'));
        $profile = PrintProfile::query()->where('store_id', $store->id)->where('active', true)
            ->where('document_type', $type)->with('printer.agent')->findOrFail($data['paper_profile_id']);
        abort_unless($profile->selectable || ($data['trigger'] ?? null) === 'automatic', 403);
        abort_if($profile->version !== (int) $data['paper_profile_version'], 409, 'profile_stale');
        abort_unless($profile->printer && !$profile->printer->agent->revoked_at
            && $profile->printer->agent->store_id === $store->id, 422, 'Profilga faol kompyuter va printer biriktiring.');
        $maxWidth = $profile->printer->capabilities['max_width_mm'] ?? null;
        abort_if($maxWidth && (float) $profile->width_mm > (float) $maxWidth, 422, 'Qog‘oz eni printerga mos emas.');

        return $profile;
    }

    public function create(User $user, Store $store, ?int $deviceId, array $data, bool $isCopy = false): array
    {
        $profile = $this->profile($user, $store, $data);
        $copies  = $profile->document_type === 'receipt' ? (int) ($data['copies'] ?? 1) : 1;
        abort_if($copies < 1 || $copies > $profile->max_copies, 422, 'Nusxalar soni ruxsat etilgan chegaradan tashqarida.');
        $sourceKey   = null;
        $totalLabels = 0;
        if ($profile->document_type === 'barcode') {
            $payload = ['store_name' => $store->name, 'labels' => []];
            foreach ($data['items'] as $item) {
                $product = app(\App\Services\FlutterSyncService::class)->productsQuery($store->id)->findOrFail($item['product_id']);
                $code    = (string) $product->barcode;
                $format  = $profile->layout['symbology'] ?? 'auto';
                $ean     = preg_match('/^\d{13}$/D', $code) && $this->validEan($code);
                if ($format === 'auto') {
                    $format = $ean ? 'EAN13' : 'C128';
                }
                if (blank($code) || strlen($code) > 24 || ($format === 'EAN13' && !$ean) || !preg_match('/^[\x20-\x7E]+$/D', $code)) {
                    throw ValidationException::withMessages(['items' => $product->name . ': shtrix-kod formatga mos emas.']);
                }
                $count = (int) $item['copies'];
                abort_if($count < 1 || $count > $profile->max_copies, 422, 'Etiketka nusxalari chegaradan oshgan.');
                $totalLabels += $count;
                abort_if($totalLabels > config('printing.max_labels'), 422, 'Umumiy etiketka soni juda katta.');
                $svg = $this->barcodeSvg($code, $format);
                $row = ['name' => $product->name, 'barcode' => $code, 'price' => (string) $product->price,
                    'svg'      => 'data:image/svg+xml;base64,' . base64_encode($svg)];
                for ($index = 0; $index < $count; $index++) {
                    $payload['labels'][] = $row;
                }
            }
        } else {
            $source = $data['source'];
            if ($source['kind'] === 'local_sale') {
                $operation = \App\Models\FlutterSyncOperation::query()->where('operation_id', $source['operation_id'])->first();
                if ($operation) {
                    abort_unless($operation->store_id === $store->id && $operation->user_id === $user->id
                        && $operation->device_id === $deviceId && $operation->entity_type === 'sale'
                        && ($operation->payload['local_sale_id'] ?? null) === $source['local_sale_id'], 403);
                    abort_if(in_array($operation->status, ['rejected', 'conflict', 'blocked'], true), 422, 'Savdoni administrator tekshirishi kerak.');
                    if ($operation->status === 'applied') {
                        $source = ['kind' => 'server_sale', 'sale_id' => $operation->entity_id];
                    }
                }
            }
            if ($source['kind'] === 'server_sale') {
                $sale                    = Sale::withoutGlobalScope('current_store')->where('store_id', $store->id)->with(['client', 'createdBy'])->findOrFail($source['sale_id']);
                $payload                 = ReceiptData::fromSale($sale, ['client_name' => $sale->client?->full_name, 'cashier_name' => $sale->createdBy?->name]);
                $payload['status_label'] = $sale->isRejected() ? 'RAD ETILGAN' : ($sale->isPending() ? 'Server tasdig‘i kutilmoqda' : 'Server tasdiqlagan');
                $sourceKey               = $sale->flutter_local_sale_id ? 'local:' . $sale->flutter_local_sale_id : 'sale:' . $sale->id;
                $meta                    = $sale->flutter_receipt_meta;
                if ($meta) {
                    $payload['items'] = $meta['items'];
                    $payload['meta']  = [...$payload['meta'], ...collect($meta)->except('items')->all()];
                } else {
                    $payload['meta']['cash'] = $sale->mixed_cash_amount;
                    $payload['meta']['card'] = $sale->mixed_card_amount;
                }
                $payload['date'] = $sale->created_at?->timezone('Asia/Tashkent')->format('d.m.Y H:i:s') ?? $payload['date'];
                if ($sale->isRejected()) {
                    PrintAccess::authorize($user, $store->id, 'reprint_receipts');
                    $isCopy = true;
                }
            } else {
                $snapshot = $source['snapshot'];
                $amount   = 0;
                foreach ($snapshot['items'] as $item) {
                    $line = (float) $item['qty'] * (float) $item['price'];
                    abort_if((float) ($item['discount'] ?? 0) > $line, 422, 'Chegirma summadan katta.');
                    $amount += $line - (float) ($item['discount'] ?? 0);
                }
                $total = (float) $snapshot['total_amount'];
                abort_if(
                    abs(round($amount - (float) ($snapshot['receipt_discount'] ?? 0), 2) - $total) > 0.011
                    || abs((float) $snapshot['paid_amount'] + (float) $snapshot['remaining_amount'] - $total) > 0.011,
                    422,
                    'Chek summalari mos emas.'
                );
                $payload = ['store_name' => $store->name, 'receipt_number' => $source['local_sale_id'],
                    'date'               => \Illuminate\Support\Carbon::parse($snapshot['date'])->timezone('Asia/Tashkent')->format('d.m.Y H:i:s'), 'items' => $snapshot['items'],
                    'totals'             => ['amount' => $snapshot['total_amount'], 'qty' => array_sum(array_column($snapshot['items'], 'qty'))],
                    'meta'               => ['cashier_name' => $user->name, 'client_name' => $snapshot['client_name'] ?? null,
                        'payment_type'                      => $snapshot['payment_type'], 'paid_amount' => $snapshot['paid_amount'],
                        'remaining_amount'                  => $snapshot['remaining_amount'], 'receipt_discount' => $snapshot['receipt_discount'] ?? 0,
                        'cash'                              => $snapshot['cash'] ?? null, 'card' => $snapshot['card'] ?? null,
                        'received_cash'                     => $snapshot['received_cash'] ?? null, 'change' => $snapshot['change'] ?? null],
                    'status_label' => 'Server tasdig‘i kutilmoqda'];
                $sourceKey = 'local:' . $source['local_sale_id'];
            }
            $payload['is_copy'] = $isCopy;
        }

        return $this->persist($user, $store, $deviceId, $profile, $payload, $copies, $totalLabels, $sourceKey);
    }

    public function test(User $user, Store $store, string $profileId): array
    {
        $p       = PrintProfile::query()->where('store_id', $store->id)->findOrFail($profileId);
        $profile = $this->profile($user, $store, ['document_type' => $p->document_type,
            'paper_profile_id'                                    => $p->id, 'paper_profile_version' => $p->version, 'trigger' => 'automatic'], true);
        $payload = $p->document_type === 'barcode'
            ? ['is_test' => true, 'store_name' => 'OSONPOS TEST', 'labels' => [['name' => 'TEST — Ўzbek / O‘zbek',
                'barcode'                                                              => '0012345678905', 'price' => '0', 'svg' => 'data:image/svg+xml;base64,' . base64_encode($this->barcodeSvg('0012345678905', 'EAN13'))]]]
            : ['is_test'       => true, 'store_name' => 'OSONPOS — TEST', 'receipt_number' => 'TEST', 'date' => now()->toIso8601String(),
                'status_label' => 'SINOV HUJJATI', 'items' => [['name' => 'Test mahsulot / Ўзбек', 'qty' => 1, 'price' => '0']],
                'totals'       => ['amount' => '0', 'qty' => 1], 'meta' => ['payment_type' => 'cash', 'paid_amount' => '0', 'remaining_amount' => '0']];

        return $this->persist($user, $store, null, $profile, $payload, 1, $p->document_type === 'barcode' ? 1 : 0, 'test:' . Str::uuid());
    }

    public function copy(User $user, Store $store, ?int $deviceId, PrintDocument $original): array
    {
        $current = PrintProfile::query()->where('store_id', $store->id)->findOrFail($original->profile_id);
        $profile = $this->profile($user, $store, ['document_type' => $original->document_type,
            'paper_profile_id'                                    => $original->profile_id, 'paper_profile_version' => $current->version]);
        abort_if($original->copies > $profile->max_copies, 422, 'Yangi profil nusxa limitiga moslab qayta tayyorlang.');
        $payload = $original->payload;
        if ($original->document_type === 'barcode') {
            $counts = array_count_values(array_column($payload['labels'], 'barcode'));
            abort_if(max($counts ?: [0]) > $profile->max_copies, 422, 'Yangi profil etiketka limitiga moslab qayta tayyorlang.');
        }
        $payload['is_copy'] = true;

        return $this->persist($user, $store, $deviceId, $profile, $payload, $original->copies, $original->total_labels, $original->source_key);
    }

    private function persist(User $user, Store $store, ?int $deviceId, PrintProfile $profile, array $payload, int $copies, int $totalLabels, ?string $sourceKey): array
    {
        $snapshot = ['id'   => $profile->id, 'version' => $profile->version, 'name' => $profile->name,
            'document_type' => $profile->document_type, 'width_mm' => (float) $profile->width_mm,
            'height_mm'     => $profile->height_mm === null ? null : (float) $profile->height_mm,
            'layout'        => $profile->layout, 'agent_id' => $profile->printer->agent_id,
            'printer_id'    => $profile->printer_id, 'printer_name' => $profile->printer->name,
            'local_key'     => $profile->printer->local_key];
        $height = $profile->height_mm === null ? min(2500, max(160, 105 + count($payload['items'] ?? []) * 13)) : (float) $profile->height_mm;
        \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('fonts'));
        $renderer = Pdf::loadView('printing.' . $profile->document_type, ['payload' => $payload, 'profile' => $snapshot])
            ->setOptions(['isRemoteEnabled' => false, 'defaultFont' => 'OsonPrint'], true)
            ->setPaper([0, 0, (float) $profile->width_mm * 72 / 25.4, $height * 72 / 25.4]);
        $pdf = $renderer->output();
        abort_if($profile->document_type === 'barcode' && $renderer->getDomPDF()->getCanvas()->get_page_count() !== $totalLabels, 422, 'Etiketka o‘lchamiga ma’lumotlar sig‘madi. Profilni moslang.');
        abort_if(strlen($pdf) > config('printing.max_document_bytes'), 422, 'Hujjat hajmi juda katta.');
        $id    = (string) Str::uuid();
        $path  = 'printing/' . $store->id . '/' . $id . '.pdf';
        $token = Str::random(64);
        abort_unless(Storage::disk('local')->put($path, $pdf), 500);

        try {
            $document = PrintDocument::query()->create(['id' => $id, 'store_id' => $store->id,
                'user_id'                                    => $user->id, 'device_id' => $deviceId, 'profile_id' => $profile->id,
                'profile_snapshot'                           => $snapshot, 'payload' => $payload, 'document_type' => $profile->document_type,
                'source_key'                                 => $sourceKey, 'copies' => $copies, 'total_labels' => $totalLabels,
                'path'                                       => $path, 'sha256' => hash('sha256', $pdf), 'token_hash' => hash('sha256', $token),
                'expires_at'                                 => now()->addMinutes(10)]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);

            throw $error;
        }

        return ['document' => $document, 'document_token' => $token];
    }

    private function barcodeSvg(string $code, string $format): string
    {
        $xml = new \DOMDocument;
        $xml->loadXML(\DNS1D::getBarcodeSVG($code, $format, 1, 32, 'black', false), LIBXML_NONET);
        $root   = $xml->documentElement;
        $width  = (float) $root->getAttribute('width');
        $height = (float) $root->getAttribute('height');
        $left   = $format === 'EAN13' ? 11 : 10;
        $right  = $format === 'EAN13' ? 7 : 10;
        $total  = $width + $left + $right;
        $root->setAttribute('viewBox', '-' . $left . ' 0 ' . $total . ' ' . $height);
        $root->setAttribute('width', (string) $total);
        $root->setAttribute('preserveAspectRatio', 'none');

        return $xml->saveXML();
    }

    private function validEan(string $code): bool
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $code[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10 === (int) $code[12];
    }
}
