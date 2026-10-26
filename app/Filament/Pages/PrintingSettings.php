<?php

namespace App\Filament\Pages;

use App\Models\Store;
use App\Models\PrintJob;
use Filament\Pages\Page;
use App\Models\PrintAgent;
use Illuminate\Support\Str;
use App\Models\PrintProfile;
use App\Enums\NavigationGroup;
use App\Models\GeneralSetting;
use App\Models\PrintStoreSetting;
use App\Services\Printing\PrintAccess;
use Filament\Notifications\Notification;
use App\Services\Printing\PrintJobService;
use App\Services\Printing\PrintAgentService;
use Illuminate\Validation\ValidationException;
use App\Services\Printing\PrintDocumentService;

class PrintingSettings extends Page
{
    protected static string|null|\UnitEnum $navigationGroup  = NavigationGroup::Settings;
    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-printer';
    protected static ?string $navigationLabel                = 'Chop etish';
    protected static ?string $title                          = 'Chop etish sozlamalari';
    protected string $view                                   = 'filament.pages.printing-settings';
    public array $profiles                                   = [];
    public array $printers                                   = [];
    public array $media                                      = [];
    public array $disconnectedProfiles                       = [];
    public bool $autoReceipt                                 = false;
    public ?string $pairingCode                              = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && PrintAccess::allows($user, (int) $user->current_store_id, 'manage_printing');
    }

    private function store(): Store
    {
        $user = auth()->user();
        PrintAccess::authorize($user, (int) $user->current_store_id, 'manage_printing');

        return $user->stores()->findOrFail($user->current_store_id);
    }

    public function mount(): void
    {
        $store = $this->store();
        $store->getConnection()->transaction(function () use ($store): void {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            if (!PrintProfile::query()->where('store_id', $store->id)->exists()) {
                foreach ([['Chek 58 mm', 'receipt', 58, null], ['Chek 80 mm', 'receipt', 80, null], ['Barcode 30×20', 'barcode', 30, 20], ['Barcode 57×30', 'barcode', 57, 30]] as $i => [$name,$type,$width,$height]) {
                    PrintProfile::query()->create(['store_id' => $store->id, 'name' => $name, 'document_type' => $type,
                        'width_mm'                            => $width, 'height_mm' => $height, 'is_default' => in_array($i, [1, 2]),
                        'layout'                              => ['margin_mm' => 1, 'symbology' => 'auto', 'show_price' => (bool) GeneralSetting::query()->value('barcode_show_price')]]);
                }
            }
        });
        $this->reload();
    }

    public function reload(): void
    {
        $store                      = $this->store();
        $agent                      = PrintAgent::query()->where('active_store_id', $store->id)->first();
        $this->printers             = $agent?->printers()->orderBy('name')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'available' => $p->available])->all() ?? [];
        $printerIds                 = array_column($this->printers, 'id');
        $this->disconnectedProfiles = [];
        $this->profiles             = PrintProfile::query()->where('store_id', $store->id)->orderBy('created_at')->orderBy('id')->get()->map(function (PrintProfile $p) use ($printerIds): array {
            $printerId = $p->printer_id;
            if ($printerId && !in_array($printerId, $printerIds, true)) {
                $this->disconnectedProfiles[] = $p->name;
                $printerId                    = null;
            }

            return [
                'id'             => $p->id, 'name' => $p->name, 'document_type' => $p->document_type, 'width_mm' => $p->width_mm, 'height_mm' => $p->height_mm,
                'printer_id'     => $printerId, 'active' => $p->active, 'selectable' => $p->selectable, 'is_default' => $p->is_default,
                'default_copies' => $p->default_copies, 'max_copies' => $p->max_copies, 'margin_mm' => $p->layout['margin_mm'] ?? 1,
                'show_price'     => $p->layout['show_price'] ?? false, 'symbology' => $p->layout['symbology'] ?? 'auto'];
        })->all();
        $this->media       = $agent?->printers()->pluck('loaded_media', 'id')->all() ?? [];
        $this->autoReceipt = (bool) PrintStoreSetting::query()->where('store_id', $store->id)->value('auto_print_receipt');
    }

    public function addProfile(): void
    {
        $this->store();
        $this->profiles[] = ['id' => (string) Str::uuid(), 'name' => 'Yangi profil', 'document_type' => 'barcode', 'width_mm' => 30,
            'height_mm'           => 20, 'printer_id' => null, 'active' => true, 'selectable' => true, 'is_default' => false,
            'default_copies'      => 1, 'max_copies' => 100, 'margin_mm' => 1, 'show_price' => false, 'symbology' => 'auto'];
    }

    public function save(): void
    {
        $store = $this->store();
        $data  = $this->validate(['profiles' => ['required', 'array', 'min:1', 'max:50'],
            'profiles.*.id'                  => ['required', 'uuid', 'distinct'], 'profiles.*.name' => ['required', 'string', 'max:100'],
            'profiles.*.document_type'       => ['required', 'in:receipt,barcode'], 'profiles.*.width_mm' => ['required', 'numeric', 'min:15', 'max:300'],
            'profiles.*.height_mm'           => ['nullable', 'numeric', 'min:10', 'max:2500'], 'profiles.*.printer_id' => ['nullable', 'uuid'],
            'profiles.*.active'              => ['required', 'boolean'], 'profiles.*.selectable' => ['required', 'boolean'],
            'profiles.*.is_default'          => ['required', 'boolean'], 'profiles.*.default_copies' => ['required', 'integer', 'min:1', 'max:1000'],
            'profiles.*.max_copies'          => ['required', 'integer', 'min:1', 'max:1000'], 'profiles.*.margin_mm' => ['required', 'numeric', 'min:0', 'max:5'],
            'profiles.*.show_price'          => ['required', 'boolean'], 'profiles.*.symbology' => ['required', 'in:auto,EAN13,C128'],
            'media'                          => ['array'], 'media.*' => ['required', 'in:receipt,barcode,unknown'], 'autoReceipt' => ['boolean']]);
        $store->getConnection()->transaction(function () use ($store, $data): void {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $agent    = PrintAgent::query()->where('active_store_id', $store->id)->first();
            $defaults = [];
            foreach ($data['profiles'] as $index => $row) {
                if ($row['document_type'] === 'barcode' && empty($row['height_mm'])) {
                    throw ValidationException::withMessages(['profiles' => 'Etiketka balandligini kiriting.']);
                }
                if (($row['document_type'] === 'receipt' && $row['max_copies'] > 100) || $row['default_copies'] > $row['max_copies'] || min($row['width_mm'], $row['height_mm'] ?: $row['width_mm']) <= $row['margin_mm'] * 2) {
                    throw ValidationException::withMessages(['profiles' => 'Nusxa limiti yoki margin yaroqsiz.']);
                }
                if ($row['active'] && $row['is_default']) {
                    if (isset($defaults[$row['document_type']])) {
                        throw ValidationException::withMessages(['profiles' => 'Har bir hujjat turida bitta standart profil bo‘lsin.']);
                    }
                    $defaults[$row['document_type']] = $row;
                }
                if ($row['printer_id']) {
                    if (!$agent?->printers()->whereKey($row['printer_id'])->exists()) {
                        throw ValidationException::withMessages(["profiles.{$index}.printer_id" => $row['name'] . ': avvalgi printer joriy kompyuterga tegishli emas. Printerni qayta tanlab saqlang.']);
                    }
                }
                $profile = PrintProfile::query()->find($row['id']);
                abort_if($profile && $profile->store_id !== $store->id, 403);
                $profile ??= new PrintProfile(['id' => $row['id'], 'store_id' => $store->id]);
                $layout = ['margin_mm' => (float) $row['margin_mm'], 'show_price' => (bool) $row['show_price'], 'symbology' => $row['symbology']];
                $profile->fill(collect($row)->only(['name', 'document_type', 'width_mm', 'height_mm', 'printer_id', 'active', 'selectable', 'is_default', 'default_copies', 'max_copies'])->all());
                $profile->layout = $layout;
                if ($profile->exists && $profile->isDirty()) {
                    $profile->version++;
                }
                $profile->save();
            }
            if ($this->autoReceipt && empty($defaults['receipt']['printer_id'])) {
                throw ValidationException::withMessages(['autoReceipt' => 'Avval standart chek profiliga printerni biriktiring.']);
            }
            foreach ($data['media'] as $id => $kind) {
                $printer = $agent?->printers()->find($id);
                if (!$printer) {
                    throw ValidationException::withMessages(['media' => 'Printerlar ro‘yxati o‘zgargan. “Yangilash”ni bosib, printerlarni qayta tanlang.']);
                }
                $printer->update(['loaded_media' => $kind]);
            }
            PrintStoreSetting::query()->updateOrCreate(['store_id' => $store->id], ['auto_print_receipt' => $this->autoReceipt]);
        });
        $this->reload();
        Notification::make()->title('Chop etish sozlamalari saqlandi')->success()->send();
    }

    public function testProfile(string $profileId): void
    {
        $store = $this->store();
        $made  = app(PrintDocumentService::class)->test(auth()->user(), $store, $profileId);
        app(PrintJobService::class)->create(auth()->user(), $store, null, [
            'client_job_id' => (string) Str::uuid(), 'document_token' => $made['document_token'],
            'requested_at'  => now()->toIso8601String(),
        ]);
        Notification::make()->title('Test hujjati navbatga qo‘yildi')->success()->send();
    }

    public function issueCode(): void
    {
        $this->pairingCode = app(PrintAgentService::class)->issueCode(auth()->user(), $this->store());
    }

    public function revoke(): void
    {
        $store = $this->store();
        $agent = PrintAgent::query()->where('active_store_id', $store->id)->firstOrFail();
        app(PrintAgentService::class)->revoke(auth()->user(), $store, $agent->id);
        $this->pairingCode = null;
        $this->reload();
    }

    protected function getViewData(): array
    {
        $store = $this->store();
        $agent = PrintAgent::query()->where('active_store_id', $store->id)->first();

        return ['agent' => $agent, 'online' => $agent?->last_seen_at?->gt(now()->subMinute()) ?? false,
            'jobs'      => PrintJob::query()->where('store_id', $store->id)->with('document')->latest()->limit(20)->get()];
    }
}
